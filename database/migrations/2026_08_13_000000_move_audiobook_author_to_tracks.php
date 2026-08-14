<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move an audiobook's author from the BOOK to the CHAPTER, mirroring
     * `narrator_id`, which was always per-track.
     *
     * WHY, measured on the real library rather than argued: TCOM is a per-file tag, and an
     * anthology uses it per story. "Necrophobia 1" names four authors across its 33 chapters
     * and "Necrophobia 2" names five. `LibraryScanService::collection()` keyed its
     * firstOrCreate on `(type, name, album_artist_id, author_id)`, so those two books scanned
     * as ELEVEN collection rows sharing two names — and a book-level column could not fill the
     * detail page's per-chapter Author column either.
     *
     * THE ORIGINAL MIGRATIONS WERE EDITED TOO, the same way the podcast narrowing was
     * (2026_08_08_000001): a fresh install builds the right shape directly and this file finds
     * nothing to do. It exists for databases already built from the old ones — every step is
     * guarded on what is actually there, so it is a no-op on a fresh database and idempotent
     * on a half-migrated one.
     *
     * IT DOES NOT MERGE THE SPLIT BOOKS, deliberately: on an existing
     * database the new `(type, name, album_artist_id)` unique index will REFUSE to build while
     * the duplicate audiobook rows are still there, and the answer is `migrate:fresh` plus a
     * full `app:update` re-scan rather than a merge nobody will ever need again. There are no
     * real users yet. If that index creation fails, that is what it is telling you.
     *
     * Postgres carries the CHECK rewrites alone, for the reason the podcast migration records:
     * sqlite keeps a table's CHECKs inside its definition, so narrowing one means rebuilding a
     * table carrying nine indexes and four foreign keys — and the only sqlite databases here
     * are throwaways that migrate from scratch, taking the corrected shape from the originals.
     */
    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        // 1. The chapter gains the column, and inherits whatever its book carried.
        if (! Schema::hasColumn('tracks', 'author_id')) {
            Schema::table('tracks', function (Blueprint $table) {
                $table->foreignUuid('author_id')->nullable()
                    ->constrained('authors')->restrictOnDelete();
                $table->index('author_id');
            });

            if (Schema::hasColumn('collections', 'author_id')) {
                DB::statement(
                    'UPDATE tracks SET author_id = ('
                    .'SELECT c.author_id FROM collections c WHERE c.id = tracks.collection_id'
                    .") WHERE type = 'audiobook'"
                );
            }
        }

        if ($pgsql) {
            // 2. Music may now carry no author either.
            DB::statement('ALTER TABLE tracks DROP CONSTRAINT IF EXISTS tracks_type_taxonomy_ck');
            DB::statement(
                'ALTER TABLE tracks ADD CONSTRAINT tracks_type_taxonomy_ck CHECK ('
                ."(type <> 'music' OR (narrator_id IS NULL AND author_id IS NULL)) AND "
                ."(type <> 'audiobook' OR (artist_id IS NULL AND genre_id IS NULL)))"
            );
        }

        // 3. The book gives the column up. Both the dedup index and the owner CHECK name it,
        //    so they go first — a column cannot be dropped while a constraint reads it.
        if (Schema::hasColumn('collections', 'author_id')) {
            if ($pgsql) {
                DB::statement('DROP INDEX IF EXISTS collections_dedup_uq');
                DB::statement('ALTER TABLE collections DROP CONSTRAINT IF EXISTS collections_owner_type_ck');
            }

            Schema::table('collections', function (Blueprint $table) use ($pgsql) {
                if (! $pgsql) {
                    $table->dropUnique(['type', 'name', 'album_artist_id', 'author_id']);
                }

                $table->dropIndex(['author_id']);
                $table->dropConstrainedForeignId('author_id');

                if (! $pgsql) {
                    $table->unique(['type', 'name', 'album_artist_id']);
                }
            });

            if ($pgsql) {
                DB::statement(
                    'ALTER TABLE collections ADD CONSTRAINT collections_owner_type_ck CHECK ('
                    ."type = 'album' OR album_artist_id IS NULL)"
                );
                DB::statement(
                    'CREATE UNIQUE INDEX collections_dedup_uq ON collections '
                    .'(type, name, album_artist_id) NULLS NOT DISTINCT'
                );
            }
        }
    }

    /**
     * Put the author back on the book, taking each book's author from its first chapter that
     * names one.
     *
     * Lossy by nature and honest about it: an anthology has more authors than a single column
     * can hold, so rolling back keeps one of them and the rest are recovered only by a
     * re-scan. The forward direction is the one that matters here.
     */
    public function down(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        if (! Schema::hasColumn('collections', 'author_id')) {
            if ($pgsql) {
                DB::statement('DROP INDEX IF EXISTS collections_dedup_uq');
                DB::statement('ALTER TABLE collections DROP CONSTRAINT IF EXISTS collections_owner_type_ck');
            }

            Schema::table('collections', function (Blueprint $table) use ($pgsql) {
                if (! $pgsql) {
                    $table->dropUnique(['type', 'name', 'album_artist_id']);
                }

                $table->foreignUuid('author_id')->nullable()
                    ->constrained('authors')->restrictOnDelete();
                $table->index('author_id');

                if (! $pgsql) {
                    $table->unique(['type', 'name', 'album_artist_id', 'author_id']);
                }
            });

            DB::statement(
                'UPDATE collections SET author_id = ('
                .'SELECT t.author_id FROM tracks t '
                .'WHERE t.collection_id = collections.id AND t.author_id IS NOT NULL LIMIT 1'
                .") WHERE type = 'audiobook'"
            );

            if ($pgsql) {
                DB::statement(
                    'ALTER TABLE collections ADD CONSTRAINT collections_owner_type_ck CHECK ('
                    ."(type = 'album' OR album_artist_id IS NULL) AND "
                    ."(type = 'audiobook' OR author_id IS NULL))"
                );
                DB::statement(
                    'CREATE UNIQUE INDEX collections_dedup_uq ON collections '
                    .'(type, name, album_artist_id, author_id) NULLS NOT DISTINCT'
                );
            }
        }

        if (Schema::hasColumn('tracks', 'author_id')) {
            if ($pgsql) {
                DB::statement('ALTER TABLE tracks DROP CONSTRAINT IF EXISTS tracks_type_taxonomy_ck');
                DB::statement(
                    'ALTER TABLE tracks ADD CONSTRAINT tracks_type_taxonomy_ck CHECK ('
                    ."(type <> 'music' OR narrator_id IS NULL) AND "
                    ."(type <> 'audiobook' OR (artist_id IS NULL AND genre_id IS NULL)))"
                );
            }

            Schema::table('tracks', function (Blueprint $table) {
                $table->dropIndex(['author_id']);
                $table->dropConstrainedForeignId('author_id');
            });
        }
    }
};
