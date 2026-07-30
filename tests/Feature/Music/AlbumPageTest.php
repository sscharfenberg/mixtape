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
 * One album's detail page (`/music/albums/{album}`, behind auth) — where the Albums
 * listing's rows lead.
 *
 * Two halves to cover. The container's own facts: its totals (which must agree with what
 * the listing reports for the same album), the type guard keeping the other two
 * collection kinds off this route, and a cover URL decided without extracting anything.
 * Then its TRACK TABLE: the album's running order (disc, then track — the reason
 * DataTableService grew tiebreakers), the raw values each row carries, and that a
 * user-chosen sort or search still only ever sees this album's tracks.
 */
class AlbumPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $album = Collection::factory()->create();

        $this->get("/music/albums/{$album->id}")->assertRedirect('/login');
    }

    public function test_it_renders_the_album_with_its_container_totals(): void
    {
        $album = Collection::factory()->create([
            'name' => 'Mellon Collie and the Infinite Sadness',
            'year' => 1995,
            'album_artist_id' => Artist::factory()->create(['name' => 'The Smashing Pumpkins'])->id,
        ]);

        // Two discs, three tracks, a fractional total — the same shape the listing's
        // aggregates are asserted on, so the two pages can be compared by eye.
        foreach ([[1, 100.5], [1, 100.0], [2, 71.0]] as $index => [$disc, $duration]) {
            Track::factory()->create([
                'collection_id' => $album->id,
                'disc' => $disc,
                'track' => $index + 1,
                'duration' => $duration,
                'cover' => false,
                'modified_at' => $index === 2 ? '2024-06-07 08:09:10' : '2019-01-02 03:04:05',
            ]);
        }

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Music/Albums/Album/AlbumPage')
                ->where('album.name', 'Mellon Collie and the Infinite Sadness')
                ->where('album.artist', 'The Smashing Pumpkins')
                ->where('album.year', 1995)
                ->where('album.songs', 3)
                ->where('album.discs', 2)
                ->where('album.duration', 271.5)
                // The NEWEST file's mtime, not the first one's.
                ->where('album.modifiedAt', fn (?string $iso) => str_starts_with((string) $iso, '2024-06-07T08:09:10'))
                ->where('album.coverUrl', null)
            );
    }

    public function test_an_untagged_rip_still_reports_one_disc(): void
    {
        $album = Collection::factory()->create();
        Track::factory()->count(2)->create(['collection_id' => $album->id, 'disc' => null, 'cover' => false]);

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertInertia(fn (Assert $page) => $page->where('album.discs', 1));
    }

    public function test_an_album_with_no_tracks_left_does_not_break_the_page(): void
    {
        // The scanner prunes empty collections, but a page must not 500 in the window
        // where one exists — sum() over no rows is null, count() is 0.
        $album = Collection::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('album.songs', 0)
                ->where('album.discs', 1)
                ->where('album.duration', null)
                ->where('album.modifiedAt', null)
                ->where('album.coverUrl', null)
            );
    }

    public function test_the_track_table_is_ordered_by_disc_then_track(): void
    {
        // The album's running order, and the reason DataTableService grew tiebreakers: the
        // frontend can only ask for ONE sort key, so "disc" alone would leave the tracks
        // within a disc in whatever order the engine felt like. Inserted deliberately
        // scrambled, so passing means the ORDER BY did the work and not the insert order.
        $album = Collection::factory()->create();

        foreach ([[2, 1, 'Disc two, first'], [1, 2, 'Disc one, second'], [2, 2, 'Disc two, second'], [1, 1, 'Disc one, first']] as [$disc, $track, $name]) {
            Track::factory()->create([
                'collection_id' => $album->id,
                'disc' => $disc,
                'track' => $track,
                'name' => $name,
                'cover' => false,
            ]);
        }

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 4)
                ->where('table.sort.key', 'disc')
                ->where('table.rows.0.name', 'Disc one, first')
                ->where('table.rows.1.name', 'Disc one, second')
                ->where('table.rows.2.name', 'Disc two, first')
                ->where('table.rows.3.name', 'Disc two, second')
            );
    }

    public function test_the_table_advertises_its_natural_order_only_while_it_is_in_it(): void
    {
        // What lets the header mark CD *and* Track as sorted: in the default view the extra
        // keys ARE the order being read, so they are reported (minus the primary itself).
        $album = Collection::factory()->create();
        Track::factory()->count(2)->create(['collection_id' => $album->id]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get("/music/albums/{$album->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.sort.key', 'disc')
                ->where('table.tiebreakers', ['track', 'name'])
            );

        // Under a chosen sort they are NOT reported, though they still order the query.
        // Marking four columns ascending when the reader picked one makes the marking mean
        // less, and disc/track almost never separates two rows of distinct durations.
        $this->actingAs($user)
            ->get("/music/albums/{$album->id}?sort=duration&dir=desc")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.sort.key', 'duration')
                ->where('table.tiebreakers', [])
            );
    }

    public function test_the_tiebreakers_still_order_the_query_under_a_chosen_sort(): void
    {
        // The half that must NOT follow the marking: not advertising them cannot mean not
        // applying them, or paging would stop being deterministic the moment a reader
        // sorts by anything. Two tracks with the SAME duration, so only the disc/track
        // tiebreak can decide their order.
        $album = Collection::factory()->create();
        $second = Track::factory()->create([
            'collection_id' => $album->id, 'name' => 'Second', 'disc' => 1, 'track' => 2, 'duration' => 200.0,
        ]);
        $first = Track::factory()->create([
            'collection_id' => $album->id, 'name' => 'First', 'disc' => 1, 'track' => 1, 'duration' => 200.0,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}?sort=duration&dir=desc")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.tiebreakers', [])
                ->where('table.rows.0.id', $first->id)
                ->where('table.rows.1.id', $second->id)
            );
    }

    public function test_a_track_row_carries_its_raw_values_and_a_link_to_the_song(): void
    {
        $album = Collection::factory()->create();
        $track = Track::factory()->create([
            'collection_id' => $album->id,
            'name' => 'Teen Age Riot',
            'artist_id' => Artist::factory()->create(['name' => 'Sonic Youth'])->id,
            'disc' => 1,
            'track' => 1,
            'duration' => 419.5,
            'size' => 16_785_408,
            'cover' => true,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.name', 'Teen Age Riot')
                ->where('table.rows.0.artist', 'Sonic Youth')
                ->where('table.rows.0.disc', 1)
                ->where('table.rows.0.track', 1)
                // Raw seconds and raw bytes — the page clocks and humanises them.
                ->where('table.rows.0.duration', 419.5)
                ->where('table.rows.0.size', 16_785_408)
                // The track's OWN embedded art, from the scan-time flag with no
                // filesystem access.
                ->where('table.rows.0.coverUrl', "/music/songs/{$track->id}/cover")
                // What makes the row clickable, and where it goes.
                ->where('table.rows.0.href', "/music/songs/{$track->id}")
            );
    }

    public function test_a_track_with_no_embedded_art_gets_no_cover_url(): void
    {
        // Deliberately NOT falling back to the album's directory image: that one is the
        // hero right above the table, and repeating it down every row says nothing about
        // the track. The page draws its placeholder instead.
        $album = Collection::factory()->create(['cover_path' => 'Some Artist/Some Album/folder.jpg']);
        Track::factory()->create(['collection_id' => $album->id, 'cover' => false]);

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertInertia(fn (Assert $page) => $page->where('table.rows.0.coverUrl', null));
    }

    public function test_the_track_table_can_be_sorted_and_searched(): void
    {
        $album = Collection::factory()->create();
        $short = Track::factory()->create([
            'collection_id' => $album->id, 'name' => 'Providence', 'duration' => 145.0, 'disc' => 1, 'track' => 9,
        ]);
        $long = Track::factory()->create([
            'collection_id' => $album->id, 'name' => 'Trilogy', 'duration' => 800.0, 'disc' => 1, 'track' => 10,
        ]);

        $user = User::factory()->create();

        // A user-chosen sort must beat the default disc order…
        $this->actingAs($user)
            ->get("/music/albums/{$album->id}?sort=duration&dir=desc")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.id', $long->id)
                ->where('table.rows.1.id', $short->id)
            );

        // …and search narrows to this album's own tracks. `assertOk` before `assertInertia`
        // on purpose: a 500 here reports as the unhelpful "Not a valid Inertia response",
        // which is exactly how this test's first version hid a TypeError in the search
        // callback (a HasMany where FoldedSearch wanted a Builder).
        $this->actingAs($user)
            ->get("/music/albums/{$album->id}?search=Providence")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 1)
                ->where('table.rows.0.id', $short->id)
            );
    }

    public function test_another_albums_tracks_stay_out_of_the_table(): void
    {
        $album = Collection::factory()->create();
        Track::factory()->count(2)->create(['collection_id' => $album->id]);
        Track::factory()->count(3)->create(['collection_id' => Collection::factory()->create()->id]);

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 2)
                ->where('table.total', 2)
            );
    }

    public function test_an_audiobook_is_not_reachable_as_an_album(): void
    {
        $audiobook = Collection::factory()->audiobook()->create();

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$audiobook->id}")
            ->assertNotFound();
    }

    public function test_the_cover_url_is_sent_without_extracting_anything(): void
    {
        $album = Collection::factory()->create();
        Track::factory()->create(['collection_id' => $album->id, 'cover' => true]);

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('album.coverUrl', "/music/albums/{$album->id}/cover")
            );

        // The page only LINKS the cover; nothing may have been decoded or cached yet.
        $this->assertDirectoryDoesNotExist(storage_path('app/private/covers'));
    }
}
