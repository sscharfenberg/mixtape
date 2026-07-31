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
