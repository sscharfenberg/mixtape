<?php

namespace Tests\Feature\Music;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Music\Concerns\SortsAListing;
use Tests\TestCase;

/**
 * The Genres listing (`/music/genres`, behind auth) — the server-driven DataTable payload
 * GenresController shapes.
 *
 * Three of the four numbers are plain counts over the genre's tracks and are asserted
 * once. The fourth carries all the weight: ARTISTS counts the artists whose MAIN genre
 * this is, so an artist with 5 Jazz tracks and 3 Rock tracks counts for Jazz and NOT for
 * Rock. Most of these tests are about that one column — that it picks the majority, that
 * it never double-counts, that a genre nobody leads reports 0, and that it agrees with
 * what the artist's own page says about them (the failure this cannot be allowed to have,
 * since a reader has no way to tell which of two disagreeing screens is lying).
 */
class GenresPageTest extends TestCase
{
    use RefreshDatabase;
    use SortsAListing;

    /**
     * $count music tracks by $artist in $genre, with duration and size pinned — the sums
     * are asserted, and TrackFactory randomises both by design.
     */
    private function tracks(?Artist $artist, ?Genre $genre, int $count, float $duration = 100.0, int $size = 1_000_000): void
    {
        Track::factory()->count($count)->create([
            'artist_id' => $artist?->id,
            'genre_id' => $genre?->id,
            'duration' => $duration,
            'size' => $size,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/music/genres')->assertRedirect('/login');
    }

    public function test_a_row_carries_its_artist_count_song_count_and_totals(): void
    {
        $jazz = Genre::factory()->create(['name' => 'Jazz']);
        $artist = Artist::factory()->create(['name' => 'John Zorn']);

        // Three × a fractional duration, so the sum is deliberately NOT a whole number:
        // the only way to see raw seconds went over rather than something already rounded.
        $this->tracks($artist, $jazz, 2, duration: 100.25, size: 4_000_000);
        $this->tracks($artist, $jazz, 1, duration: 71.0, size: 6_000_000);

        $this->actingAs(User::factory()->create())
            ->get('/music/genres')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Music/Genres/GenresPage')
                ->has('table.rows', 1)
                ->where('table.rows.0.name', 'Jazz')
                ->where('table.rows.0.artists', 1)
                ->where('table.rows.0.songs', 3)
                ->where('table.rows.0.duration', 271.5)
                ->where('table.rows.0.size', 14_000_000)
                // What makes the row clickable — the frontend visits this on a row click,
                // and the name cell renders it as a real link.
                ->where('table.rows.0.href', "/music/genres/{$jazz->id}")
            );
    }

    public function test_an_artist_counts_only_for_the_genre_most_of_their_songs_carry(): void
    {
        // The column's whole meaning, and the case that separates it from "artists with a
        // track in this genre": this artist has tracks in three genres, and counts for
        // exactly one of them.
        $ambient = Genre::factory()->create(['name' => 'Ambient']);
        $blackMetal = Genre::factory()->create(['name' => 'Black Metal']);
        $folk = Genre::factory()->create(['name' => 'Folk']);

        $ulver = Artist::factory()->create(['name' => 'Ulver']);
        $this->tracks($ulver, $ambient, 5);
        $this->tracks($ulver, $blackMetal, 2);
        $this->tracks($ulver, $folk, 1);

        $this->actingAs(User::factory()->create())
            ->get('/music/genres?sort=name&dir=asc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 3)
                // Ambient wins on 5 of the 8 files.
                ->where('table.rows.0.name', 'Ambient')
                ->where('table.rows.0.artists', 1)
                // The other two hold this artist's songs and none of this artist: they are
                // where Ulver also records, not what Ulver is.
                ->where('table.rows.1.name', 'Black Metal')
                ->where('table.rows.1.artists', 0)
                ->where('table.rows.1.songs', 2)
                ->where('table.rows.2.name', 'Folk')
                ->where('table.rows.2.artists', 0)
                ->where('table.rows.2.songs', 1)
            );
    }

    public function test_the_artist_counts_add_up_to_the_number_of_artists(): void
    {
        // The property the "main genre" rule buys, and the reason it is worth the window
        // function: every artist is counted exactly once across the whole column, so the
        // numbers can be read as a breakdown of the library rather than as overlapping
        // tallies that sum to more artists than exist.
        $rock = Genre::factory()->create(['name' => 'Rock']);
        $pop = Genre::factory()->create(['name' => 'Pop']);

        // Three artists, each straddling both genres, each with a different majority.
        $rocker = Artist::factory()->create(['name' => 'A Rocker']);
        $this->tracks($rocker, $rock, 4);
        $this->tracks($rocker, $pop, 1);

        $popStar = Artist::factory()->create(['name' => 'B Pop Star']);
        $this->tracks($popStar, $pop, 3);
        $this->tracks($popStar, $rock, 2);

        $crossover = Artist::factory()->create(['name' => 'C Crossover']);
        $this->tracks($crossover, $rock, 6);
        $this->tracks($crossover, $pop, 5);

        $this->actingAs(User::factory()->create())
            ->get('/music/genres?sort=artists&dir=desc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 2)
                // 2 + 1 = 3, the number of artists — not 6, which is what counting
                // "artists with a track in this genre" would give.
                ->where('table.rows.0.name', 'Rock')
                ->where('table.rows.0.artists', 2)
                ->where('table.rows.1.name', 'Pop')
                ->where('table.rows.1.artists', 1)
                // And every song still counts for the genre it is tagged with, in both.
                ->where('table.rows.0.songs', 12)
                ->where('table.rows.1.songs', 9)
            );
    }

    public function test_an_artist_split_evenly_counts_for_the_first_genre_by_name(): void
    {
        // A tie has no majority, and SQL orders tied rows arbitrarily — so the count could
        // otherwise move between two requests. The tie-break is the genre's own name, the
        // same rule the artist page uses (see the agreement test below).
        $jazz = Genre::factory()->create(['name' => 'Jazz']);
        $ambient = Genre::factory()->create(['name' => 'Ambient']);

        $artist = Artist::factory()->create(['name' => 'John Zorn']);
        $this->tracks($artist, $jazz, 4);
        $this->tracks($artist, $ambient, 4);

        $user = User::factory()->create();

        // Repeated, because "stable" is the actual claim — a single pass could pass by
        // luck on whichever row the engine happened to return first.
        foreach (range(1, 3) as $attempt) {
            $this->actingAs($user)
                ->get('/music/genres?sort=name&dir=asc')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('table.rows.0.name', 'Ambient')
                    ->where('table.rows.0.artists', 1)
                    ->where('table.rows.1.name', 'Jazz')
                    ->where('table.rows.1.artists', 0)
                );
        }
    }

