<?php

namespace Tests\Feature\Music;

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
            );
    }

    public function test_each_widget_is_capped_at_four_entries_with_the_expected_shape(): void
    {
        // Six music tracks pull in six albums / artists / genres through their
        // FKs, so every widget has more than four candidates to cap.
        Track::factory()->count(6)->create();

        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->has('albums.latest', 4)->has('albums.random', 4)
                ->has('artists.latest', 4)->has('artists.random', 4)
                ->has('genres.latest', 4)->has('genres.random', 4)
                ->has('songs.latest', 4)->has('songs.random', 4)
                ->has('albums.latest.0', fn (Assert $album) => $album->hasAll(['id', 'name', 'artist', 'year']))
                ->has('songs.latest.0', fn (Assert $song) => $song->hasAll(['id', 'name', 'artist']))
            );
    }
}
