<?php

namespace Tests\Feature\Music;

use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * One genre's detail page (`/music/genres/{genre}`, behind auth) — where a row of the
 * Genres listing leads, and where the genre tile on an artist's page goes.
 *
 * The numbers are the listing's, and the interesting one is the same: how many artists
 * call this their MAIN genre. What is new here is WHERE that filter sits. The listing
 * ranks every artist's genres and groups the winners; this page has to rank them the same
 * way and only then keep the ones this genre won. Filtering to the genre any earlier would
 * hand the page artists who merely dabble in it — which is what the second test below is
 * for, and the one bug this page can plausibly have.
 */
class GenrePageTest extends TestCase
{
    use RefreshDatabase;

    /** $count music tracks by $artist in $genre, with duration and size pinned. */
    private function tracks(?Artist $artist, Genre $genre, int $count, float $duration = 100.0, int $size = 1_000_000): void
    {
        Track::factory()->count($count)->create([
            'artist_id' => $artist?->id,
            'genre_id' => $genre->id,
            'duration' => $duration,
            'size' => $size,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $genre = Genre::factory()->create();

        $this->get("/music/genres/{$genre->id}")->assertRedirect('/login');
    }

    public function test_the_page_carries_the_genres_name_and_its_four_numbers(): void
    {
        $genre = Genre::factory()->create(['name' => 'Melodic Death Metal']);
        $artist = Artist::factory()->create(['name' => 'At the Gates']);

        // A fractional sum on purpose: the only way to see raw seconds went over rather
        // than something already rounded for display.
        $this->tracks($artist, $genre, 2, duration: 100.25, size: 4_000_000);
        $this->tracks($artist, $genre, 1, duration: 71.0, size: 6_000_000);

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$genre->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Music/Genres/Genre/GenrePage')
                ->where('genre.id', $genre->id)
                ->where('genre.name', 'Melodic Death Metal')
                ->where('genre.artists', 1)
                ->where('genre.songs', 3)
                ->where('genre.duration', 271.5)
                ->where('genre.size', 14_000_000)
            );
    }

    public function test_the_artist_count_excludes_artists_who_only_dabble_in_the_genre(): void
    {
        // The bug this page could have: filtering to the genre BEFORE ranking. Seen through
        // that lens, an artist with 2 Ambient tracks looks like a 100% Ambient artist,
        // because their 9 Jazz tracks are outside the filter — so the page would claim
        // them. Every genre has to compete for an artist before we ask which one won.
        $ambient = Genre::factory()->create(['name' => 'Ambient']);
        $jazz = Genre::factory()->create(['name' => 'Jazz']);

        $dabbler = Artist::factory()->create(['name' => 'Mostly Jazz']);
        $this->tracks($dabbler, $jazz, 9);
        $this->tracks($dabbler, $ambient, 2);

        $native = Artist::factory()->create(['name' => 'Actually Ambient']);
        $this->tracks($native, $ambient, 5);

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$ambient->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // One, not two: the dabbler's songs are here, the dabbler is not.
                ->where('genre.artists', 1)
                ->where('genre.songs', 7)
            );
    }

    public function test_the_page_and_the_listing_report_the_same_numbers_for_a_genre(): void
    {
        // Two code paths over one rule — the listing groups the winners, the page filters
        // them — so they are worth pinning against each other rather than each against a
        // literal. A tie is where they would diverge first, so the fixture has one.
        $ambient = Genre::factory()->create(['name' => 'Ambient']);
        $jazz = Genre::factory()->create(['name' => 'Jazz']);

        $split = Artist::factory()->create(['name' => 'Brian Eno']);
        $this->tracks($split, $ambient, 3);
        $this->tracks($split, $jazz, 3);
        $this->tracks(Artist::factory()->create(), $ambient, 4);

        $user = User::factory()->create();

        $listingRows = $this->actingAs($user)->get('/music/genres')
            ->viewData('page')['props']['table']['rows'];
        $row = collect($listingRows)->firstWhere('id', $ambient->id);

        $detail = $this->actingAs($user)->get($row['href'])
            ->viewData('page')['props']['genre'];

        $this->assertSame($row['artists'], $detail['artists'], 'artist counts disagree');
        $this->assertSame($row['songs'], $detail['songs'], 'song counts disagree');
        $this->assertSame($row['duration'], $detail['duration'], 'durations disagree');
        $this->assertSame($row['size'], $detail['size'], 'sizes disagree');
    }

