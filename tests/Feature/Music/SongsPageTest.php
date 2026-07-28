<?php

namespace Tests\Feature\Music;

use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Songs listing (`/music/songs`, behind auth) — the server-driven DataTable
 * payload SongsController shapes. Search is deliberately untested here: its
 * `COLLATE "C" ILIKE` is Postgres-only and these tests run on SQLite.
 */
class SongsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/music/songs')->assertRedirect('/login');
    }

    public function test_every_row_carries_the_href_that_makes_it_clickable(): void
    {
        $song = Track::factory()->create(['name' => 'Lightning Strikes', 'duration' => 185.4]);

        $this->actingAs(User::factory()->create())
            ->get('/music/songs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Music/Songs/SongsPage')
                ->has('table.rows', 1)
                ->where('table.rows.0.name', 'Lightning Strikes')
                // Raw seconds, not a "3:05" clock: the page's cell-duration slot
                // formats it (Utils/formatting.ts → formatClock).
                ->where('table.rows.0.duration', 185.4)
                // The row click and the title link both navigate to this; a
                // relative path, so it holds whatever host serves the app.
                ->where('table.rows.0.href', "/music/songs/{$song->id}")
            );
    }

    public function test_audiobook_chapters_stay_out_of_the_song_listing(): void
    {
        Track::factory()->count(2)->create();
        Track::factory()->audiobook()->create();

        $this->actingAs(User::factory()->create())
            ->get('/music/songs')
            ->assertInertia(fn (Assert $page) => $page->has('table.rows', 2));
    }
}
