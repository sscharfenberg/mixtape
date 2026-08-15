<?php

namespace Tests\Feature\Player;

use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Play;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Play counts for a SUBJECT — an artist, a genre, an album — on their own pages and as the
 * listings' column. The single-track half lives in PlayHistoryTest beside the beacon.
 *
 * ONE COUNTING RULE, SHARED WITH THE SONG PAGE: listening EVENTS by `track_id`. Every play row
 * belongs to exactly one track and so to exactly one artist, which makes a straight join
 * already exact — and which is what lets the figures add up, an album's count being the sum of
 * its tracks'. Counting by `content_hash` instead breaks that; PlayCounts' docblock has the
 * argument and what each reading costs.
 *
 * THE LISTINGS SHOW ONLY THE READER'S OWN listens: this
 * instance is shared with family and friends, so a browse list sorts usefully on what YOU
 * have played, and the yours/others split belongs on the detail page where a tile can label
 * it. That makes it the only per-viewer column in the app, which is what the housemate test
 * guards.
 */
class SubjectPlayCountsTest extends TestCase
{
    use RefreshDatabase;

    /** A music track under a given artist / genre / album, defaulting each to a fresh one. */
    private function track(array $attributes = []): Track
    {
        return Track::factory()->create([
            'artist_id' => Artist::factory()->create()->id,
            'collection_id' => Collection::factory()->create()->id,
            'genre_id' => Genre::factory()->create()->id,
            ...$attributes,
        ]);
    }

    /** Record `$times` listens of one track by one user. */
    private function listen(User $user, Track $track, int $times): void
    {
        Play::factory()->count($times)->create(['user_id' => $user->id, 'track_id' => $track->id]);
    }

    public function test_an_artist_page_splits_the_readers_listens_from_everybody_elses(): void
    {
        $reader = User::factory()->create();
        $housemate = User::factory()->create();
        $artist = Artist::factory()->create();

        // Two DIFFERENT songs, so the figure is plainly the artist's total rather than one
        // track's — the whole reason the subject count exists.
        $first = $this->track(['artist_id' => $artist->id]);
        $second = $this->track(['artist_id' => $artist->id]);

        $this->listen($reader, $first, 3);
        $this->listen($reader, $second, 1);
        $this->listen($housemate, $first, 5);

        $this->actingAs($reader)
            ->get("/music/artists/{$artist->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('plays.own', 4)
                ->where('plays.others', 5)
            );
    }

    public function test_a_genre_page_splits_the_readers_listens_from_everybody_elses(): void
    {
        $reader = User::factory()->create();
        $housemate = User::factory()->create();
        $genre = Genre::factory()->create();

        $this->listen($reader, $this->track(['genre_id' => $genre->id]), 2);
        $this->listen($housemate, $this->track(['genre_id' => $genre->id]), 6);

        $this->actingAs($reader)
            ->get("/music/genres/{$genre->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('plays.own', 2)
                ->where('plays.others', 6)
            );
    }

    public function test_an_album_page_splits_the_readers_listens_from_everybody_elses(): void
    {
        $reader = User::factory()->create();
        $housemate = User::factory()->create();
        $album = Collection::factory()->create();

        $this->listen($reader, $this->track(['collection_id' => $album->id]), 7);
        $this->listen($housemate, $this->track(['collection_id' => $album->id]), 1);

        $this->actingAs($reader)
            ->get("/music/albums/{$album->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('plays.own', 7)
                ->where('plays.others', 1)
            );
    }

    public function test_a_subject_nobody_has_played_reports_zeroes(): void
    {
        // Zero is what the TILES turn into silence; the server just counts.
        $artist = Artist::factory()->create();
        $this->track(['artist_id' => $artist->id]);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertInertia(fn (Assert $page) => $page->where('plays.own', 0)->where('plays.others', 0));
    }

