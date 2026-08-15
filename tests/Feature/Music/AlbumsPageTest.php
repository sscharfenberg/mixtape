<?php

namespace Tests\Feature\Music;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Music\Concerns\SortsAListing;
use Tests\TestCase;

/**
 * The Albums listing (`/music/albums`, behind auth) — the server-driven DataTable
 * payload AlbumsController shapes.
 *
 * What is different from SongsPageTest, and what these tests are therefore about: an
 * album row is mostly AGGREGATE. Four of its eight columns are computed over the
 * album's tracks (songs, discs, playing time, newest mtime), so they are asserted on
 * their values *and* on being sortable — sorting an aggregate happens by SELECT alias,
 * which is the one part of the query that could plausibly behave differently on
 * Postgres and on the SQLite these tests run against.
 */
class AlbumsPageTest extends TestCase
{
    use RefreshDatabase;
    use SortsAListing;

    /**
     * An album with $tracks tracks, each of the given duration, all on one disc
     * unless $discs says otherwise. Everything the aggregates read is explicit,
     * because TrackFactory randomises duration / mtime / cover by design.
     */
    private function album(
        string $name,
        ?string $artist = null,
        array $attributes = [],
        int $tracks = 3,
        float $duration = 100.0,
        int $discs = 1,
        string $modifiedAt = '2020-10-08 09:45:52',
    ): Collection {
        $album = Collection::factory()->create(array_merge([
            'name' => $name,
            'album_artist_id' => $artist === null ? null : Artist::factory()->create(['name' => $artist])->id,
        ], $attributes));

        for ($i = 0; $i < $tracks; $i++) {
            Track::factory()->create([
                'collection_id' => $album->id,
                'duration' => $duration,
                'cover' => false,
                'disc' => $discs === 0 ? null : ($i % $discs) + 1,
                'track' => $i + 1,
                'modified_at' => $modifiedAt,
            ]);
        }

        return $album;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/music/albums')->assertRedirect('/login');
    }

