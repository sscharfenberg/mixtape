<?php

namespace Tests\Feature\Music;

use App\Models\Artist;
use App\Models\Play;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Music browse page (`/music`, behind auth) — four widgets (albums,
 * artists, genres, songs), each carrying a latest + random set capped at four
 * entries (MusicController).
 */
class MusicPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/music')->assertRedirect('/login');
    }

    public function test_authenticated_user_sees_the_music_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Music/MusicPage')
                ->has('albums.latest')->has('albums.random')
                ->has('artists.latest')->has('artists.random')
                ->has('genres.latest')->has('genres.random')
                ->has('songs.latest')->has('songs.random')
                // "popular" ships only for the three widgets that support it…
                ->has('songs.popular')
                ->has('artists.popular')
                ->has('genres.popular')
                // …never for albums (the owner scoped it out).
                ->missing('albums.popular')
                // the stats widget's collection totals.
                ->has('stats', fn (Assert $stats) => $stats->hasAll([
                    'songs', 'sizeBytes', 'playtimeSeconds', 'albums', 'artists', 'genres',
                ]))
            );
    }

    public function test_each_widget_is_capped_at_four_entries_with_the_expected_shape(): void
    {
        // Six music tracks pull in six albums / artists / genres through their
        // FKs, so every widget has more than four candidates to cap.
        $user = User::factory()->create();
        $tracks = Track::factory()->count(6)->create();
        // Songs' "popular" is gated to >1 play, so give every track two plays or
        // the set would be empty rather than capped.
        $tracks->each(fn (Track $t) => Play::factory()->count(2)->create(['track_id' => $t->id, 'user_id' => $user->id]));

        $this->actingAs($user)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->has('albums.latest', 4)->has('albums.random', 4)
                ->has('artists.latest', 4)->has('artists.random', 4)->has('artists.popular', 4)
                ->has('genres.latest', 4)->has('genres.random', 4)->has('genres.popular', 4)
                ->has('songs.latest', 4)->has('songs.random', 4)->has('songs.popular', 4)
                ->has('albums.latest.0', fn (Assert $album) => $album->hasAll(['id', 'name', 'artist', 'year']))
                ->has('songs.latest.0', fn (Assert $song) => $song->hasAll(['id', 'name', 'artist']))
            );
    }

    public function test_artists_widget_excludes_artists_with_no_tracks(): void
    {
        // Two performers (each Track::factory mints its own artist) plus one
        // album-artist-only artist — a compilation owner like "Irish Folk
        // Festival" that performs nothing, so its max(modified_at) is NULL.
        // Postgres sorts that NULL to the TOP of "latest" (the reported bug);
        // the controller's has('tracks') filter drops it. Both modes should
        // therefore return only the two real performers. (On SQLite the NULL
        // would sort last, not first, so this asserts the filter itself — count
        // 2, not 3 — independently of the DB's NULL ordering.)
        Track::factory()->count(2)->create();
        Artist::factory()->create(['name' => 'No Tracks Compilation']);

        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->has('artists.latest', 2)
                ->has('artists.random', 2)
            );
    }

    public function test_songs_popular_ranks_by_plays_and_excludes_single_plays(): void
    {
        $user = User::factory()->create();
        $hot = Track::factory()->create(['name' => 'Hot Track']);
        $warm = Track::factory()->create(['name' => 'Warm Track']);
        $cold = Track::factory()->create(['name' => 'Cold Track']);
        Play::factory()->count(5)->create(['track_id' => $hot->id, 'user_id' => $user->id]);
        Play::factory()->count(2)->create(['track_id' => $warm->id, 'user_id' => $user->id]);
        Play::factory()->count(1)->create(['track_id' => $cold->id, 'user_id' => $user->id]);

        // Ranked by play count; the single-play track is excluded (popular needs >1).
        $this->actingAs($user)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->has('songs.popular', 2)
                ->where('songs.popular.0.name', 'Hot Track')
                ->where('songs.popular.1.name', 'Warm Track')
            );
    }

    public function test_songs_popular_is_empty_when_no_song_has_more_than_one_play(): void
    {
        $user = User::factory()->create();
        $songs = Track::factory()->count(3)->create();
        Play::factory()->create(['track_id' => $songs[0]->id, 'user_id' => $user->id]);
        Play::factory()->create(['track_id' => $songs[1]->id, 'user_id' => $user->id]);

        // Every song sits at ≤1 play → no popularity signal → empty set, which the
        // widget renders as "not enough data".
        $this->actingAs($user)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page->has('songs.popular', 0));
    }

    public function test_artists_popular_orders_by_total_file_duration(): void
    {
        $long = Artist::factory()->create(['name' => 'Long Artist']);
        $short = Artist::factory()->create(['name' => 'Short Artist']);
        Track::factory()->create(['artist_id' => $long->id, 'duration' => 500]);
        Track::factory()->create(['artist_id' => $short->id, 'duration' => 100]);

        // "popular" for artists = most total file duration, so Long Artist leads.
        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page->where('artists.popular.0.name', 'Long Artist'));
    }

    public function test_stats_count_music_only(): void
    {
        // Three music files (each mints its own album/artist/genre) plus one
        // audiobook chapter, which must NOT count toward the music stats.
        Track::factory()->count(3)->create(['size' => 1000]);
        Track::factory()->audiobook()->create(['size' => 999999]);

        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.songs', 3)
                ->where('stats.sizeBytes', 3000) // audiobook's size excluded
                ->where('stats.albums', 3)
                ->where('stats.artists', 3)
                ->where('stats.genres', 3)
            );
    }
}