    public function test_a_subject_counts_listening_events_not_recordings(): void
    {
        // One recording, two copies in this artist's catalogue (the album and the best-of),
        // played once each: the artist heard two listens, and that is what their page says.
        // Hash-matching would report four — the same recording counted once per copy.
        $reader = User::factory()->create();
        $artist = Artist::factory()->create();
        $hash = str_repeat('b', 64);

        $album = $this->track(['artist_id' => $artist->id, 'content_hash' => $hash]);
        $bestOf = $this->track(['artist_id' => $artist->id, 'content_hash' => $hash]);

        $this->listen($reader, $album, 1);
        $this->listen($reader, $bestOf, 1);

        $this->actingAs($reader)
            ->get("/music/artists/{$artist->id}")
            ->assertInertia(fn (Assert $page) => $page->where('plays.own', 2));
    }

    public function test_an_albums_count_is_the_sum_of_its_songs_own_counts(): void
    {
        // The property the one shared rule buys, and the reason the song page stopped
        // counting by hash: a reader can add the tiles up. Under the old rule each track
        // counted its twin elsewhere, so the tracks summed to more than the record they sit
        // on, with nothing on screen to say which number was the odd one.
        $reader = User::factory()->create();
        $album = Collection::factory()->create();

        $first = $this->track(['collection_id' => $album->id]);
        $second = $this->track(['collection_id' => $album->id]);
        // A third copy of the first song's recording, filed on a different record entirely.
        $elsewhere = $this->track(['content_hash' => $first->content_hash]);

        $this->listen($reader, $first, 2);
        $this->listen($reader, $second, 3);
        $this->listen($reader, $elsewhere, 9);

        $songTotal = 0;

        foreach ([$first, $second] as $song) {
            $response = $this->actingAs($reader)->get("/music/songs/{$song->id}");
            $songTotal += $this->inertiaProp($response, 'plays.own');
        }

        $this->actingAs($reader)
            ->get("/music/albums/{$album->id}")
            ->assertInertia(fn (Assert $page) => $page->where('plays.own', $songTotal));

        $this->assertSame(5, $songTotal, 'the songs of this album should account for five listens');
    }

    public function test_an_artists_count_is_scoped_to_music_like_the_songs_figure_beside_it(): void
    {
        // The tiles sit in one row, so they have to agree: `songs` counts music only
        // (App\Http\Controllers\Music scopes every query that way), and a plays figure
        // counting listens that the neighbouring number does not count would be arithmetic
        // no reader could reproduce. The Postgres CHECK bars this row outright; SQLite lets
        // the suite build it, which is exactly why the guard is worth a test.
        $reader = User::factory()->create();
        $artist = Artist::factory()->create();

        $song = $this->track(['artist_id' => $artist->id]);
        $chapter = $this->track(['artist_id' => $artist->id, 'type' => TrackType::Audiobook]);

        $this->listen($reader, $song, 2);
        $this->listen($reader, $chapter, 9);

        $this->actingAs($reader)
            ->get("/music/artists/{$artist->id}")
            ->assertInertia(fn (Assert $page) => $page->where('plays.own', 2));
    }

    public function test_the_listings_column_is_the_readers_own_listening_only(): void
    {
        // The only per-viewer column in the app. A housemate's listening must not leak into
        // it — that would make the column a household total wearing a personal label.
        $reader = User::factory()->create();
        $housemate = User::factory()->create();

        $artist = Artist::factory()->create();
        $genre = Genre::factory()->create();
        $album = Collection::factory()->create();
        $track = $this->track(['artist_id' => $artist->id, 'genre_id' => $genre->id, 'collection_id' => $album->id]);

        $this->listen($reader, $track, 3);
        $this->listen($housemate, $track, 40);

        // Addressed by id rather than by position: these listings open on their own default
        // sorts (most audio, newest file), and the factories mint empty subjects along the
        // way whose place in that order is not this test's subject.
        $playsFor = function (string $listing, User $viewer, string $id): int {
            $rows = $this->inertiaProp($this->actingAs($viewer)->get($listing), 'table.rows');
            $row = collect($rows)->firstWhere('id', $id);

            $this->assertNotNull($row, "{$listing} did not list {$id}");

            return $row['plays'];
        };

        $subjects = [
            '/music/artists' => $artist->id,
            '/music/genres' => $genre->id,
            '/music/albums' => $album->id,
        ];

        foreach ($subjects as $listing => $id) {
            $this->assertSame(3, $playsFor($listing, $reader, $id), "{$listing} did not show the reader their own count");
            $this->assertSame(40, $playsFor($listing, $housemate, $id), "{$listing} did not show the housemate their own count");
        }
    }