    public function test_a_genre_with_no_tracks_left_does_not_break_the_page(): void
    {
        // The scanner prunes orphaned genres, but the page must not 500 in the window where
        // one exists: count() over no rows is 0 and sum() is null, which the controller
        // COALESCEs so the tiles still render.
        $genre = Genre::factory()->create(['name' => 'Orphan']);

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$genre->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('genre.artists', 0)
                ->where('genre.songs', 0)
                // Asserted as ints: a whole-number float crosses the wire as `0`.
                ->where('genre.duration', 0)
                ->where('genre.size', 0)
            );
    }

    public function test_songs_crediting_no_artist_still_count_as_songs(): void
    {
        // `artist_id` is nullable, so a genre can hold files that vote for nobody: they
        // belong in the song count and cannot reach the artist count.
        $genre = Genre::factory()->create(['name' => 'Field Recordings']);
        $this->tracks(null, $genre, 3);

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$genre->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('genre.songs', 3)
                ->where('genre.artists', 0)
            );
    }

    public function test_a_podcast_episode_does_not_count_towards_a_genres_totals(): void
    {
        // The `type = music` scope: a podcast episode is the one non-music track a DB CHECK
        // still lets carry a genre, and none are imported yet.
        $genre = Genre::factory()->create(['name' => 'Ambient']);
        $this->tracks(Artist::factory()->create(), $genre, 1, duration: 100.0, size: 1_000_000);

        Track::factory()->create([
            'type' => TrackType::Podcast,
            'collection_id' => Collection::factory()->podcastShow()->create()->id,
            'genre_id' => $genre->id,
            'artist_id' => Artist::factory()->create()->id,
            'duration' => 3600.0,
            'size' => 50_000_000,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$genre->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('genre.songs', 1)
                ->where('genre.artists', 1)
                ->where('genre.duration', 100)
                ->where('genre.size', 1_000_000)
            );
    }

    public function test_the_albums_tab_holds_albums_whose_main_genre_this_is(): void
    {
        // The rule this tab exists to pin, and the bug it was changed for. A compilation
        // holding ONE track of the genre is not an album of that genre — the live library
        // had a twenty-track contest album with fifteen Pop songs and one each of five
        // others, and under the old "holds at least one" rule it appeared in all six.
        $blues = Genre::factory()->create();
        $other = Genre::factory()->create();

        $mostly = Collection::factory()->create(['name' => 'Mostly Blues', 'year' => 1970]);
        $oneOff = Collection::factory()->create(['name' => 'Compilation', 'year' => 1990]);
        $none = Collection::factory()->create(['name' => 'No Blues At All', 'year' => 1980]);

        Track::factory()->count(2)->create(['collection_id' => $mostly->id, 'genre_id' => $blues->id]);
        // One blues track among four — NOT an album of this genre.
        Track::factory()->create(['collection_id' => $oneOff->id, 'genre_id' => $blues->id]);
        Track::factory()->count(3)->create(['collection_id' => $oneOff->id, 'genre_id' => $other->id]);
        Track::factory()->create(['collection_id' => $none->id, 'genre_id' => $other->id]);

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$blues->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('discography', 1)
                ->where('discography.0.name', 'Mostly Blues')
                // …while the compilation's one blues SONG still shows on the songs tab,
                // alongside the two from the album that owns the genre. The two tabs answer
                // different questions, and only the album one is about what a record IS.
                ->has('table.rows', 3)
            );
    }

    public function test_each_album_carries_its_artist_for_the_genre_page_to_show(): void
    {
        // The artist page's discography has no use for this — every row would name the same
        // person — so it is the genre page that asks for it (Discography's `showArtist`).
        // A compilation filed under no album-artist still lists; its tile just drops the chip.
        $genre = Genre::factory()->create();
        $artist = Artist::factory()->create(['name' => 'Blind Guardian']);

        $credited = Collection::factory()->create(['album_artist_id' => $artist->id, 'year' => 2010]);
        $compilation = Collection::factory()->create(['album_artist_id' => null, 'year' => 1990]);

        Track::factory()->create(['collection_id' => $credited->id, 'genre_id' => $genre->id]);
        Track::factory()->create(['collection_id' => $compilation->id, 'genre_id' => $genre->id]);

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$genre->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('discography.0.artist', 'Blind Guardian')
                ->where('discography.1.artist', null)
            );
    }

    public function test_the_compilation_still_appears_under_the_genre_that_owns_it(): void
    {
        // The other half of the rule: the album is not lost, it is filed where it belongs.
        $blues = Genre::factory()->create();
        $pop = Genre::factory()->create();

        $compilation = Collection::factory()->create(['name' => 'Compilation']);
        Track::factory()->create(['collection_id' => $compilation->id, 'genre_id' => $blues->id]);
        Track::factory()->count(3)->create(['collection_id' => $compilation->id, 'genre_id' => $pop->id]);

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$pop->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('discography', 1)
                ->where('discography.0.name', 'Compilation')
            );
    }

    public function test_an_album_split_evenly_breaks_the_tie_on_the_genre_name(): void
    {
        // Half and half has no majority, and SQL orders tied rows arbitrarily — so without
        // the second ORDER BY the album could file itself under a different genre on each
        // request. The tie-break is the genre's own name, the same rule artists use.
        $ambient = Genre::factory()->create(['name' => 'Ambient']);
        $jazz = Genre::factory()->create(['name' => 'Jazz']);

        $album = Collection::factory()->create(['name' => 'Evenly Split']);
        Track::factory()->count(3)->create(['collection_id' => $album->id, 'genre_id' => $ambient->id]);
        Track::factory()->count(3)->create(['collection_id' => $album->id, 'genre_id' => $jazz->id]);

        $user = User::factory()->create();

        $this->actingAs($user)->get("/music/genres/{$ambient->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('discography', 1));
        $this->actingAs($user)->get("/music/genres/{$jazz->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('discography', 0));
    }

    public function test_an_album_of_the_genre_appears_once_however_many_tracks_it_has(): void
    {
        // Why the filter is a whereIn against the ranked set rather than a join to it: a
        // join would return the album once per matching row and multiply the aggregates.
        $genre = Genre::factory()->create();
        $album = Collection::factory()->create();

        Track::factory()->count(5)->create(['collection_id' => $album->id, 'genre_id' => $genre->id]);

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$genre->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('discography', 1)
                ->where('discography.0.songs', 5)
            );
    }

    public function test_the_artists_tab_lists_exactly_the_artists_the_hero_counted(): void
    {
        // The two must agree by construction — they are the same rows — because the count
        // sits directly above the list. A dabbler is in neither: their main genre is the
        // other one.
        $genre = Genre::factory()->create();
        $other = Genre::factory()->create();

        $mainly = Artist::factory()->create(['name' => 'Aaron Mainly']);
        $dabbler = Artist::factory()->create(['name' => 'Zoe Dabbler']);

        Track::factory()->count(3)->create(['artist_id' => $mainly->id, 'genre_id' => $genre->id]);
        Track::factory()->create(['artist_id' => $dabbler->id, 'genre_id' => $genre->id]);
        Track::factory()->count(4)->create(['artist_id' => $dabbler->id, 'genre_id' => $other->id]);

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$genre->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('artists', 1)
                ->where('artists.0.name', 'Aaron Mainly')
                ->where('artists.0.href', "/music/artists/{$mainly->id}")
                // The hero's number and the tab's list are the same rows.
                ->where('genre.artists', 1)
            );
    }

    public function test_the_songs_tab_is_the_only_thing_reading_the_pages_sort_params(): void
    {
        // The page carries ONE server-driven table on purpose: DataTableService reads
        // sort/dir/page/search unprefixed and all three tabs render at once, so a second
        // one would drive this from the same params. Sorting moves the songs table and
        // leaves the other two panels exactly where they were.
        $genre = Genre::factory()->create();
        $early = Collection::factory()->create(['name' => 'Early', 'year' => 1970]);
        $late = Collection::factory()->create(['name' => 'Late', 'year' => 2010]);

        Track::factory()->create(['collection_id' => $early->id, 'genre_id' => $genre->id, 'name' => 'Alpha']);
        Track::factory()->create(['collection_id' => $late->id, 'genre_id' => $genre->id, 'name' => 'Omega']);

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$genre->id}?sort=name&dir=desc")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.sort.key', 'name')
                ->where('table.sort.direction', 'desc')
                ->where('table.rows.0.name', 'Omega')
                // Still newest-first — the discography has no sort to be hijacked.
                ->where('discography.0.name', 'Late')
                ->where('discography.1.name', 'Early')
            );
    }

    public function test_the_songs_tab_defaults_to_newest_year_then_album_disc_and_track(): void
    {
        // Built so each level can only be satisfied by that level: the album NAMES run
        // opposite to their years, and the tracks go in scrambled, so neither album order
        // nor insertion order could produce this result by accident.
        $genre = Genre::factory()->create();

        $zebra = Collection::factory()->create(['name' => 'Zebra', 'year' => 1990]);
        $aard = Collection::factory()->create(['name' => 'Aardvark', 'year' => 1990]);
        $alpha = Collection::factory()->create(['name' => 'Alpha', 'year' => 2010]);

        $song = function (Collection $album, int $disc, int $track, string $name) use ($genre): void {
            Track::factory()->create([
                'collection_id' => $album->id, 'genre_id' => $genre->id,
                'disc' => $disc, 'track' => $track, 'name' => $name,
            ]);
        };

        $song($zebra, 2, 1, 'zebra-2-1');
        $song($alpha, 1, 1, 'alpha-1-1');
        $song($zebra, 1, 2, 'zebra-1-2');
        $song($aard, 1, 1, 'aardvark-1-1');
        $song($zebra, 1, 1, 'zebra-1-1');

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$genre->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.sort.key', 'year')
                ->where('table.sort.direction', 'desc')
                ->where('table.tiebreakers', ['album', 'disc', 'track', 'name'])
                // 2010 leads, then the two 1990 albums A–Z — and inside Zebra the discs and
                // tracks still climb, which is the point of the tiebreakers staying ascending.
                ->where('table.rows.0.name', 'alpha-1-1')
                ->where('table.rows.1.name', 'aardvark-1-1')
                ->where('table.rows.2.name', 'zebra-1-1')
                ->where('table.rows.3.name', 'zebra-1-2')
                ->where('table.rows.4.name', 'zebra-2-1')
            );
    }

    public function test_a_song_with_no_album_year_sorts_last_rather_than_opening_the_tab(): void
    {
        // The default is DESCENDING and Postgres puts NULLs FIRST under DESC, so on the raw
        // column an untagged rip would be the first thing anyone sees on a genre page. The
        // controller sorts on a COALESCEd alias, which also makes the two engines agree —
        // so this means in production what it means here on SQLite.
        $genre = Genre::factory()->create();
        $dated = Collection::factory()->create(['year' => 2001]);
        $undated = Collection::factory()->create(['year' => null]);

        Track::factory()->create(['collection_id' => $undated->id, 'genre_id' => $genre->id, 'name' => 'undated']);
        Track::factory()->create(['collection_id' => $dated->id, 'genre_id' => $genre->id, 'name' => 'dated']);

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$genre->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.name', 'dated')
                ->where('table.rows.1.name', 'undated')
                ->where('table.rows.1.year', null)
            );
    }

    public function test_the_songs_tab_carries_disc_and_track_with_their_per_set_totals(): void
    {
        // A two-disc album: disc 1 holds three tracks, disc 2 holds one. Rendered "1/2" and
        // "3/3", so both denominators have to be per-SET — discTotal counts the album's
        // discs, trackTotal only the row's own disc.
        $genre = Genre::factory()->create();
        $album = Collection::factory()->create(['year' => 2000]);

        foreach ([[1, 1], [1, 2], [1, 3], [2, 1]] as [$disc, $track]) {
            Track::factory()->create([
                'collection_id' => $album->id, 'genre_id' => $genre->id,
                'disc' => $disc, 'track' => $track, 'name' => "d{$disc}t{$track}",
            ]);
        }

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$genre->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.disc', 1)
                ->where('table.rows.0.discTotal', 2)
                ->where('table.rows.0.trackTotal', 3)
                // The lone track on disc 2 reads "1/1", not "1/3".
                ->where('table.rows.3.disc', 2)
                ->where('table.rows.3.trackTotal', 1)
            );
    }

    public function test_the_hero_album_count_matches_the_albums_tab(): void
    {
        // Same rows, counted once — the pip sits directly above the tab that lists them.
        $genre = Genre::factory()->create();
        $other = Genre::factory()->create();

        foreach (range(1, 3) as $i) {
            $album = Collection::factory()->create();
            Track::factory()->create(['collection_id' => $album->id, 'genre_id' => $genre->id]);
        }
        // Mostly someone else's — counted for neither the pip nor the tab.
        $foreign = Collection::factory()->create();
        Track::factory()->create(['collection_id' => $foreign->id, 'genre_id' => $genre->id]);
        Track::factory()->count(3)->create(['collection_id' => $foreign->id, 'genre_id' => $other->id]);

        $this->actingAs(User::factory()->create())
            ->get("/music/genres/{$genre->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('genre.albums', 3)
                ->has('discography', 3)
            );
    }

    public function test_all_three_panels_are_sent_whatever_the_tab_param_says(): void
    {
        // `?tab=` is the frontend's own state, written into the URL so a reload reopens the
        // right tab. Answering only the open tab would mean a request and a spinner on
        // every tab click, so all three come back regardless — including for a tab that
        // does not exist.
        $genre = Genre::factory()->create();
        $album = Collection::factory()->create();
        $artist = Artist::factory()->create();
        Track::factory()->create([
            'collection_id' => $album->id, 'artist_id' => $artist->id, 'genre_id' => $genre->id,
        ]);

        foreach (['', '?tab=albums', '?tab=artists', '?tab=songs', '?tab=nonsense'] as $query) {
            $this->actingAs(User::factory()->create())
                ->get("/music/genres/{$genre->id}{$query}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('discography', 1)
                    ->has('artists', 1)
                    ->has('table.rows', 1)
                );
        }
    }

    public function test_an_unknown_or_malformed_id_is_a_404(): void
    {
        $user = User::factory()->create();

        // A well-formed UUID that matches nothing 404s at the model binding; anything that
        // is not a UUID 404s one step earlier, at the router's `whereUuid` — which is what
        // keeps `/music/genres/anything` from reaching the controller at all.
        $this->actingAs($user)->get('/music/genres/'.Str::uuid())->assertNotFound();
        $this->actingAs($user)->get('/music/genres/not-a-uuid')->assertNotFound();
    }
}