    public function test_the_listing_and_the_artists_own_page_agree_on_that_artists_genre(): void
    {
        // The contradiction this must never produce: the artist page saying "Ambient"
        // while the listing counts them under Jazz. Both read DominantGenre, and this is
        // the test that keeps them reading the same thing — it fails the moment either
        // side grows its own copy of the rule.
        $jazz = Genre::factory()->create(['name' => 'Jazz']);
        $ambient = Genre::factory()->create(['name' => 'Ambient']);

        $artist = Artist::factory()->create(['name' => 'Brian Eno']);
        // Deliberately a tie, which is where two implementations diverge first: the
        // majority case is hard to get wrong, the tie-break is easy to.
        $this->tracks($artist, $jazz, 3);
        $this->tracks($artist, $ambient, 3);

        $user = User::factory()->create();

        $listing = $this->actingAs($user)->get('/music/genres?sort=artists&dir=desc');
        $detail = $this->actingAs($user)->get("/music/artists/{$artist->id}");

        $countedUnder = $this->inertiaProp($listing, 'table.rows.0');
        $shownOnTheArtistsPage = $this->inertiaProp($detail, 'artist.genre');

        $this->assertSame(1, $countedUnder['artists'], 'the artist should be counted exactly once');
        $this->assertSame(
            $countedUnder['name'],
            $shownOnTheArtistsPage,
            'the genre listing and the artist page disagree about this artist'
        );
    }

