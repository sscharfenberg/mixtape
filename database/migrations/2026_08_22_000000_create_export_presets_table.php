<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `export_presets` — a named bundle of the three .m3u export options, owned by a user.
     *
     * WHY A TABLE AND NOT A CONFIG VALUE. The export's path prefix says where the files will
     * be from the point of view of whatever plays them — a Mac's /Volumes mount, a phone's
     * internal storage, a car's USB stick where the music sits at the root. That is a fact
     * about a PERSON'S DEVICES, which the server cannot know and one instance-wide setting
     * cannot hold. `mixtape.playlists.export.path_prefix` remains as the seed for a reader who
     * has defined no preset of their own.
     *
     * ALL THREE OPTIONS TRAVEL TOGETHER, and that is what makes a preset one object rather
     * than three settings: the device decides all three. A car head unit wants a simple list
     * in Windows-1252 with no prefix; a phone wants an extended list in UTF-8 under
     * /storage/emulated/0/Music. Split apart, the reader would recombine them by hand on every
     * export, which is the retyping this exists to remove.
     *
     * `path_prefix` IS NOT NULLABLE AND EMPTY IS A REAL VALUE — the car case above, where the
     * playlist sits beside the music and the paths are relative. There is nothing a null could
     * mean here that '' does not.
     *
     * The name is unique per owner and carries the sorted-column collation, for the two
     * reasons recorded below.
     */
    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::create('export_presets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Ownership cascades: delete a user → delete their presets. True ownership, as on
            // `playlists` and `shares`.
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // 60 rather than the 255 a playlist name gets: this renders inside a dropdown
            // inside the export modal, where a long name has nowhere to go.
            $table->string('name', 60);

            // Validated against App\Services\Playlists\PlaylistExport::FORMATS / ::ENCODINGS —
            // the same constants the export request draws its `in:` rules from, so a preset
            // cannot hold a shape the renderer has no branch for. Stored as the strings
            // themselves rather than as a key into a list, so a row reads as what it does.
            $table->string('format', 16);
            $table->string('encoding', 16);

            $table->string('path_prefix', 255)->default('');

            // Which preset the export modal opens on. Exactly one per user, enforced below on
            // production and by the writes everywhere.
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            // "your MacBook ≠ my MacBook". This composite also serves every "a user's presets"
            // lookup, the `hasExportPresets` existence check and the user-delete cascade check,
            // so `user_id` needs no standalone index (data-model.md → "Indexes").
            $table->unique(['user_id', 'name']);
        });

        if ($pgsql) {
            // AT MOST ONE DEFAULT PER USER. A partial unique index rather than a CHECK,
            // because the rule spans rows: it is the second `is_default` row for one user that
            // is wrong, not any row on its own.
            //
            // It exists because two defaults FAIL SILENTLY — the export modal preselects
            // whichever row the sort happened to return first, so the reader gets a plausible
            // preset that is not the one they chose, and nothing errors anywhere. The writes
            // clear the flag before setting it (ExportPresetDefault), which is the ordering
            // this index requires: Postgres checks a unique index per statement, so setting
            // before clearing would collide with the row being replaced.
            //
            // sqlite (the test connection) skips it, exactly as the `shares` and `collections`
            // CHECKs do: the constraint is a production guarantee and the suite proves the app
            // never tries to violate it.
            DB::statement(
                'CREATE UNIQUE INDEX export_presets_one_default_uq ON export_presets (user_id) WHERE is_default'
            );

            // The sorted-column collation, for the reason
            // 2026_08_15_000001_pin_the_sort_collation records: a name column that takes the
            // DATABASE default sorts differently on two boxes created with different locales,
            // and the presets list is ordered by this column. That migration cannot cover a
            // table created after it, so a new sorted name column pins its own collation here.
            // The locale is guaranteed present: that migration refuses to run without it, and
            // it has run before this one on every box.
            DB::statement('ALTER TABLE export_presets ALTER COLUMN name TYPE varchar(60) COLLATE "en_GB.utf8"');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('export_presets');
    }
};
