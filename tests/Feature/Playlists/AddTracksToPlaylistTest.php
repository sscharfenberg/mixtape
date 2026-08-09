<?php

namespace Tests\Feature\Playlists;

use App\Models\Collection;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `POST /playlists/{playlist}/tracks` — putting tracks into a playlist, from a detail page's
 * hero or from the play queue's menu.
 *
 * THE TWO BODY SHAPES are what most of this file is about, because they are the feature's one
 * structural decision: a hero sends `{ subject, id }` and the tracks are resolved here, while
 * the queue sends `{ tracks: [...] }` because it is client state the server has no live copy
 * of. Both end in the same append, so the tests that matter are the ones where the two shapes
 * could plausibly diverge — the ORDER entries land in, and what happens to ids that are
 * already there.
 *
 * WHAT A READER WOULD NOTICE GOING WRONG, and so what is pinned:
 *
 *   - a subject arrives in PLAYING order, not in whatever order the rows came back in. An
 *     album added to a playlist and then played must sound like the album.
 *   - nothing is added TWICE. The select hides a playlist that already holds the subject, but
 *     that answer is computed when the page renders and acted on when save is pressed, so the
 *     write has to filter again — and say so, since "nothing happened" is a different message
 *     from "twelve tracks were added".
 *   - positions CONTINUE from what is already there, contiguously. The column is documented as
 *     contiguous and the reorder path renumbers the whole set assuming it.
 *   - the playlist counts as CHANGED. The write is a bulk insert, which fires no model events,
 *     so PlaylistTrack::$touches never runs — both playlist pages print that date.
 */
class AddTracksToPlaylistTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $playlist = Playlist::factory()->create();
        $track = Track::factory()->create();

        $this->post("/playlists/{$playlist->id}/tracks", ['tracks' => [$track->id]])
            ->assertRedirect('/login');

        $this->assertDatabaseCount('playlist_tracks', 0);
    }

    public function test_a_stranger_gets_a_404_rather_than_a_403(): void
    {
        // 403 would confirm the playlist exists, on a box that is deliberately reachable from
        // the internet — the same rule every other playlist request here follows.
        $playlist = Playlist::factory()->create();
        $track = Track::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post("/playlists/{$playlist->id}/tracks", ['tracks' => [$track->id]])
            ->assertNotFound();

        $this->assertDatabaseCount('playlist_tracks', 0);
    }

    public function test_it_adds_one_song_by_subject_and_names_it_in_the_flash(): void
    {
        [$reader, $playlist] = $this->readerWithPlaylist();
        $song = Track::factory()->create(['name' => 'Paranoid Android']);

        $this->actingAs($reader)
            ->post("/playlists/{$playlist->id}/tracks", ['subject' => 'song', 'id' => $song->id])
            ->assertRedirect()
            ->assertSessionHas('type', 'success')
            ->assertSessionHas('message', fn (string $message): bool => str_contains($message, 'Paranoid Android'));

        $this->assertSame([$song->id], $this->trackIdsOf($playlist));
    }

    public function test_a_subject_arrives_in_playing_order_rather_than_row_order(): void
    {
        [$reader, $playlist] = $this->readerWithPlaylist();
        $album = Collection::factory()->create(['year' => 1997]);

        // Created back-to-front on purpose: insertion order is the one order the result must
        // NOT be in, or "add this album" would produce a playlist that plays it shuffled.
        $third = Track::factory()->create(['collection_id' => $album->id, 'disc' => 1, 'track' => 3]);
        $first = Track::factory()->create(['collection_id' => $album->id, 'disc' => 1, 'track' => 1]);
        $second = Track::factory()->create(['collection_id' => $album->id, 'disc' => 1, 'track' => 2]);

        $this->actingAs($reader)
            ->post("/playlists/{$playlist->id}/tracks", ['subject' => 'album', 'id' => $album->id])
            ->assertRedirect();

        $this->assertSame([$first->id, $second->id, $third->id], $this->trackIdsOf($playlist));
    }

    public function test_a_subject_leaves_audiobook_chapters_out(): void
    {
        // The same music-only scoping the four detail pages apply to `queueTracks`, so "add
        // this genre" and "play this genre" cannot come to mean different sets of songs.
        [$reader, $playlist] = $this->readerWithPlaylist();
        $album = Collection::factory()->create();

        $song = Track::factory()->create(['collection_id' => $album->id]);
        Track::factory()->audiobook()->create(['collection_id' => $album->id]);

        $this->actingAs($reader)
            ->post("/playlists/{$playlist->id}/tracks", ['subject' => 'album', 'id' => $album->id])
            ->assertRedirect();

        $this->assertSame([$song->id], $this->trackIdsOf($playlist));
    }

    public function test_it_appends_after_what_is_already_there_and_keeps_positions_contiguous(): void
    {
        [$reader, $playlist] = $this->readerWithPlaylist();
        $existing = Track::factory()->create();
        PlaylistTrack::factory()->create([
            'playlist_id' => $playlist->id,
            'track_id' => $existing->id,
            'position' => 0,
        ]);

        $album = Collection::factory()->create();
        Track::factory()->count(2)->sequence(['track' => 1], ['track' => 2])
            ->create(['collection_id' => $album->id]);

        $this->actingAs($reader)
            ->post("/playlists/{$playlist->id}/tracks", ['subject' => 'album', 'id' => $album->id])
            ->assertRedirect();

        $positions = DB::table('playlist_tracks')
            ->where('playlist_id', $playlist->id)
            ->orderBy('position')
            ->pluck('position')
            ->all();

        $this->assertSame([0, 1, 2], $positions);
    }

    public function test_it_counts_as_changing_the_playlist(): void
    {
        // A bulk insert fires no model events, so PlaylistTrack::$touches never runs — the
        // service touches the parent by hand, and both playlist pages print that date.
        [$reader, $playlist] = $this->readerWithPlaylist();
        $changedBefore = $playlist->updated_at;
        $song = Track::factory()->create();

        $this->travel(1)->minute();

        $this->actingAs($reader)
            ->post("/playlists/{$playlist->id}/tracks", ['subject' => 'song', 'id' => $song->id])
            ->assertRedirect();

        $this->assertTrue($playlist->fresh()->updated_at->greaterThan($changedBefore));
    }

    public function test_it_skips_what_the_playlist_already_holds(): void
    {
        [$reader, $playlist] = $this->readerWithPlaylist();
        $album = Collection::factory()->create();
        [$held, $fresh] = Track::factory()->count(2)->sequence(['track' => 1], ['track' => 2])
            ->create(['collection_id' => $album->id])
            ->all();

        PlaylistTrack::factory()->create([
            'playlist_id' => $playlist->id,
            'track_id' => $held->id,
            'position' => 0,
        ]);

        $this->actingAs($reader)
            ->post("/playlists/{$playlist->id}/tracks", ['subject' => 'album', 'id' => $album->id])
            ->assertRedirect()
            ->assertSessionHas('type', 'success');

        // One entry each — the held track is not there twice.
        $this->assertSame([$held->id, $fresh->id], $this->trackIdsOf($playlist));
    }

    public function test_adding_nothing_new_says_so_rather_than_claiming_success(): void
    {
        [$reader, $playlist] = $this->readerWithPlaylist();
        $song = Track::factory()->create();
        PlaylistTrack::factory()->create([
            'playlist_id' => $playlist->id,
            'track_id' => $song->id,
            'position' => 0,
        ]);

        $this->actingAs($reader)
            ->post("/playlists/{$playlist->id}/tracks", ['subject' => 'song', 'id' => $song->id])
            ->assertRedirect()
            // `info`, not `success` and not an error: the playlist already holds it, which is
            // very often exactly what the reader wanted to know.
            ->assertSessionHas('type', 'info');

        $this->assertDatabaseCount('playlist_tracks', 1);
    }

    public function test_the_queue_shape_keeps_the_order_it_was_sent_in(): void
    {
        // The queue is the reader's own arrangement, so — unlike a subject — the request's
        // order IS the answer and must not be re-sorted into playing order.
        [$reader, $playlist] = $this->readerWithPlaylist();
        $album = Collection::factory()->create();
        [$one, $two, $three] = Track::factory()->count(3)
            ->sequence(['track' => 1], ['track' => 2], ['track' => 3])
            ->create(['collection_id' => $album->id])
            ->all();

        $queued = [$three->id, $one->id, $two->id];

        $this->actingAs($reader)
            ->post("/playlists/{$playlist->id}/tracks", ['tracks' => $queued])
            ->assertRedirect();

        $this->assertSame($queued, $this->trackIdsOf($playlist));
    }

    public function test_the_queue_shape_survives_a_stale_id_and_a_repeated_one(): void
    {
        /*
         * Both cases are real rather than defensive. A queue restored from localStorage can
         * name a file the scanner has since removed — a foreign key would answer that with a
         * 500 — and a queue may legitimately hold the same song twice, which is not what "add
         * the queue to a playlist" means.
         */
        [$reader, $playlist] = $this->readerWithPlaylist();
        $song = Track::factory()->create();

        $this->actingAs($reader)
            ->post("/playlists/{$playlist->id}/tracks", [
                'tracks' => [$song->id, '3f0f1a1e-0000-4000-8000-000000000000', $song->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('type', 'success');

        $this->assertSame([$song->id], $this->trackIdsOf($playlist));
    }

    public function test_a_body_naming_neither_shape_is_rejected(): void
    {
        [$reader, $playlist] = $this->readerWithPlaylist();

        $this->actingAs($reader)
            ->post("/playlists/{$playlist->id}/tracks", [])
            ->assertSessionHasErrors(['subject', 'tracks']);
    }

    public function test_a_subject_the_app_does_not_have_a_page_for_is_rejected(): void
    {
        [$reader, $playlist] = $this->readerWithPlaylist();

        $this->actingAs($reader)
            ->post("/playlists/{$playlist->id}/tracks", ['subject' => 'playlist', 'id' => $playlist->id])
            ->assertSessionHasErrors('subject');
    }

    /**
     * A reader and one empty playlist of their own.
     *
     * @return array{User, Playlist}
     */
    private function readerWithPlaylist(): array
    {
        $reader = User::factory()->create();

        return [$reader, Playlist::factory()->for($reader)->create()];
    }

    /**
     * The playlist's track ids in stored order — what every assertion above is really about.
     *
     * @return list<string>
     */
    private function trackIdsOf(Playlist $playlist): array
    {
        return DB::table('playlist_tracks')
            ->where('playlist_id', $playlist->id)
            ->orderBy('position')
            ->pluck('track_id')
            ->all();
    }
}