    public function test_a_listing_row_nobody_has_played_reports_zero_rather_than_null(): void
    {
        // The COALESCE over the LEFT JOIN. Left null, Postgres would sort these rows FIRST
        // under a descending sort — opening the page on precisely the artists the reader has
        // never listened to — and the column would print a blank where the page draws a dash.
        Artist::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get('/music/artists')
            ->assertInertia(fn (Assert $page) => $page->where('table.rows.0.plays', 0));
    }

    public function test_each_listing_can_be_sorted_by_plays_in_both_directions(): void
    {
        $reader = User::factory()->create();

        // One well-played subject and one barely-played one, on all three axes at once.
        $loved = $this->track();
        $ignored = $this->track();
        $this->listen($reader, $loved, 9);
        $this->listen($reader, $ignored, 1);

        $expected = [
            '/music/artists' => [$loved->artist_id, $ignored->artist_id],
            '/music/genres' => [$loved->genre_id, $ignored->genre_id],
            '/music/albums' => [$loved->collection_id, $ignored->collection_id],
        ];

        foreach ($expected as $listing => [$most, $least]) {
            $descending = $this->actingAs($reader)->get("{$listing}?sort=plays&dir=desc");
            $ascending = $this->actingAs($reader)->get("{$listing}?sort=plays&dir=asc");

            $descending->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('table.sort.key', 'plays')
                ->where('table.sort.direction', 'desc')
            );

            // The RELATIVE order of the two rows this test controls, not their absolute
            // positions: the factories mint unplayed subjects of their own along the way (a
            // Collection brings an album-artist), and those legitimately sit ahead of both
            // of these under an ascending sort. Asserting row 0 would be asserting the
            // fixture's noise. What has to hold is that the pair comes back reversed — which
            // is what fails if ORDER BY cannot see the joined alias.
            $ranking = fn ($response): array => array_column($this->inertiaProp($response, 'table.rows'), 'id');

            $this->assertLessThan(
                array_search($least, $ranking($descending), true),
                array_search($most, $ranking($descending), true),
                "{$listing} did not put the most-played first under sort=plays&dir=desc"
            );
            $this->assertGreaterThan(
                array_search($least, $ranking($ascending), true),
                array_search($most, $ranking($ascending), true),
                "sorting {$listing} by plays did not reverse"
            );
        }
    }

    public function test_a_track_filed_under_nothing_costs_no_listing_its_rows(): void
    {
        // Tracks with a null artist/genre/album are real (an untagged rip), and their plays
        // group under a null key. The grouped subquery drops those rows rather than joining
        // them to nothing — this asserts the listings still render, and still count what is
        // filed properly.
        $reader = User::factory()->create();

        $orphan = Track::factory()->create(['artist_id' => null, 'genre_id' => null, 'collection_id' => null]);
        $this->listen($reader, $orphan, 4);

        $artist = Artist::factory()->create();
        $this->listen($reader, $this->track(['artist_id' => $artist->id]), 2);

        $this->actingAs($reader)
            ->get('/music/artists?sort=plays&dir=desc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.id', $artist->id)
                ->where('table.rows.0.plays', 2)
            );
    }
}