    public function test_a_row_carries_the_aggregates_and_the_href_that_makes_it_clickable(): void
    {
        // Three × 90.5s, so the summed total is deliberately NOT a whole number: a
        // fractional sum is the only way to see that raw seconds went over rather than
        // something already rounded for display.
        $album = $this->album('Luciferian Towers', 'Godspeed You! Black Emperor', ['year' => 2017], tracks: 3, duration: 90.5);

        $this->actingAs(User::factory()->create())
            ->get('/music/albums')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Music/Albums/AlbumsPage')
                ->has('table.rows', 1)
                ->where('table.rows.0.name', 'Luciferian Towers')
                ->where('table.rows.0.artist', 'Godspeed You! Black Emperor')
                ->where('table.rows.0.year', 2017)
                ->where('table.rows.0.songs', 3)
                ->where('table.rows.0.discs', 1)
                // Raw seconds summed, not a clock string: the page's cell-duration
                // slot formats it (Utils/formatting.ts → formatClock).
                ->where('table.rows.0.duration', 271.5)
                // The newest file's mtime, as an ISO-8601 instant — the page renders
                // it in the viewer's own timezone.
                ->where('table.rows.0.modifiedAt', fn (?string $iso) => str_starts_with((string) $iso, '2020-10-08T09:45:52'))
                ->where('table.rows.0.href', "/music/albums/{$album->id}")
            );
    }

    public function test_audiobooks_stay_out_of_the_album_listing(): void
    {
        // `collections` is one table for all three container kinds (data-model.md →
        // "the collections half-step"), so the type scope is what keeps this listing
        // about music.
        $this->album('Luciferian Towers');
        Collection::factory()->audiobook()->create();

        $this->actingAs(User::factory()->create())
            ->get('/music/albums')
            ->assertInertia(fn (Assert $page) => $page->has('table.rows', 1));
    }

    public function test_a_multi_disc_set_reports_its_discs_and_an_untagged_rip_reports_one(): void
    {
        $double = $this->album('Mellon Collie', tracks: 4, discs: 2);
        $untagged = $this->album('No Disc Tags', tracks: 2, discs: 0);

        $this->actingAs(User::factory()->create())
            ->get('/music/albums?sort=discs&dir=desc')
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 2)
                ->where('table.rows.0.id', $double->id)
                ->where('table.rows.0.discs', 2)
                // COUNT(DISTINCT disc) counts 0 where no file carries a disc tag —
                // floored to 1, because "0 discs" reads as missing data and the album
                // is still one disc.
                ->where('table.rows.1.id', $untagged->id)
                ->where('table.rows.1.discs', 1)
            );
    }

    public function test_the_listing_opens_on_the_most_recently_changed_albums(): void
    {
        // The default sort, and the one column where "default" is a real decision: the
        // listing answers "what changed lately" before it answers "what starts with A".
        $older = $this->album('Aardvark', modifiedAt: '2019-01-02 03:04:05');
        $newer = $this->album('Zzyzx', modifiedAt: '2024-06-07 08:09:10');

        $this->actingAs(User::factory()->create())
            ->get('/music/albums')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Alphabetically these two are the wrong way round, which is the point:
                // Zzyzx leads because its files are newer.
                ->where('table.rows.0.id', $newer->id)
                ->where('table.rows.1.id', $older->id)
                ->where('table.sort.key', 'modifiedAt')
                ->where('table.sort.direction', 'desc')
                // And the name tiebreak is advertised here (it only is on the default
                // sort), so the header can show the compound "newest, then A–Z" order.
                ->where('table.tiebreakers', ['name'])
            );
    }

    public function test_albums_sharing_an_mtime_fall_back_to_a_stable_alphabetical_order(): void
    {
        // The reason the tiebreak exists: one bulk copy stamps a whole batch of files
        // with the same second, and without a trailing key SQL may order those rows
        // differently on each request — enough for one album to show up on two pages of
        // the same browse.
        $sameSecond = '2024-06-07 08:09:10';
        $this->album('Charlie', modifiedAt: $sameSecond);
        $this->album('Alpha', modifiedAt: $sameSecond);
        $this->album('Bravo', modifiedAt: $sameSecond);

        $this->actingAs(User::factory()->create())
            ->get('/music/albums')
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.name', 'Alpha')
                ->where('table.rows.1.name', 'Bravo')
                ->where('table.rows.2.name', 'Charlie')
            );
    }

    public function test_every_aggregate_column_can_be_sorted_by(): void
    {
        // A short album and a long one. EVERY sorted-on value differs between them,
        // including the two easiest to leave equal here — the disc count and the file
        // date — because a tie sorts stably, so a tied column would "not reverse" and
        // read as a broken sort when it is really a broken fixture. What the reversal
        // proves, and why it is asserted that way, is in the trait.
        $short = $this->album('Short', 'A Artist', ['year' => 1999], tracks: 1, duration: 60.0, discs: 1, modifiedAt: '2019-01-02 03:04:05');
        $long = $this->album('Long', 'B Artist', ['year' => 2020], tracks: 5, duration: 300.0, discs: 2, modifiedAt: '2024-06-07 08:09:10');

        $user = User::factory()->create();

        $this->assertEverySortableColumnReverses($user, '/music/albums', ['songs', 'duration', 'year', 'name', 'artist', 'modifiedAt', 'discs'], [$short->id, $long->id]);
    }

    public function test_search_covers_the_album_and_the_artist_and_ignores_accents(): void
    {
        $byName = $this->album('Badmotorfinger', 'Soundgarden');
        $byArtist = $this->album('Groza', 'Mgła');

        $user = User::factory()->create();

        // Album name, artist name, and the artist typed without its accent — the last
        // one is what the folded columns exist for (FoldedSearch).
        foreach (['Motorfinger' => $byName, 'Soundgar' => $byName, 'mgla' => $byArtist] as $term => $expected) {
            $this->actingAs($user)
                ->get('/music/albums?search='.urlencode((string) $term))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('table.rows', 1)
                    ->where('table.rows.0.id', $expected->id)
                );
        }
    }

    public function test_an_album_with_no_art_sends_no_cover_url(): void
    {
        // No media area on disk and no track carrying embedded art, so the row must
        // say null and let the table draw its placeholder rather than point an <img>
        // at a 404.
        $this->album('Luciferian Towers');

        $this->actingAs(User::factory()->create())
            ->get('/music/albums')
            ->assertInertia(fn (Assert $page) => $page->where('table.rows.0.coverUrl', null));
    }

    public function test_a_recorded_cover_path_is_what_makes_the_listing_link_a_thumbnail(): void
    {
        // The column the scanner writes, doing its job: no track here carries embedded
        // art and no filesystem is involved at all, so the recorded path is the only
        // thing that can produce a cover URL.
        $album = $this->album('Luciferian Towers');
        $album->update(['cover_path' => 'Godspeed You! Black Emperor/Luciferian Towers/folder.jpg']);

        $this->actingAs(User::factory()->create())
            ->get('/music/albums')
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.coverUrl', "/music/albums/{$album->id}/cover")
            );
    }

    public function test_an_album_whose_tracks_carry_embedded_art_links_its_cover(): void
    {
        $album = $this->album('Luciferian Towers');
        $album->tracks()->first()->update(['cover' => true]);

        $this->actingAs(User::factory()->create())
            ->get('/music/albums')
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.coverUrl', "/music/albums/{$album->id}/cover")
            );
    }
}
