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
 * One song's detail page (`/music/songs/{song}`, behind auth) — the row-click
 * target of the Songs listing (SongController).
 */
class SongPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $song = Track::factory()->create();

        $this->get("/music/songs/{$song->id}")->assertRedirect('/login');
    }

    public function test_authenticated_user_sees_the_song_with_its_taxonomy(): void
    {
        $song = Track::factory()->create([
            'name' => 'Lightning Strikes',
            'duration' => 185.4,
            'artist_id' => Artist::factory()->create(['name' => 'The Storm'])->id,
            'collection_id' => Collection::factory()->create(['name' => 'Thunder Road', 'year' => 1994])->id,
            'genre_id' => Genre::factory()->create(['name' => 'Post-Rock'])->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Music/Songs/Song/SongPage')
                ->where('song.id', $song->id)
                ->where('song.name', 'Lightning Strikes')
                ->where('song.artist', 'The Storm')
                ->where('song.album', 'Thunder Road')
                ->where('song.year', 1994)
                ->where('song.genre', 'Post-Rock')
                // 185.4s → 3:05, the same clock form the listing's duration column
                // uses (Track::clockDuration()).
                ->where('song.duration', '3:05')
            );
    }

    public function test_untagged_fields_come_through_as_null_rather_than_failing(): void
    {
        // A music file whose tags named no album/genre: the FKs are nullable, and
        // the page drops the rows instead of rendering empty ones.
        $song = Track::factory()->create([
            'collection_id' => null,
            'genre_id' => null,
            'duration' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('song.album', null)
                ->where('song.genre', null)
                ->where('song.year', null)
                ->where('song.duration', null)
            );
    }

    public function test_an_audiobook_chapter_is_not_reachable_as_a_song(): void
    {
        // Tracks are one table for music and audiobook chapters, so without the
        // controller's type check a chapter would render happily under /music/.
        $chapter = Track::factory()->audiobook()->create();

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$chapter->id}")
            ->assertNotFound();
    }

    public function test_an_unknown_or_malformed_id_is_a_404(): void
    {
        $user = User::factory()->create();

        // A well-formed UUID that isn't in the table (model binding misses)…
        $this->actingAs($user)
            ->get('/music/songs/'.fake()->uuid())
            ->assertNotFound();

        // …and something that isn't a UUID at all, which the route's whereUuid
        // rejects before any binding runs.
        $this->actingAs($user)
            ->get('/music/songs/not-a-uuid')
            ->assertNotFound();
    }
}
