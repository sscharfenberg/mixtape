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
     * IT MERGES THE SPLIT BOOKS, because the alternative is telling somebody to destroy their
     * data. The new `(type, name, album_artist_id)` unique index cannot build while the
     * duplicates are there, so the choice is to merge them or to fail — and failing means the
     * migration's advice is `migrate:fresh`, which on a live instance costs every account,
     * playlist, listen and SHARE LINK already handed to somebody. The library is the only part
     * a re-scan can rebuild.
     *
     * The merge is safe because the duplicate rows are the same book: the old scanner keyed
     * `firstOrCreate` on `(type, name, album_artist_id, author_id)`, so the ONLY thing that
     * differed between them was the author — which step 1 above has already moved onto the
     * chapters by the time this runs. Whichever row is kept, nothing is lost with the others.
     *
     * Everything pointing at a losing row is moved first: chapters, share links, and reading
     * positions. Bookmarks need more than a repoint — their primary key is (user, book), so a
     * reader holding a position in two halves of one split book would collide — and the newest
     * of theirs is kept, which is the one they would expect to come back to.
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

            // BEFORE the narrower unique below, either driver's version of it: it cannot build
            // over the rows the old scanner split, and the merge is what makes them one book.
            $this->mergeSplitAudiobooks();

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
     * Fold each audiobook the old scanner split by author back into one row.
     *
     * `firstOrCreate` keyed on `(type, name, album_artist_id, author_id)`, so an anthology
     * naming four authors across its chapters became four collection rows sharing one name.
     * Step 1 has already copied the author onto the chapters, so the rows now differ in
     * nothing — any of them can be the keeper.
     *
     * Deterministic rather than arbitrary: the lowest id wins, so a re-run and a restore of
     * the same backup make the same choice, and a half-applied migration resumes rather than
     * shuffling rows about.
     */
    private function mergeSplitAudiobooks(): void
    {
        $groups = DB::table('collections')
            ->where('type', 'audiobook')
            ->select('name', 'album_artist_id')
            ->groupBy('name', 'album_artist_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $ids = DB::table('collections')
                ->where('type', 'audiobook')
                ->where('name', $group->name)
                ->when(
                    $group->album_artist_id === null,
                    fn ($query) => $query->whereNull('album_artist_id'),
                    fn ($query) => $query->where('album_artist_id', $group->album_artist_id)
                )
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $keeper = array_shift($ids);

            if ($keeper === null || $ids === []) {
                continue;
            }

            DB::table('tracks')->whereIn('collection_id', $ids)->update(['collection_id' => $keeper]);
            DB::table('shares')->whereIn('collection_id', $ids)->update(['collection_id' => $keeper]);

            $this->mergeBookmarks([...$ids, $keeper], $keeper);

            DB::table('collections')->whereIn('id', $ids)->delete();
        }
    }

    /**
     * Leave one reading position per reader across a merged book, keeping their newest.
     *
     * A plain repoint would violate the bookmarks table's (user, collection) primary key for
     * anybody who had listened to two halves of the same split book — so the surviving rows
     * are chosen first, the whole group is cleared, and the survivors written back against the
     * keeper. The newest is the one a reader expects to return to.
     *
     * Guarded on the table existing: this migration runs BEFORE the one that creates it on a
     * database migrating in order, and only meets it on one already carrying both.
     *
     * @param  list<string>  $groupIds  every collection id in the group, keeper included
     */
    private function mergeBookmarks(array $groupIds, string $keeper): void
    {
        if (! Schema::hasTable('audiobook_bookmarks')) {
            return;
        }

        $survivors = DB::table('audiobook_bookmarks')
            ->whereIn('collection_id', $groupIds)
            ->orderByDesc('updated_at')
            ->get()
            ->unique('user_id');

        DB::table('audiobook_bookmarks')->whereIn('collection_id', $groupIds)->delete();

        foreach ($survivors as $bookmark) {
            DB::table('audiobook_bookmarks')->insert([
                'user_id' => $bookmark->user_id,
                'collection_id' => $keeper,
                'track_id' => $bookmark->track_id,
                'position_ms' => $bookmark->position_ms,
                'updated_at' => $bookmark->updated_at,
            ]);
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
