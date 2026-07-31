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

        $this->tracks($artist, Genre::factory()->create(['name' => 'Black Metal']), 2);
        $this->tracks($artist, Genre::factory()->create(['name' => 'Ambient']), 5);
        $this->tracks($artist, Genre::factory()->create(['name' => 'Folk']), 1);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Not "Black Metal" (what they are known for) and not "Folk" (the first
                // one alphabetically) — the one five of the eight files are tagged with.
                ->where('artist.genre', 'Ambient')
                // No URL yet: the genre area is still a listing with no detail page behind
                // it, so the page renders the tile as plain text rather than a dead link.
                ->where('artist.genreUrl', null)
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
}