    public function test_a_genre_with_no_tracks_left_reports_zeroes_rather_than_nulls(): void
    {
        // The scanner prunes orphaned genres, but a listing must not break in the window
        // where one exists — and a NULL sum here is what floats it to the top of the
        // DEFAULT sort on Postgres (ORDER BY … DESC puts NULLs first).
        Genre::factory()->create(['name' => 'Orphan']);

        $this->actingAs(User::factory()->create())
            ->get('/music/genres')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.name', 'Orphan')
                ->where('table.rows.0.artists', 0)
                ->where('table.rows.0.songs', 0)
                // Asserted as ints: a whole-number float crosses the wire as `0`.
                ->where('table.rows.0.duration', 0)
                ->where('table.rows.0.size', 0)
            );
    }

    public function test_songs_crediting_no_artist_still_count_as_songs(): void
    {
        // `artist_id` is nullable, so a genre can hold files that vote for nobody. They
        // belong in the song count and cannot reach the artist count — an untagged
        // performer is not an artist to be counted.
        $genre = Genre::factory()->create(['name' => 'Field Recordings']);
        $this->tracks(null, $genre, 3);

        $this->actingAs(User::factory()->create())
            ->get('/music/genres')
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.rows.0.songs', 3)
                ->where('table.rows.0.artists', 0)
            );
    }

    public function test_the_listing_opens_on_the_genres_with_the_most_audio(): void
    {
        $most = Genre::factory()->create(['name' => 'Zeuhl']);
        $least = Genre::factory()->create(['name' => 'Ambient']);

        $this->tracks(Artist::factory()->create(), $most, 1, duration: 300.0);
        $this->tracks(Artist::factory()->create(), $least, 1, duration: 60.0);

        $this->actingAs(User::factory()->create())
            ->get('/music/genres')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Alphabetically these two are the wrong way round, which is the point.
                ->where('table.rows.0.name', 'Zeuhl')
                ->where('table.rows.1.name', 'Ambient')
                ->where('table.sort.key', 'duration')
                ->where('table.sort.direction', 'desc')
                // The name tiebreak is advertised on the default sort, so the header can
                // show the compound "most audio, then A–Z" order.
                ->where('table.tiebreakers', ['name'])
            );
    }

    public function test_every_column_can_be_sorted_by(): void
    {
        // A small genre and a large one, with every sorted-on value differing and the
        // names running OPPOSITE to the numbers, so no column can pass by accidentally
        // agreeing with another. See the trait for what the reversal proves.
        $small = Genre::factory()->create(['name' => 'Zeuhl']);
        $large = Genre::factory()->create(['name' => 'Ambient']);

        $this->tracks(Artist::factory()->create(), $small, 1, duration: 60.0, size: 1_000_000);

        // Two artists whose main genre is the large one, and three songs against the
        // small one's single song.
        $this->tracks(Artist::factory()->create(), $large, 2, duration: 100.0, size: 2_000_000);
        $this->tracks(Artist::factory()->create(), $large, 1, duration: 100.0, size: 2_000_000);

        $user = User::factory()->create();

        $this->assertEverySortableColumnReverses($user, '/music/genres', ['name', 'artists', 'songs', 'duration', 'size'], [$small->id, $large->id]);
    }

    public function test_search_matches_a_genre_name_and_ignores_accents(): void
    {
        $accented = Genre::factory()->create(['name' => 'Musique concrète']);
        $plain = Genre::factory()->create(['name' => 'Drum and Bass']);

        $user = User::factory()->create();

        foreach (['concrete' => $accented, 'concrète' => $accented, 'drum' => $plain] as $term => $expected) {
            $this->actingAs($user)
                ->get('/music/genres?search='.urlencode((string) $term))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('table.rows', 1)
                    ->where('table.rows.0.id', $expected->id)
                );
        }
    }
}
