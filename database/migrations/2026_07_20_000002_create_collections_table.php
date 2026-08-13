<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `collections` — the merged albums + audiobooks container (data-model.md →
     * (a), "the collections half-step"). One table with a `type` holds a music
     * album or an audiobook, so a track has ONE
     * `collection_id` regardless of media type and adding a new type stays cheap.
     *
     * The container-level owner lives here (not on `tracks`): `album_artist_id`
     * for a music album, `author_id` for an audiobook. That fixes the legacy bug
     * where audiobooks had no owner FK and two same-titled books collapsed into
     * one row (data-model.md → (b) #3).
     *
     * `name` dedupes case-insensitively on both drivers — the ICU collation on Postgres, sqlite's
     * own `nocase` in the suite. The taxonomy migration carries why the sqlite half is spelled
     * out rather than left to the default (it is `BINARY`, i.e. case-SENSITIVE, so the two
     * engines used to disagree about what "the same album" means).
     */
    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $collation = $pgsql ? 'case_insensitive' : 'nocase';

        Schema::create('collections', function (Blueprint $table) use ($collation, $pgsql) {
            $table->uuid('id')->primary();

            // enum → varchar + value CHECK on Postgres (data-model.md → (c)).
            $table->enum('type', ['album', 'audiobook']);
            $table->string('name', 255)->collation($collation);
            $table->year('year')->nullable();
            $table->boolean('cover')->default(false); // has a Folder.jpg alongside it

            // Container owner. Taxonomy FKs are `restrict`, not `cascade`/`null`:
            // the scanner only ever prunes ORPHAN taxonomy (data-model.md → (b) #5),
            // so restrict never blocks a real delete, and it turns an accidental
            // delete of a still-referenced artist into a loud error rather than a
            // silent cascade or a stray null (data-model.md → (b) #1).
            //
            // ONLY THE ALBUM OWNER LIVES HERE. An audiobook's author is a property of
            // the CHAPTER, not of the book (2026-08-13) — see the tracks migration.
            $table->foreignUuid('album_artist_id')->nullable()
                ->constrained('artists')->restrictOnDelete();

            $table->timestamps(); // created_at = "date added" at the album/book grain

            // Standalone FK indexes: Postgres does not index the referencing side
            // of a FK, and these back the restrict checks + the orphan-prune
            // (data-model.md → (c)). `type`-leading composite also serves
            // "recently added <type>" and alphabetical browse.
            $table->index('album_artist_id');
            $table->index(['type', 'created_at']);

            if (! $pgsql) {
                // sqlite has no NULLS NOT DISTINCT; a plain composite unique is
                // close enough for the test suite (it treats NULL owners as
                // distinct, which the real Postgres index below does not).
                $table->unique(['type', 'name', 'album_artist_id']);
            }
        });

        if ($pgsql) {
            // Owner is set only for its own type. An audiobook has no owner column at
            // all now, so this reads one-sided (2026-08-13).
            DB::statement(
                'ALTER TABLE collections ADD CONSTRAINT collections_owner_type_ck CHECK ('
                ."type = 'album' OR album_artist_id IS NULL)"
            );

            // Dedup key (data-model.md → (b) #3). NULLS NOT DISTINCT (PG15+) so two
            // untagged-owner rows of the same title collide instead of slipping
            // past as separate. `name` carries the case_insensitive collation, so
            // the whole index dedupes case-insensitively and Unicode-correctly.
            //
            // AN AUDIOBOOK THEREFORE DEDUPES ON ITS TITLE ALONE, which is the point:
            // an anthology whose chapters name four different authors is ONE book, and
            // keying on the author split it into four (measured on the real library,
            // 2026-08-13 — "Necrophobia 1" became four rows sharing a name).
            DB::statement(
                'CREATE UNIQUE INDEX collections_dedup_uq ON collections '
                .'(type, name, album_artist_id) NULLS NOT DISTINCT'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
    }
};
