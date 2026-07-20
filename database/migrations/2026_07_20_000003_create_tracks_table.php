<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `tracks` — the one unified playable row (option B). Legacy had two parallel
     * `songs` + `tracks` tables sharing 15 columns; v2 merges them so every
     * cross-cutting feature (playlists, plays, share-links, search) points at ONE
     * table and ONE FK (data-model.md → (a)).
     *
     * Identity is content-based, not path-based: the scan is a diff keyed on an
     * audio-stream `content_hash`, so a rename or a re-tag keeps the row's id
     * (data-model.md → "the one fact"). `path` stays UNIQUE (one file ⇒ one row)
     * but is just a mutable attribute + the fast-path change-detection key; two
     * files with identical audio are two rows (clones), sharing a content_hash.
     */
    public function up(): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::create('tracks', function (Blueprint $table) {
            $table->uuid('id')->primary(); // independent random uuid — NOT derived from the hash

            // Playable kind; the CHECK below guards the taxonomy FKs against it.
            $table->enum('type', ['music', 'audiobook', 'podcast']);

            // --- Taxonomy FKs (all `restrict`, all nullable) ---------------------
            // restrict, not cascade: deleting an artist must never delete their
            // tracks. The scanner only prunes ORPHAN taxonomy (data-model.md → (b)
            // #1/#5), so restrict never fires in normal operation. The container
            // FK collapses album_id/audiobook_id into one collection_id.
            $table->foreignUuid('collection_id')->nullable()
                ->constrained('collections')->restrictOnDelete();
            $table->foreignUuid('artist_id')->nullable()
                ->constrained('artists')->restrictOnDelete();   // performer (music)
            $table->foreignUuid('genre_id')->nullable()
                ->constrained('genres')->restrictOnDelete();    // music
            $table->foreignUuid('narrator_id')->nullable()
                ->constrained('narrators')->restrictOnDelete(); // audiobook

            // Music-only free-text tags (not guarded by the CHECK — harmless if set).
            $table->string('composer', 255)->nullable();
            $table->string('publisher', 128)->nullable();

            // --- Identity + scan bookkeeping -------------------------------------
            $table->string('name', 255); // song / chapter title (default collation → trgm search)
            $table->string('path', 512)->unique(); // scan anchor: one file ⇒ one row
            $table->string('content_hash', 64);    // audio-stream hash = identity; clones share it
            $table->unsignedBigInteger('size')->nullable();  // bytes; with path+modified_at = fast-path
            $table->dateTime('modified_at')->nullable();     // filemtime

            // --- Technical stream fields (read from the mp3) ---------------------
            $table->string('codec', 14)->nullable();
            $table->enum('channel', ['stereo', 'dual_mono', 'joint_stereo', 'mono'])->nullable();
            $table->double('duration')->nullable(); // seconds (was float precision 53 = double)
            $table->unsignedMediumInteger('sample_rate')->nullable();
            $table->unsignedMediumInteger('bit_rate')->nullable();
            $table->boolean('vbr')->default(false);
            $table->boolean('cover')->default(false);
            $table->unsignedSmallInteger('track')->nullable();
            $table->unsignedTinyInteger('disc')->nullable();

            // Real insert-time "date added" (data-model.md → (c)). Set once and
            // untouched by re-tags/renames (those are UPDATEs), unlike legacy's
            // filemtime. No updated_at — the model sets `const UPDATED_AT = null`.
            $table->timestamp('created_at')->nullable();

            // --- Indexes (Postgres does not index FK referencing columns) --------
            $table->index('content_hash');                 // rename-match + "x clones"
            $table->index('artist_id');                    // restrict/prune + joins
            $table->index('genre_id');
            $table->index('narrator_id');
            $table->index(['collection_id', 'disc', 'track']); // ordered playback (also covers collection_id)
            $table->index(['type', 'created_at']);             // "recently added" per media type
        });

        if ($pgsql) {
            // Type-guard: music tracks carry no narrator; audiobook tracks carry no
            // artist/genre. podcast is unconstrained here (data-model.md → (a)).
            DB::statement(
                'ALTER TABLE tracks ADD CONSTRAINT tracks_type_taxonomy_ck CHECK ('
                ."(type <> 'music' OR narrator_id IS NULL) AND "
                ."(type <> 'audiobook' OR (artist_id IS NULL AND genre_id IS NULL)))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
