<?php

namespace Tests\Feature\Playlists;

use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `PUT /playlists/{playlist}/tracks/order` — the running order inside one playlist, written by
 * the detail page's drag handles.
 *
 * The sibling of ReorderPlaylistsTest one level down, and the differences are what this file
 * is really about. Ownership is split across both halves: the PLAYLIST is authorized (404, so
 * a guessed uuid cannot confirm it exists) while each ENTRY is validated against it, so a
 * foreign entry id is a 422 on the field rather than a 404 on the request. And the ids are
 * pivot ids, not track ids — a track may sit in a playlist twice, so a track id does not
 * identify a position.
 *
 * The two invariants worth pinning are the ones a reader would notice going wrong: positions
 * come back CONTIGUOUS from 0, and a reorder counts as CHANGING the playlist — the query
 * builder fires no model events, so the controller has to touch the parent by hand or the one
 * edit that changes what a playlist is would be the only one its date ignored.
 */
class ReorderPlaylistTracksTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $playlist = Playlist::factory()->create();

        $this->put("/playlists/{$playlist->id}/tracks/order", ['ids' => []])->assertRedirect('/login');
    }

    public function test_it_renumbers_the_entries_in_the_order_sent(): void
    {
        [$reader, $playlist, $entries] = $this->playlistOf(3);

        $reordered = [$entries[2]->id, $entries[0]->id, $entries[1]->id];

        $this->actingAs($reader)
            ->put("/playlists/{$playlist->id}/tracks/order", ['ids' => $reordered])
            ->assertRedirect();

        foreach ($reordered as $position => $id) {
            $this->assertSame($position, PlaylistTrack::query()->findOrFail($id)->position);
        }
    }

    public function test_positions_come_back_contiguous_from_zero(): void
    {
        // The migration calls `position` contiguous and the page renders by it, so a gap would
        // be a silent divergence between what is stored and what anything else assumes.
        [$reader, $playlist, $entries] = $this->playlistOf(4, startAt: 10);

        $this->actingAs($reader)
            ->put("/playlists/{$playlist->id}/tracks/order", [
                'ids' => collect($entries)->pluck('id')->all(),
            ])
            ->assertRedirect();

        $this->assertSame(
            [0, 1, 2, 3],
            PlaylistTrack::query()->where('playlist_id', $playlist->id)->orderBy('position')->pluck('position')->all()
        );
    }

    public function test_a_reorder_counts_as_changing_the_playlist(): void
    {
        /*
         * PlaylistTrack::$touches does NOT fire here: the controller renumbers through the
         * query builder, which raises no model events. Without the explicit touch, dragging a
         * playlist into a different order — the one edit that changes what it actually is —
         * would be the only change its "changed" date never noticed, on both playlist pages.
         */
        [$reader, $playlist, $entries] = $this->playlistOf(2);
        $before = $playlist->fresh()->updated_at;

        $this->travel(1)->minute();
        $this->actingAs($reader)
            ->put("/playlists/{$playlist->id}/tracks/order", [
                'ids' => [$entries[1]->id, $entries[0]->id],
            ])
            ->assertRedirect();

        $this->assertTrue($playlist->fresh()->updated_at->greaterThan($before));
    }

    public function test_a_strangers_playlist_answers_404_rather_than_403(): void
    {
        // The disclosure rule the whole area follows: 403 would confirm the playlist exists.
        [, $playlist, $entries] = $this->playlistOf(2);

        $this->actingAs(User::factory()->create())
            ->put("/playlists/{$playlist->id}/tracks/order", [
                'ids' => [$entries[1]->id, $entries[0]->id],
            ])
            ->assertNotFound();

        // And nothing moved.
        $this->assertSame(0, PlaylistTrack::query()->findOrFail($entries[0]->id)->position);
    }

    public function test_an_entry_of_another_playlist_is_rejected(): void
    {
        // A 422 on the field rather than a 404 on the request: the playlist in the URL is the
        // reader's, so the request is legitimate — one of its ids simply is not one of theirs.
        [$reader, $playlist, $entries] = $this->playlistOf(2);
        $other = Playlist::factory()->create(['user_id' => $reader->id, 'name' => 'Elsewhere']);
        $foreign = PlaylistTrack::factory()->create(['playlist_id' => $other->id]);

        $this->actingAs($reader)
            ->put("/playlists/{$playlist->id}/tracks/order", [
                'ids' => [$entries[1]->id, $foreign->id],
            ])
            ->assertSessionHasErrors('ids.1');

        // NOTHING WAS RENUMBERED, and the row asserted on has to be one the rejected order would
        // have MOVED: `$entries[1]` is sent first, so a request that got through would put it at
        // position 0. Asserting on a row the order leaves where it already was proves nothing.
        $this->assertSame(1, PlaylistTrack::query()->findOrFail($entries[1]->id)->position);
        // …and the foreign entry keeps its own playlist's numbering.
        $this->assertSame($foreign->position, $foreign->fresh()->position);
    }

    public function test_a_partial_ordering_is_rejected(): void
    {
        // Renumbering only what was sent would leave the rest on their old numbers, interleaved
        // with the new ones — an order the reader never asked for and cannot see the logic of.
        [$reader, $playlist, $entries] = $this->playlistOf(3);

        $this->actingAs($reader)
            ->put("/playlists/{$playlist->id}/tracks/order", [
                'ids' => [$entries[1]->id, $entries[0]->id],
            ])
            ->assertSessionHasErrors('ids');
    }

    public function test_a_repeated_id_is_rejected(): void
    {
        [$reader, $playlist, $entries] = $this->playlistOf(2);

        $this->actingAs($reader)
            ->put("/playlists/{$playlist->id}/tracks/order", [
                'ids' => [$entries[0]->id, $entries[0]->id],
            ])
            ->assertSessionHasErrors('ids.1');
    }

    public function test_the_same_track_twice_can_still_be_reordered(): void
    {
        // The reason the ids are PIVOT ids: a track id would name two rows here, and the two
        // are genuinely at different positions.
        $reader = User::factory()->create();
        $playlist = Playlist::factory()->create(['user_id' => $reader->id]);
        $track = Track::factory()->create();
        $first = PlaylistTrack::factory()->create([
            'playlist_id' => $playlist->id, 'track_id' => $track->id, 'position' => 0,
        ]);
        $second = PlaylistTrack::factory()->create([
            'playlist_id' => $playlist->id, 'track_id' => $track->id, 'position' => 1,
        ]);

        $this->actingAs($reader)
            ->put("/playlists/{$playlist->id}/tracks/order", ['ids' => [$second->id, $first->id]])
            ->assertRedirect();

        $this->assertSame(0, $second->fresh()->position);
        $this->assertSame(1, $first->fresh()->position);
    }

    /**
     * A reader, a playlist of theirs, and `$count` entries at known positions.
     *
     * `startAt` exists for the contiguity test: entries that begin at 10 prove the renumbering
     * writes 0..n-1 rather than merely preserving whatever spacing it was handed.
     *
     * @return array{0: User, 1: Playlist, 2: array<int, PlaylistTrack>}
     */
    private function playlistOf(int $count, int $startAt = 0): array
    {
        $reader = User::factory()->create();
        $playlist = Playlist::factory()->create(['user_id' => $reader->id]);

        $entries = [];
        foreach (range(0, $count - 1) as $index) {
            $entries[] = PlaylistTrack::factory()->create([
                'playlist_id' => $playlist->id,
                'track_id' => Track::factory()->create()->id,
                'position' => $startAt + $index,
            ]);
        }

        return [$reader, $playlist, $entries];
    }
}
