<?php

namespace Tests\Feature\Music;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * One artist's detail page (`/music/artists/{artist}`, behind auth) — where a row of the
 * Artists listing leads, and where the artist tile on a song or album page goes.
 *
 * The numbers are the listing's, computed the same way and asserted there; what is new
 * here — and what most of these tests are about — is the DOMINANT GENRE. It is derived,
 * not stored (MixTape tags genre per track, and an artist may vary it), so the page's one
 * genuinely new claim is "the genre most of their songs carry", including how it behaves
 * on a tie and when there is nothing to derive it from.
 */
class ArtistPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * $count music tracks by $artist in $genre, with duration and size pinned — the
     * aggregates sum exactly those columns and TrackFactory randomises both.
     */
    private function tracks(Artist $artist, ?Genre $genre, int $count, float $duration = 100.0, int $size = 1_000_000): void
    {
        Track::factory()->count($count)->create([
            'artist_id' => $artist->id,
            'genre_id' => $genre?->id,
            'duration' => $duration,
            'size' => $size,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $artist = Artist::factory()->create();

        $this->get("/music/artists/{$artist->id}")->assertRedirect('/login');
    }

    public function test_the_page_carries_the_artists_name_the_album_count_and_the_totals(): void
    {
        $artist = Artist::factory()->create(['name' => 'Sonic Youth']);

        // Three albums of their own, tracks on only one of them, plus a track on someone
        // else's compilation — the same fixture shape as the listing's row test, and for
        // the same reason: only the DISCOGRAPHY reading of "albums" gives 3.
        $own = Collection::factory()->count(3)->create(['album_artist_id' => $artist->id]);
        $guest = Collection::factory()->create(['album_artist_id' => null]);

        Track::factory()->create([
            'artist_id' => $artist->id, 'collection_id' => $own->first()->id,
            'duration' => 100.5, 'size' => 4_000_000,
        ]);
        Track::factory()->create([
            'artist_id' => $artist->id, 'collection_id' => $own->first()->id,
            'duration' => 100.0, 'size' => 5_000_000,
        ]);
        Track::factory()->create([
            'artist_id' => $artist->id, 'collection_id' => $guest->id,
            'duration' => 71.0, 'size' => 6_000_000,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Music/Artists/Artist/ArtistPage')
                ->where('artist.id', $artist->id)
                ->where('artist.name', 'Sonic Youth')
                ->where('artist.albums', 3)
                ->where('artist.songs', 3)
                // Raw seconds and raw bytes — fractional on purpose, so it is visible that
                // the sum went over unrounded for the page to clock and humanise.
                ->where('artist.duration', 271.5)
                ->where('artist.size', 15_000_000)
            );
    }

    public function test_the_genre_shown_is_the_one_most_of_their_songs_carry(): void
    {
        // The page's one derived fact. This artist varies genre across their catalogue —
        // the tile summarises rather than picking whichever row the engine returned first.
        $artist = Artist::factory()->create(['name' => 'Ulver']);

        $ambient = Genre::factory()->create(['name' => 'Ambient']);

        $this->tracks($artist, Genre::factory()->create(['name' => 'Black Metal']), 2);
        $this->tracks($artist, $ambient, 5);
        $this->tracks($artist, Genre::factory()->create(['name' => 'Folk']), 1);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Not "Black Metal" (what they are known for) and not "Folk" (the first
                // one alphabetically) — the one five of the eight files are tagged with.
                ->where('artist.genre', 'Ambient')
                // And it LEADS to that genre's page — the tile is the link, so the URL is
                // the feature. Pointing at Ambient, not at whichever genre came first.
                ->where('artist.genreUrl', "/music/genres/{$ambient->id}")
            );
    }

    public function test_a_genre_tie_breaks_on_the_genre_name_so_the_page_is_stable(): void
    {
        // Half and half. SQL orders tied groups arbitrarily, so without the second ORDER BY
        // this page could show a DIFFERENT genre on each reload — which reads as a bug in
        // the app rather than as an artist with two equal halves.
        $artist = Artist::factory()->create(['name' => 'John Zorn']);

        $this->tracks($artist, Genre::factory()->create(['name' => 'Jazz']), 4);
        $this->tracks($artist, Genre::factory()->create(['name' => 'Ambient']), 4);

        $user = User::factory()->create();

        foreach (range(1, 3) as $attempt) {
            $this->actingAs($user)
                ->get("/music/artists/{$artist->id}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('artist.genre', 'Ambient'));
        }
    }

    public function test_an_artist_whose_files_carry_no_genre_shows_none(): void
    {
        // `genre_id` is nullable, and an untagged catalogue must drop the tile rather than
        // read "unknown" — so the whole-genre subquery has to survive having nothing to
        // group.
        $artist = Artist::factory()->create(['name' => 'Untagged Rip']);
        $this->tracks($artist, null, 3);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('artist.songs', 3)
                ->where('artist.genre', null)
            );
    }

    public function test_a_credited_compilation_owner_with_no_tracks_reports_zeroes_and_no_genre(): void
    {
        // The artist kind that has a discography and no songs at all: every track on its
        // album credits the individual performers. The page must not fall over on the empty
        // aggregates — and 0 here is an answer, not missing data (the tiles still render).
        $owner = Artist::factory()->create(['name' => 'Irish Folk Festival']);
        $album = Collection::factory()->create(['album_artist_id' => $owner->id]);

        Track::factory()->create([
            'artist_id' => Artist::factory()->create(['name' => 'Tommy Peoples'])->id,
            'collection_id' => $album->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$owner->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('artist.albums', 1)
                ->where('artist.songs', 0)
                // COALESCEd, so the page clocks "0:00" instead of dropping the tile — and
                // asserted as ints, since a whole-number float crosses the wire as `0`.
                ->where('artist.duration', 0)
                ->where('artist.size', 0)
                ->where('artist.genre', null)
            );
    }

    public function test_the_discography_lists_their_credited_albums_newest_first(): void
    {
        $artist = Artist::factory()->create();

        Collection::factory()->create(['album_artist_id' => $artist->id, 'name' => 'Third', 'year' => 2007]);
        Collection::factory()->create(['album_artist_id' => $artist->id, 'name' => 'First', 'year' => 1994]);
        Collection::factory()->create(['album_artist_id' => $artist->id, 'name' => 'Second', 'year' => 2001]);
        // Somebody else's album — it must not appear, since the tab is the artist's
        // DISCOGRAPHY (what they are credited with), not every album they turn up on.
        Collection::factory()->create(['album_artist_id' => Artist::factory()->create()->id]);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('discography', 3)
                // Newest first — the reverse of the order they were created in above.
                ->where('discography.0.name', 'Third')
                ->where('discography.1.name', 'Second')
                ->where('discography.2.name', 'First')
                // The tab and the hero tile count the same relation, so they can never
                // disagree about how many albums this artist has.
                ->where('artist.albums', 3)
            );
    }

    public function test_the_hero_fans_up_to_three_covers_one_per_album(): void
    {
        // The artist has no photograph — MixTape stores none — so the hero shows a few of
        // their own sleeves instead. One per ALBUM and never the same one twice, which is what
        // stops a fan reading as a rendering fault.
        $artist = Artist::factory()->create();
        Collection::factory()->count(5)->create(['album_artist_id' => $artist->id, 'cover_path' => '/art.jpg']);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $page->has('covers', 3);

                $covers = $page->toArray()['props']['covers'];
                $this->assertSame($covers, array_values(array_unique($covers)));
            });
    }

    public function test_a_one_album_artist_fans_a_single_cover_rather_than_three(): void
    {
        // Never padded — and this is the COMMON card rather than an edge case: half the
        // artists in this collection have exactly one album.
        $artist = Artist::factory()->create();
        Collection::factory()->create(['album_artist_id' => $artist->id, 'cover_path' => '/art.jpg']);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertInertia(fn (Assert $page) => $page->has('covers', 1));
    }

    public function test_albums_with_no_artwork_are_left_out_of_the_fan(): void
    {
        // Dropped rather than fanned as placeholders. An artist whose records ALL lack artwork
        // sends nothing, which the page renders as one placeholder sleeve.
        $artist = Artist::factory()->create();
        Collection::factory()->count(2)->create(['album_artist_id' => $artist->id, 'cover_path' => null]);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertInertia(fn (Assert $page) => $page->has('covers', 0));
    }

    public function test_an_album_with_no_year_sorts_last_rather_than_leading_the_discography(): void
    {
        // The ordering case a single engine cannot prove: Postgres and SQLite disagree on
        // where NULLs land by default, so the controller sorts on an explicit CASE flag.
        // Without it an untagged rip would head the list on one engine and tail it on the
        // other — and the suite runs on SQLite, so only asserting the intent catches it.
        $artist = Artist::factory()->create();

        Collection::factory()->create(['album_artist_id' => $artist->id, 'name' => 'Untagged', 'year' => null]);
        Collection::factory()->create(['album_artist_id' => $artist->id, 'name' => 'Dated', 'year' => 1999]);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('discography.0.name', 'Dated')
                ->where('discography.1.name', 'Untagged')
                ->where('discography.1.year', null)
            );
    }

    public function test_each_discography_row_totals_its_own_tracks_and_links_to_the_album(): void
    {
        $artist = Artist::factory()->create();
        $album = Collection::factory()->create(['album_artist_id' => $artist->id, 'cover_path' => null]);

        Track::factory()->create([
            'artist_id' => $artist->id, 'collection_id' => $album->id, 'duration' => 120.5, 'cover' => false,
        ]);
        Track::factory()->create([
            'artist_id' => $artist->id, 'collection_id' => $album->id, 'duration' => 121.0, 'cover' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('discography.0.songs', 2)
                // Raw seconds, and deliberately summing to a FRACTION: a whole-number float
                // crosses the wire as an int, so only a fractional total actually shows that
                // the sum went over unrounded for the page to clock.
                ->where('discography.0.duration', 241.5)
                ->where('discography.0.href', "/music/albums/{$album->id}")
                // No directory image and no file claiming embedded art, so the row is handed
                // no URL at all rather than one that would 404 (the page draws a placeholder).
                ->where('discography.0.coverUrl', null)
            );
    }

    public function test_the_songs_tab_holds_only_this_artists_music_and_links_each_row_to_its_album(): void
    {
        $artist = Artist::factory()->create();
        $album = Collection::factory()->create(['album_artist_id' => $artist->id, 'name' => 'Loveless']);

        Track::factory()->create([
            'artist_id' => $artist->id, 'collection_id' => $album->id, 'name' => 'Only Shallow',
        ]);
        // Another artist's track on the same album — the tab is by ARTIST, not by album.
        Track::factory()->create([
            'artist_id' => Artist::factory()->create()->id, 'collection_id' => $album->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 1)
                ->where('table.rows.0.name', 'Only Shallow')
                ->where('table.rows.0.album', 'Loveless')
                // Two destinations in one row: the row opens the song, the album cell the
                // album. Both are asserted because the cell is only a link when it is given
                // a URL, and a null here would silently turn it into plain text.
                ->where('table.rows.0.albumUrl', "/music/albums/{$album->id}")
                ->where('table.rows.0.href', '/music/songs/'.$page->toArray()['props']['table']['rows'][0]['id'])
            );
    }

    public function test_the_songs_tab_defaults_to_newest_year_then_album_then_disc_then_track(): void
    {
        // The fixture is built so each level of the order can only be satisfied by that
        // level: the album NAMES run opposite to their years, so a table sorted by album
        // name would come back in a different order than one sorted by year; and the tracks
        // are created out of sequence, so insertion order proves nothing either.
        $artist = Artist::factory()->create();

        $zebra = Collection::factory()->create(['album_artist_id' => $artist->id, 'name' => 'Zebra', 'year' => 1990]);
        $aard = Collection::factory()->create(['album_artist_id' => $artist->id, 'name' => 'Aardvark', 'year' => 1990]);
        $alpha = Collection::factory()->create(['album_artist_id' => $artist->id, 'name' => 'Alpha', 'year' => 2010]);

        $song = function (Collection $album, int $disc, int $track, string $name) use ($artist): void {
            Track::factory()->create([
                'artist_id' => $artist->id, 'collection_id' => $album->id,
                'disc' => $disc, 'track' => $track, 'name' => $name,
            ]);
        };

        // Deliberately scrambled on the way in.
        $song($alpha, 1, 1, 'alpha-1-1');
        $song($zebra, 2, 1, 'zebra-2-1');
        $song($zebra, 1, 2, 'zebra-1-2');
        $song($aard, 1, 1, 'aardvark-1-1');
        $song($zebra, 1, 1, 'zebra-1-1');

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.sort.key', 'year')
                ->where('table.sort.direction', 'desc')
                // Echoed back so the header can mark all four as sorted, not just `year`.
                ->where('table.tiebreakers', ['album', 'disc', 'track', 'name'])
                // ONLY the year reverses. 2010 leads, then the two 1990 albums A–Z, and
                // inside Zebra the discs and tracks still climb — which is the whole point
                // of the tiebreakers staying ascending.
                ->where('table.rows.0.name', 'alpha-1-1')      // 2010 — newest first
                ->where('table.rows.1.name', 'aardvark-1-1')   // 1990, album A…
                ->where('table.rows.2.name', 'zebra-1-1')      // 1990, album Z, disc 1 track 1
                ->where('table.rows.3.name', 'zebra-1-2')      // …then track 2, NOT reversed
                ->where('table.rows.4.name', 'zebra-2-1')      // …then disc 2
            );
    }

    public function test_an_undated_album_sorts_last_in_the_songs_tab_rather_than_leading_it(): void
    {
        // The whole reason the controller sorts on a COALESCEd alias instead of the raw
        // column. The default is DESCENDING and Postgres puts NULLs FIRST under DESC, so on
        // the raw column an artist with one untagged rip would open their songs tab on that
        // rip instead of on their newest record — GenresController's trap, same fix.
        //
        // Worth asserting on SQLite precisely BECAUSE of the coalesce: sorting on a column
        // that is never null is the thing that makes the two engines agree, so this test
        // means in production what it means here. Without it, it would only have proved
        // SQLite's own null placement.
        $artist = Artist::factory()->create();
        $dated = Collection::factory()->create(['album_artist_id' => $artist->id, 'year' => 2001]);
        $undated = Collection::factory()->create(['album_artist_id' => $artist->id, 'year' => null]);

        Track::factory()->create([
            'artist_id' => $artist->id, 'collection_id' => $undated->id,
            'disc' => 1, 'track' => 1, 'name' => 'undated-song',
        ]);
        Track::factory()->create([
            'artist_id' => $artist->id, 'collection_id' => $dated->id,
            'disc' => 1, 'track' => 1, 'name' => 'dated-song',
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.name', 'dated-song')
                ->where('table.rows.1.name', 'undated-song')
                // The row still reports the real year — only the SORT folds null to 0.
                ->where('table.rows.1.year', null)
            );
    }

    public function test_the_songs_tab_carries_the_disc_and_track_numbers_with_their_totals(): void
    {
        // A two-disc album: disc 1 holds three tracks, disc 2 holds one. The page renders
        // these as "1/2" and "3/3", so both denominators have to be per-SET, not per-album —
        // discTotal counts the album's discs, trackTotal only the row's own disc.
        $artist = Artist::factory()->create();
        $album = Collection::factory()->create(['album_artist_id' => $artist->id]);

        foreach ([[1, 1], [1, 2], [1, 3], [2, 1]] as [$disc, $track]) {
            Track::factory()->create([
                'artist_id' => $artist->id, 'collection_id' => $album->id,
                'disc' => $disc, 'track' => $track, 'name' => "d{$disc}t{$track}",
            ]);
        }

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.disc', 1)
                ->where('table.rows.0.discTotal', 2)
                ->where('table.rows.0.track', 1)
                // Disc 1's own count, NOT the album's four tracks.
                ->where('table.rows.0.trackTotal', 3)
                // The lone track on disc 2 reads "1/1", not "1/3".
                ->where('table.rows.3.disc', 2)
                ->where('table.rows.3.trackTotal', 1)
            );
    }

    public function test_untagged_discs_are_counted_together_rather_than_reporting_zero(): void
    {
        // The NULL-safety in the trackTotal subquery. `sib.disc = tracks.disc` matches
        // nothing when both are NULL, so a plain equality would report a total of 0 for a
        // whole album of files that simply carry no disc tag — and the page would print a
        // bare "2" where it could say "2/3".
        $artist = Artist::factory()->create();
        $album = Collection::factory()->create(['album_artist_id' => $artist->id]);

        foreach ([1, 2, 3] as $track) {
            Track::factory()->create([
                'artist_id' => $artist->id, 'collection_id' => $album->id,
                'disc' => null, 'track' => $track, 'name' => "t{$track}",
            ]);
        }

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.trackTotal', 3)
                ->where('table.rows.0.disc', null)
                // COUNT(DISTINCT disc) skips NULLs, so an album nobody tagged reports 0
                // discs — and with `disc` null the cell renders blank either way.
                ->where('table.rows.0.discTotal', 0)
            );
    }

    public function test_a_track_filed_under_no_album_reports_no_totals(): void
    {
        // With no container there is nothing to count against, so both totals are null and
        // the page prints the bare numbers rather than "2/0".
        $artist = Artist::factory()->create();

        Track::factory()->create([
            'artist_id' => $artist->id, 'collection_id' => null, 'disc' => 1, 'track' => 2,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.track', 2)
                ->where('table.rows.0.trackTotal', null)
                ->where('table.rows.0.discTotal', null)
            );
    }

    public function test_both_panels_are_sent_whatever_the_tab_param_says(): void
    {
        // `?tab=` is the frontend's own state, written into the URL so a reload reopens the
        // right tab (useTabParam). The obvious saving — answer only the open tab — is the
        // wrong trade, because then every tab click needs a request and a spinner over
        // content already on screen. This pins that: both panels come back regardless, and
        // an unknown tab changes nothing either.
        $artist = Artist::factory()->create();
        Collection::factory()->create(['album_artist_id' => $artist->id]);
        Track::factory()->create(['artist_id' => $artist->id]);

        foreach (['', '?tab=albums', '?tab=songs', '?tab=nonsense'] as $query) {
            $this->actingAs(User::factory()->create())
                ->get("/music/artists/{$artist->id}{$query}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('discography', 1)
                    ->has('table.rows', 1)
                );
        }
    }

    public function test_the_songs_tab_is_the_only_thing_reading_the_pages_sort_params(): void
    {
        // The page deliberately carries ONE server-driven table, because DataTableService
        // reads unprefixed sort/dir/page/search and two tables here would drive each other
        // from the same params. This pins that contract: `sort` moves the songs table, and
        // the discography — a plain array, not a TableResponse — is untouched by it.
        $artist = Artist::factory()->create();

        Collection::factory()->create(['album_artist_id' => $artist->id, 'name' => 'Early', 'year' => 1990]);
        Collection::factory()->create(['album_artist_id' => $artist->id, 'name' => 'Late', 'year' => 2010]);

        Track::factory()->create(['artist_id' => $artist->id, 'name' => 'Alpha']);
        Track::factory()->create(['artist_id' => $artist->id, 'name' => 'Omega']);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}?sort=name&dir=desc")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.sort.key', 'name')
                ->where('table.sort.direction', 'desc')
                ->where('table.rows.0.name', 'Omega')
                // Still newest-first: the discography has no sort to be hijacked.
                ->where('discography.0.name', 'Late')
                ->where('discography.1.name', 'Early')
            );
    }
}
