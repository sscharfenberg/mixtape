<?php

namespace Tests\Feature\Playlists;

use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `DELETE /playlists/{playlist}/tracks/{entry}` — one entry out of one playlist, pressed
 * straight from its row with no dialog in front of it.
 *
 * TWO THINGS HAVE TO BE TRUE AT ONCE, and the second is the one worth a test of its own: the
 * playlist must be the caller's, AND the entry must belong to that playlist. Checking only
 * ownership would let somebody who owns playlist A name an entry of playlist B in the URL and
 * have it deleted — the guard would pass on A while the row that went was B's. That is the
 * cross-playlist case below, and it is deliberately set up with a playlist the caller really
 * does own, because a test where they own neither cannot tell the two checks apart.
 *
 * `position` MUST COME BACK CONTIGUOUS. PlaylistTrack's docblock states the invariant, the
 * reorder maintains it and the export sorts on it — and a gap is invisible from the page,
 * since the order still reads correctly. So nothing would look wrong until something treated
 * a position as an index.
 */
class DeletePlaylistTrackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A playlist of `$count` entries at positions 0..n, and the entries themselves.
     *
     * @return array{0: Playlist, 1: list<PlaylistTrack>}
     */
    private function playlistOf(User $owner, int $count): array
    {
        $playlist = Playlist::factory()->for($owner)->create();

        $entries = [];
        for ($position = 0; $position < $count; $position++) {
            $entries[] = PlaylistTrack::factory()
                ->for($playlist)
                ->for(Track::factory()->create())
                ->create(['position' => $position]);
        }

        return [$playlist, $entries];
    }

    public function test_guests_are_redirected_to_login(): void
    {
        [$playlist, $entries] = $this->playlistOf(User::factory()->create(), 2);

        $this->delete("/playlists/{$playlist->id}/tracks/{$entries[0]->id}")->assertRedirect('/login');
        $this->assertDatabaseHas('playlist_tracks', ['id' => $entries[0]->id]);
    }

    public function test_the_owner_can_remove_an_entry_and_stays_on_the_page(): void
    {
        $owner = User::factory()->create();
        [$playlist, $entries] = $this->playlistOf($owner, 3);

        // `back()`, unlike deleting the playlist: the page they pressed it on still exists.
        $this->actingAs($owner)
            ->from("/playlists/{$playlist->id}")
            ->delete("/playlists/{$playlist->id}/tracks/{$entries[1]->id}")
            ->assertRedirect("/playlists/{$playlist->id}");

        $this->assertDatabaseMissing('playlist_tracks', ['id' => $entries[1]->id]);
        // The file is a library row that other playlists and the queue still point at.
        $this->assertDatabaseHas('tracks', ['id' => $entries[1]->track_id]);
    }

    public function test_it_closes_the_gap_in_position(): void
    {
        $owner = User::factory()->create();
        [$playlist, $entries] = $this->playlistOf($owner, 4);

        // The middle one, so there is something on both sides of the gap: removing the last
        // entry would leave 0,1,2 whether or not anything shifted.
        $this->actingAs($owner)->delete("/playlists/{$playlist->id}/tracks/{$entries[1]->id}");

        $positions = DB::table('playlist_tracks')
            ->where('playlist_id', $playlist->id)
            ->orderBy('position')
            ->pluck('position')
            ->all();

        $this->assertSame([0, 1, 2], $positions);
    }

    public function test_it_keeps_the_surviving_entries_in_the_same_order(): void
    {
        // Contiguity alone would be satisfied by a renumber that shuffled them.
        $owner = User::factory()->create();
        [$playlist, $entries] = $this->playlistOf($owner, 4);

        $this->actingAs($owner)->delete("/playlists/{$playlist->id}/tracks/{$entries[1]->id}");

        $order = DB::table('playlist_tracks')
            ->where('playlist_id', $playlist->id)
            ->orderBy('position')
            ->pluck('id')
            ->all();

        $this->assertSame([$entries[0]->id, $entries[2]->id, $entries[3]->id], $order);
    }

    public function test_removing_one_of_two_copies_leaves_the_other(): void
    {
        /*
         * Nothing forbids the same track twice in a running order, which is why the URL names
         * the ENTRY. If this ever went by track id, both rows would go and the reader would
         * have pressed one button and lost two entries.
         */
        $owner = User::factory()->create();
        $playlist = Playlist::factory()->for($owner)->create();
        $track = Track::factory()->create();

        $first = PlaylistTrack::factory()->for($playlist)->for($track)->create(['position' => 0]);
        $second = PlaylistTrack::factory()->for($playlist)->for($track)->create(['position' => 1]);

        $this->actingAs($owner)->delete("/playlists/{$playlist->id}/tracks/{$first->id}");

        $this->assertDatabaseMissing('playlist_tracks', ['id' => $first->id]);
        $this->assertDatabaseHas('playlist_tracks', ['id' => $second->id, 'position' => 0]);
    }

    public function test_removing_an_entry_counts_as_changing_the_playlist(): void
    {
        // Both playlist pages print a "changed" date, and what a reader changes about a
        // playlist is what is IN it. PlaylistTrack names `playlist` in `$touches`, and
        // Eloquent touches owners on delete as well as on save.
        $owner = User::factory()->create();
        [$playlist, $entries] = $this->playlistOf($owner, 2);

        $playlist->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
        $before = $playlist->fresh()->updated_at;

        $this->actingAs($owner)->delete("/playlists/{$playlist->id}/tracks/{$entries[0]->id}");

        $this->assertTrue($playlist->fresh()->updated_at->greaterThan($before));
    }

    public function test_an_entry_of_another_playlist_answers_404_and_survives(): void
    {
        /*
         * THE CROSS-PLAYLIST GUARD, and the reason ownership alone is not enough. The caller
         * owns the playlist in the URL; the entry named beside it belongs to somebody else's.
         * An authorize() that asked only "is this playlist yours?" would answer yes and delete
         * a row the caller has no claim to.
         */
        $owner = User::factory()->create();
        $mine = Playlist::factory()->for($owner)->create();

        [, $theirs] = $this->playlistOf(User::factory()->create(), 1);

        $this->actingAs($owner)
            ->delete("/playlists/{$mine->id}/tracks/{$theirs[0]->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('playlist_tracks', ['id' => $theirs[0]->id]);
    }

    public function test_an_entry_of_another_playlist_of_your_own_also_answers_404(): void
    {
        // Same guard where nothing is stolen — the URL is simply inconsistent. It still has to
        // refuse, or the route would delete from a playlist the URL does not name.
        $owner = User::factory()->create();
        [$one] = $this->playlistOf($owner, 1);
        [, $otherEntries] = $this->playlistOf($owner, 1);

        $this->actingAs($owner)
            ->delete("/playlists/{$one->id}/tracks/{$otherEntries[0]->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('playlist_tracks', ['id' => $otherEntries[0]->id]);
    }

    public function test_somebody_elses_playlist_answers_404_and_survives(): void
    {
        $intruder = User::factory()->create();
        [$playlist, $entries] = $this->playlistOf(User::factory()->create(), 2);

        $this->actingAs($intruder)
            ->delete("/playlists/{$playlist->id}/tracks/{$entries[0]->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('playlist_tracks', ['id' => $entries[0]->id]);
    }
}
