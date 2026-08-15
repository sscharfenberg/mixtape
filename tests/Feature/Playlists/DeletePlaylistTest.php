<?php

namespace Tests\Feature\Playlists;

use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Share;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `DELETE /playlists/{playlist}` — the end of a playlist, pressed behind a dialog on its own
 * detail page.
 *
 * THE CASCADE IS THE POINT OF THIS FILE. Deleting the row is the part nobody gets wrong; what
 * has to keep working is what the schema takes with it, because the SHARES are somebody else's
 * problem the moment they go. A share id is the capability, so a link already sent stops
 * working and cannot be reinstated — which is exactly what the confirmation dialog warns
 * about, and a promise a test should hold the schema to rather than trusting a migration
 * written months ago.
 *
 * OWNERSHIP ANSWERS 404, NOT 403, and that is asserted rather than assumed: this instance is
 * internet-facing and shared, so a 403 would confirm that a guessed uuid names a real playlist
 * belonging to somebody else. The same rule the rest of the playlist routes follow.
 */
class DeletePlaylistTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $playlist = Playlist::factory()->create();

        $this->delete("/playlists/{$playlist->id}")->assertRedirect('/login');
        $this->assertDatabaseHas('playlists', ['id' => $playlist->id]);
    }

    public function test_the_owner_can_delete_it_and_lands_on_the_listing(): void
    {
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->for($owner)->create();

        // The listing, not `back()`: the page they pressed it on is the page that no longer
        // exists, and re-rendering it would 404 on its own show request.
        $this->actingAs($owner)
            ->delete("/playlists/{$playlist->id}")
            ->assertRedirect('/playlists');

        $this->assertDatabaseMissing('playlists', ['id' => $playlist->id]);
    }

    public function test_it_names_the_playlist_in_the_flash(): void
    {
        // The message is read on the LISTING, where nothing else says which playlist went.
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->for($owner)->create(['name' => 'Sonntagmorgen']);

        $this->actingAs($owner)
            ->delete("/playlists/{$playlist->id}")
            ->assertSessionHas('message', fn (string $message): bool => str_contains($message, 'Sonntagmorgen'));
    }

    public function test_its_entries_go_with_it(): void
    {
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->for($owner)->create();
        $track = Track::factory()->create();
        PlaylistTrack::factory()->for($playlist)->for($track)->create(['position' => 0]);

        $this->actingAs($owner)->delete("/playlists/{$playlist->id}");

        $this->assertDatabaseMissing('playlist_tracks', ['playlist_id' => $playlist->id]);
        // The TRACK is not the playlist's to delete — it is a file in the library that other
        // playlists, the queue and the listings all still point at.
        $this->assertDatabaseHas('tracks', ['id' => $track->id]);
    }

    public function test_every_share_minted_from_it_is_revoked(): void
    {
        /*
         * THE ONE THE DIALOG WARNS ABOUT. `shares.playlist_id` is `cascadeOnDelete`, so a link
         * handed to somebody dies here — silently, from their side. If this ever regressed to
         * `nullOnDelete` the row would survive with no subject, and `/s/{share}` would resolve
         * a share that grants nothing rather than answering 404.
         */
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->for($owner)->create();
        $share = Share::factory()->for($owner)->ofPlaylist($playlist)->create();

        $this->actingAs($owner)->delete("/playlists/{$playlist->id}");

        $this->assertDatabaseMissing('shares', ['id' => $share->id]);
    }

    public function test_somebody_elses_playlist_answers_404_and_survives(): void
    {
        $intruder = User::factory()->create();
        $playlist = Playlist::factory()->create();

        $this->actingAs($intruder)
            ->delete("/playlists/{$playlist->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('playlists', ['id' => $playlist->id]);
    }
}
