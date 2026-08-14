<?php

namespace Tests\Feature\Music;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Artists listing (`/music/artists`, behind auth) — the server-driven DataTable
 * payload ArtistsController shapes.
 *
 * What these tests are about: an artist row is ENTIRELY aggregate apart from its name, so
 * every column is a claim about a subquery. Two of those claims are worth more than the
 * rest, and get a test each rather than a shared one:
 *   - the album count is the artist's own DISCOGRAPHY (credited albums) and not the albums
 *     their tracks turn up on, so a guest performer reports 0 albums beside their songs —
 *     the reading the column deliberately commits to (the other count lives on the detail
 *     page, see ArtistPageTest);
 *   - the totals are scoped to MUSIC tracks, which nothing else in the app can catch
 *     today: the only other track kind is an
 *     audiobook chapter, and the `tracks` CHECK forbids one of those an `artist_id` at
 *     all — so the scope is belt-and-braces until a kind that CAN carry one arrives.
 */
class ArtistsPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One music track by $artist, on $album, with everything the aggregates read made
     * explicit — TrackFactory randomises duration / size / mtime by design, and every
     * assertion here is on a sum of exactly those columns.
     */
    private function track(Artist $artist, ?Collection $album, float $duration = 100.0, int $size = 1_000_000): Track
    {
        return Track::factory()->create([
            'artist_id' => $artist->id,
            'collection_id' => $album?->id,
            'duration' => $duration,
            'size' => $size,
        ]);
    }

    /**
     * An album owned by nobody — a compilation. `album_artist_id` is nullable (the
     * collections CHECK only pins it to `type = 'album'`), and leaving it null matters in
     * these tests: CollectionFactory's default mints a whole new Artist for the owner,
     * which would then turn up as an extra row in the listing under assertion.
     */
    private function compilation(string $name): Collection
    {
        return Collection::factory()->create(['name' => $name, 'album_artist_id' => null]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/music/artists')->assertRedirect('/login');
    }

    public function test_a_row_carries_the_album_count_the_track_totals_and_the_href(): void
    {
        $artist = Artist::factory()->create(['name' => 'Sonic Youth']);

        // THREE albums of their own, tracks on only one of them, plus one track on someone
        // else's compilation. The numbers are chosen so no other reading of "albums" gives
        // 3: the albums their tracks sit on would be 2, and only one of those is theirs.
        $own = Collection::factory()->count(3)->create(['album_artist_id' => $artist->id]);
        $guest = $this->compilation('Whatever Happened To…');

        // Three × a fractional duration, so the sum is deliberately NOT a whole number:
        // that is the only way to see raw seconds went over rather than something
        // already rounded for display.
        $this->track($artist, $own->first(), duration: 100.5, size: 4_000_000);
        $this->track($artist, $own->first(), duration: 100.0, size: 5_000_000);
        $this->track($artist, $guest, duration: 71.0, size: 6_000_000);

        $this->actingAs(User::factory()->create())
            ->get('/music/artists')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Music/Artists/ArtistsPage')
                ->has('table.rows', 1)
                ->where('table.rows.0.name', 'Sonic Youth')
                // Their discography — all three, the two they have no tracks on included,
                // and the guest compilation NOT counted.
                ->where('table.rows.0.albums', 3)
                ->where('table.rows.0.songs', 3)
                // Raw seconds and raw bytes, summed — the page clocks and humanises them
                // (Utils/formatting.ts → formatClock / formatFileSize).
                ->where('table.rows.0.duration', 271.5)
                ->where('table.rows.0.size', 15_000_000)
                ->where('table.rows.0.href', "/music/artists/{$artist->id}")
            );
    }

    public function test_the_album_count_is_the_artists_own_discography_not_the_albums_they_play_on(): void
    {
        // The case that pins the column's meaning. A compilation owner is credited with the
        // album while its songs credit the individual performers, so the SAME album counts
        // for the owner and not for the performer who actually plays on it.
        $owner = Artist::factory()->create(['name' => 'Irish Folk Festival']);
        $performer = Artist::factory()->create(['name' => 'Tommy Peoples']);

        $album = Collection::factory()->create(['album_artist_id' => $owner->id]);
        $this->track($performer, $album);

        // Sorted by name rather than left on the default (most audio first), so the row
        // order this asserts on is stated rather than a side effect of the fixture's
        // durations — the test is about the counts.
        $this->actingAs(User::factory()->create())
            ->get('/music/artists?sort=name&dir=asc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 2)
                // Credited with the album, and with none of its songs.
                ->where('table.rows.0.name', 'Irish Folk Festival')
                ->where('table.rows.0.albums', 1)
                ->where('table.rows.0.songs', 0)
                // COALESCEd to 0, not null: nothing to sum, but a NULL here is what floats
                // track-less artists to the top of a descending sort on Postgres — and the
                // default sort IS one. Asserted as an int, since a whole-number float
                // crosses the wire as `0` (the props are compared after JSON encoding).
                ->where('table.rows.0.duration', 0)
                ->where('table.rows.0.size', 0)
                // And the reverse: a song on that album, but no discography of their own.
                // 0 albums beside 1 song is the intended reading, not missing data.
                ->where('table.rows.1.name', 'Tommy Peoples')
                ->where('table.rows.1.albums', 0)
                ->where('table.rows.1.songs', 1)
            );
    }

    public function test_the_listing_opens_on_the_artists_with_the_most_audio(): void
    {
        // The default sort, and the one column where "default" is a real decision: the
        // listing answers "who do I have most of" before it answers "who starts with A".
        $most = Artist::factory()->create(['name' => 'Zzyzx']);
        $least = Artist::factory()->create(['name' => 'Aardvark']);

        $this->track($most, $this->compilation('Long'), duration: 300.0);
        $this->track($least, $this->compilation('Short'), duration: 60.0);

        $this->actingAs(User::factory()->create())
            ->get('/music/artists')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Alphabetically these two are the wrong way round, which is the point.
                ->where('table.rows.0.id', $most->id)
                ->where('table.rows.1.id', $least->id)
                ->where('table.sort.key', 'duration')
                ->where('table.sort.direction', 'desc')
                // The name tiebreak is advertised here (it only is on the default sort), so
                // the header can show the compound "most audio, then A–Z" order.
                ->where('table.tiebreakers', ['name'])
            );
    }

    public function test_artists_with_the_same_playing_time_fall_back_to_a_stable_alphabetical_order(): void
    {
        // Why the tiebreak exists, and on this listing it is not an edge case: every
        // credited-only artist sits at 0 seconds, so without a trailing key SQL may order
        // that whole block differently on each request — enough for one artist to show up
        // on two pages of the same browse.
        foreach (['Charlie', 'Alpha', 'Bravo'] as $name) {
            $artist = Artist::factory()->create(['name' => $name]);
            Collection::factory()->create(['album_artist_id' => $artist->id]);
        }

        $this->actingAs(User::factory()->create())
            ->get('/music/artists')
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.name', 'Alpha')
                ->where('table.rows.1.name', 'Bravo')
                ->where('table.rows.2.name', 'Charlie')
                // All three at zero — the tie the order above had to survive.
                ->where('table.rows.0.duration', 0)
            );
    }

    public function test_every_aggregate_column_can_be_sorted_by(): void
    {
        // A small artist and a large one, then each column asked for in both directions:
        // the pair must come back reversed. This is the test that would catch ORDER BY on
        // a subquery alias failing.
        //
        // Every sorted-on value DIFFERS between the two, including the ones easy to leave
        // equal — a tie sorts stably, so a tied column would "not reverse" and read as a
        // broken sort when it is really a broken fixture. The names run OPPOSITE to the
        // numbers ("Zeta" is the small one) so no column can pass by accidentally
        // agreeing with another.
        $small = Artist::factory()->create(['name' => 'Zeta']);
        $large = Artist::factory()->create(['name' => 'Alpha']);

        $this->track($small, Collection::factory()->create(['album_artist_id' => $small->id]), 60.0, 1_000_000);

        $largeAlbums = Collection::factory()->count(2)->create(['album_artist_id' => $large->id]);
        $this->track($large, $largeAlbums->first(), 100.0, 2_000_000);
        $this->track($large, $largeAlbums->first(), 100.0, 2_000_000);
        $this->track($large, $largeAlbums->last(), 100.0, 2_000_000);

        $user = User::factory()->create();

        foreach (['name', 'albums', 'songs', 'duration', 'size'] as $key) {
            $ascending = $this->actingAs($user)->get("/music/artists?sort={$key}&dir=asc");
            $descending = $this->actingAs($user)->get("/music/artists?sort={$key}&dir=desc");

            $ascending->assertOk()->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 2)
                ->where('table.sort.key', $key)
                ->where('table.sort.direction', 'asc')
            );

            $first = $ascending->viewData('page')['props']['table']['rows'][0]['id'];
            $last = $descending->viewData('page')['props']['table']['rows'][1]['id'];

            $this->assertSame($first, $last, "sorting by {$key} did not reverse");
            $this->assertContains($first, [$small->id, $large->id]);
        }
    }

    public function test_search_matches_an_artist_name_and_ignores_accents(): void
    {
        $accented = Artist::factory()->create(['name' => 'Mgła']);
        $plain = Artist::factory()->create(['name' => 'Soundgarden']);

        $user = User::factory()->create();

        // The accent-free spelling is what the folded columns exist for (FoldedSearch),
        // and on artist names it is the case that matters most.
        foreach (['mgla' => $accented, 'Mgła' => $accented, 'soundgar' => $plain] as $term => $expected) {
            $this->actingAs($user)
                ->get('/music/artists?search='.urlencode((string) $term))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('table.rows', 1)
                    ->where('table.rows.0.id', $expected->id)
                );
        }
    }
}
