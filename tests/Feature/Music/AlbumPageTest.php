<?php

namespace Tests\Feature\Music;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

    /**
     * Clear the cover cache around every test, because one assertion here is about its ABSENCE.
     *
     * `storage_path('app/private/covers')` is the real application directory, not a sandbox, so
     * without this the "the page extracted no cover" assertion below is really measuring whatever
     * else has run recently — five sibling files clear it in their own teardown, and this one was
     * free-riding on them. Serve a single cover from a local dev server and it fails, pointing at
     * the album page when nothing of the sort happened.
     */
    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(storage_path('app/private/covers'));
    }

    /** Leave no cached cover behind for the next file, for the reason setUp gives. */
    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/covers'));

        parent::tearDown();
    }

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
                // The hero's one tile that leads somewhere — decided server-side, like a
                // table row's `href`, so the page links the name only when handed a URL.
                ->where('album.artistUrl', '/music/artists/'.$album->album_artist_id)
                ->where('album.year', 1995)
                ->where('album.songs', 3)
                ->where('album.discs', 2)
                ->where('album.duration', 271.5)
                // The NEWEST file's mtime, not the first one's.
                ->where('album.modifiedAt', fn (?string $iso) => str_starts_with((string) $iso, '2024-06-07T08:09:10'))
                ->where('album.coverUrl', null)
            );
    }

    public function test_a_compilation_filed_under_no_album_artist_gets_no_artist_link(): void
    {
        // `album_artist_id` is nullable — a various-artists compilation is filed under
        // none — so there is nobody to link to, and the hero must render the tile away
        // rather than point at a route with an empty parameter.
        $album = Collection::factory()->create(['album_artist_id' => null]);

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('album.artist', null)
                ->where('album.artistUrl', null)
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

    public function test_the_hero_carries_the_albums_main_genre_and_a_link_to_it(): void
    {
        // The DOMINANT genre, not every genre the album's tracks graze — the same rule that
        // decides which genre page lists this record, so following the tile lands somewhere
        // that really does claim it.
        $album = Collection::factory()->create();
        $mostly = Genre::factory()->create(['name' => 'Doom']);
        $incidental = Genre::factory()->create(['name' => 'Polka']);

        Track::factory()->count(4)->create(['collection_id' => $album->id, 'genre_id' => $mostly->id]);
        Track::factory()->create(['collection_id' => $album->id, 'genre_id' => $incidental->id]);

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('album.genre', 'Doom')
                ->where('album.genreUrl', "/music/genres/{$mostly->id}")
            );
    }

    public function test_an_album_whose_tracks_carry_no_genre_gets_no_genre_tile(): void
    {
        // Null in, null out: the tile is dropped rather than rendered empty.
        $album = Collection::factory()->create();
        Track::factory()->count(2)->create(['collection_id' => $album->id, 'genre_id' => null]);

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('album.genre', null)
                ->where('album.genreUrl', null)
            );
    }

    public function test_a_track_row_carries_the_denominators_behind_its_disc_and_track(): void
    {
        // What lets the listing print "1/2" and "3/12": how many discs the album has, and how
        // long the row's OWN disc is. Two discs of different lengths, so a single shared number
        // could not satisfy both rows.
        //
        // Numbered explicitly, because the factory numbers a track at random: the denominator is
        // the greater of the file count and the highest number (see the next test), so a fixture
        // of three files numbered 4, 9 and 12 would be asserting the random number rather than
        // the per-disc grouping this test is about.
        $album = Collection::factory()->create();

        foreach ([1, 2, 3] as $number) {
            Track::factory()->create(['collection_id' => $album->id, 'disc' => 1, 'track' => $number]);
        }
        foreach ([1, 2] as $number) {
            Track::factory()->create(['collection_id' => $album->id, 'disc' => 2, 'track' => $number]);
        }

        $response = $this->actingAs(User::factory()->create())->get("/music/albums/{$album->id}");
        $rows = collect($this->inertiaProp($response, 'table.rows'));

        $this->assertSame(2, $rows->first()['discTotal'], 'the album has two discs');
        $this->assertSame(3, $rows->firstWhere('disc', 1)['trackTotal'], 'disc one holds three');
        $this->assertSame(2, $rows->firstWhere('disc', 2)['trackTotal'], 'disc two holds two');
    }

    public function test_an_album_missing_a_track_says_so_rather_than_printing_a_fraction_over_one(): void
    {
        // A rip missing one track of ten holds nine files, and a denominator that counts FILES
        // renders its last row as "10/9" — a fraction bigger than one, which reads as broken data
        // (it was reported as exactly that). The album's own numbering is the better evidence of
        // how long it is: the tag's "of N" total is not stored at all (Id3TagReader keeps the
        // position alone), so the highest number is all there is to go on.
        $album = Collection::factory()->create();

        foreach ([1, 2, 3, 5] as $number) {
            Track::factory()->create(['collection_id' => $album->id, 'disc' => 1, 'track' => $number]);
        }

        $response = $this->actingAs(User::factory()->create())->get("/music/albums/{$album->id}");
        $rows = collect($this->inertiaProp($response, 'table.rows'));

        // Five, not four: four files that number up to five are four fifths of an album.
        $this->assertSame(5, $rows->first()['trackTotal']);
        $this->assertSame([1, 2, 3, 5], $rows->pluck('track')->all());
    }

    public function test_more_files_than_the_numbering_reaches_keeps_the_file_count(): void
    {
        // The other direction, and it must not go backwards: two files sharing track 1 number no
        // higher than 2, so the denominator stays the count. Rare but real — a reissue whose
        // bonus disc claims disc 1 (measured: four albums in the live library).
        $album = Collection::factory()->create();

        foreach ([1, 1, 2] as $number) {
            Track::factory()->create(['collection_id' => $album->id, 'disc' => 1, 'track' => $number]);
        }

        $response = $this->actingAs(User::factory()->create())->get("/music/albums/{$album->id}");

        $this->assertSame(3, collect($this->inertiaProp($response, 'table.rows'))->first()['trackTotal']);
    }

    public function test_untagged_discs_are_counted_together_rather_than_as_none(): void
    {
        // The NULL-safe join in the track_total subquery. `sib.disc = tracks.disc` matches
        // nothing when both are NULL, which would report 0 tracks for a whole album of
        // untagged files — a denominator of 0 beside a real track number.
        $album = Collection::factory()->create();

        foreach ([1, 2, 3] as $number) {
            Track::factory()->create(['collection_id' => $album->id, 'disc' => null, 'track' => $number]);
        }

        $response = $this->actingAs(User::factory()->create())->get("/music/albums/{$album->id}");
        $rows = collect($this->inertiaProp($response, 'table.rows'));

        $this->assertSame(3, $rows->first()['trackTotal']);
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
        $artist = Artist::factory()->create(['name' => 'Sonic Youth']);
        $track = Track::factory()->create([
            'collection_id' => $album->id,
            'name' => 'Teen Age Riot',
            'artist_id' => $artist->id,
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
                // And the one cell that goes somewhere ELSE: the performer, not the song.
                ->where('table.rows.0.artistUrl', "/music/artists/{$artist->id}")
            );
    }

    public function test_each_track_links_its_own_performer_and_a_track_crediting_nobody_gets_no_link(): void
    {
        // The case the artist column exists for, and the case the link exists for: a
        // compilation where every track is a different performer, so the cell is a
        // per-row destination rather than a repeat of the hero's album-artist.
        $album = Collection::factory()->create(['album_artist_id' => null]);

        $first = Artist::factory()->create(['name' => 'Dinosaur Jr.']);
        $second = Artist::factory()->create(['name' => 'Mudhoney']);

        Track::factory()->create(['collection_id' => $album->id, 'artist_id' => $first->id, 'disc' => 1, 'track' => 1]);
        Track::factory()->create(['collection_id' => $album->id, 'artist_id' => $second->id, 'disc' => 1, 'track' => 2]);
        // Untagged performer: the cell must stay plain text rather than link nowhere.
        Track::factory()->create(['collection_id' => $album->id, 'artist_id' => null, 'disc' => 1, 'track' => 3]);

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.artistUrl', "/music/artists/{$first->id}")
                ->where('table.rows.1.artistUrl', "/music/artists/{$second->id}")
                ->where('table.rows.2.artist', null)
                ->where('table.rows.2.artistUrl', null)
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
