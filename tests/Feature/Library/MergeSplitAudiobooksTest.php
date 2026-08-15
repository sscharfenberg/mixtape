<?php

namespace Tests\Feature\Library;

use App\Enums\TrackType;
use App\Models\Share;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * The merge inside `2026_08_13_000000_move_audiobook_author_to_tracks`.
 *
 * WHAT IT GUARDS IS A REAL OUTAGE. The old scanner keyed `firstOrCreate` on
 * `(type, name, album_artist_id, author_id)`, so an anthology naming four authors across its
 * chapters became four collection rows sharing one name. The migration narrows that unique to
 * `(type, name, album_artist_id)` — which cannot build over them, and a production deploy
 * stopped dead with `could not create unique index "collections_dedup_uq"`.
 *
 * Its advice was `migrate:fresh`, written when the instance had no users. It has users now, and
 * that advice costs every account, playlist, listen and SHARE LINK already handed to somebody.
 * So the migration merges instead, and this proves the merge carries everything pointing at a
 * losing row rather than orphaning it.
 *
 * NO TEST COULD HAVE CAUGHT THE ORIGINAL FAILURE, which is worth knowing before trusting a
 * green suite here: the duplicates all have a NULL `album_artist_id`, sqlite's plain composite
 * unique treats NULLs as DISTINCT, and only Postgres's `NULLS NOT DISTINCT` refuses them. The
 * create-table migration says so itself ("close enough for the test suite"). What that buys is
 * this file — sqlite will happily hold the broken shape, so the repair can be exercised on it.
 *
 * REACHED BY REFLECTION, deliberately. The merge is one-shot data repair that belongs to the
 * migration and nowhere else; lifting it into a service to make it reachable would put
 * permanent production code in the app to serve a single historical database shape. The private
 * method is the seam, and a test may know about it.
 */
class MergeSplitAudiobooksTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Write one audiobook collection row directly.
     *
     * Not through the factory: the shape being repaired is one the current schema no longer
     * produces, so the duplicate IS the fixture and has to be written by hand — which is also
     * what a restored backup looks like.
     */
    private function bookRow(string $name): string
    {
        $id = (string) Str::uuid();

        DB::table('collections')->insert([
            'id' => $id,
            'type' => 'audiobook',
            'name' => $name,
            'name_fold' => Str::lower($name),
            'album_artist_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Run the migration's own merge against the current database. */
    private function merge(): void
    {
        $migration = require database_path('migrations/2026_08_13_000000_move_audiobook_author_to_tracks.php');

        $method = (new ReflectionClass($migration))->getMethod('mergeSplitAudiobooks');
        $method->setAccessible(true);
        $method->invoke($migration);
    }

    public function test_it_folds_the_split_rows_into_one_book_and_keeps_every_chapter(): void
    {
        $ids = collect(range(1, 3))->map(fn () => $this->bookRow('Necrophobia 1'))->all();
        sort($ids);

        foreach ($ids as $index => $id) {
            Track::factory()->audiobook()->count($index + 1)->create(['collection_id' => $id]);
        }

        $this->merge();

        // One book, and it is the lowest id — deterministic, so a re-run or a restore of the
        // same backup makes the same choice rather than shuffling rows about.
        $survivors = DB::table('collections')->where('type', 'audiobook')->pluck('id')->all();
        $this->assertSame([$ids[0]], $survivors);

        // 1 + 2 + 3 chapters, all now under the keeper: a merge that dropped rows would be a
        // book losing chapters, which is the failure a reader would actually notice.
        $this->assertSame(6, DB::table('tracks')->where('collection_id', $ids[0])->count());
        $this->assertSame(6, Track::query()->where('type', TrackType::Audiobook)->count());
    }

    public function test_a_share_of_a_losing_row_still_points_at_the_book(): void
    {
        /*
         * THE ONE THAT MADE `migrate:fresh` UNACCEPTABLE. A share is a link already sent to
         * somebody; if the merge deleted its collection the FK would cascade and the link would
         * die silently, which is exactly what wiping the database does — only quieter.
         */
        $ids = [$this->bookRow('Necrophobia 1'), $this->bookRow('Necrophobia 1')];
        sort($ids);

        $share = Share::factory()->create([
            'user_id' => User::factory()->create()->id,
            'collection_id' => $ids[1],
            'track_id' => null,
            'artist_id' => null,
            'playlist_id' => null,
        ]);

        $this->merge();

        $this->assertSame($ids[0], $share->fresh()->collection_id);
    }

    public function test_two_reading_positions_in_one_split_book_collapse_to_the_newest(): void
    {
        /*
         * A plain repoint would violate the bookmarks table's (user, collection) primary key
         * for anybody who listened to two halves of the same split book. The newest survives,
         * which is the chapter they would expect to come back to.
         */
        $ids = [$this->bookRow('Necrophobia 1'), $this->bookRow('Necrophobia 1')];
        sort($ids);

        $reader = User::factory()->create();
        $older = Track::factory()->audiobook()->create(['collection_id' => $ids[0]]);
        $newer = Track::factory()->audiobook()->create(['collection_id' => $ids[1]]);

        foreach ([[$ids[0], $older, 1_000, '2026-08-01 10:00:00'], [$ids[1], $newer, 9_000, '2026-08-09 10:00:00']] as [$book, $track, $position, $at]) {
            DB::table('audiobook_bookmarks')->insert([
                'user_id' => $reader->id,
                'collection_id' => $book,
                'track_id' => $track->id,
                'position_ms' => $position,
                'updated_at' => $at,
            ]);
        }

        $this->merge();

        $bookmarks = DB::table('audiobook_bookmarks')->get();

        $this->assertCount(1, $bookmarks);
        $this->assertSame($ids[0], $bookmarks[0]->collection_id);
        $this->assertSame($newer->id, $bookmarks[0]->track_id, 'the older position won');
        $this->assertSame(9_000, (int) $bookmarks[0]->position_ms);
    }

    public function test_it_leaves_a_library_with_no_duplicates_alone(): void
    {
        // The ordinary case, and the one it runs against on every fresh install: nothing to do.
        $only = $this->bookRow('Berge des Wahnsinns');
        Track::factory()->audiobook()->create(['collection_id' => $only]);

        $this->merge();

        $this->assertSame([$only], DB::table('collections')->pluck('id')->all());
        $this->assertSame(1, DB::table('tracks')->count());
    }
}
