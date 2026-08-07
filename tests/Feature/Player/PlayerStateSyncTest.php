<?php

namespace Tests\Feature\Player;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\PlayerState;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The play queue's server sync: `PUT /player/state` going up, the `playerState` shared prop
 * coming back down.
 *
 * WHAT IS WORTH A SERVER TEST HERE is everything the browser cannot see. The client half is
 * covered in Vitest against a fake fetch — it can prove a request was SENT, never that a row
 * was written, that another user's row stayed out of it, or that a queue survived the
 * library losing one of its files.
 *
 * Four of these tests are about the read half being conservative, and each guards a way the
 * feature could quietly destroy a queue rather than restore one:
 *
 * - **Null, not empty, when there is nothing stored.** The client reads null as "keep what
 *   localStorage has". Return an empty queue instead and the first page load after signing
 *   in on a second device wipes the queue on the first.
 * - **Only on a full page load.** The prop is skipped for client-side visits, where the
 *   persistent layout already holds a live queue this could only contradict.
 * - **Stored order, not query order.** QueuePayload sorts by album-disc-track, which is the
 *   right order for "play this artist" and the wrong one for a list somebody dragged into
 *   shape.
 * - **A missing track is skipped and the pointer follows.** Files disappear between scans;
 *   a queue that came back with holes would break the player, and one whose pointer stayed
 *   put would resume on the wrong song.
 */
class PlayerStateSyncTest extends TestCase
{
    use RefreshDatabase;

    /** Music tracks that share one artist/album/genre, so the factory's unique pools survive. */
    private function tracks(int $count): array
    {
        return Track::factory()->count($count)->create([
            'artist_id' => Artist::factory()->create()->id,
            'collection_id' => Collection::factory()->create()->id,
            'genre_id' => Genre::factory()->create()->id,
        ])->all();
    }

    /** A body the controller accepts, with `tracks` filled in by the caller. */
    private function payload(array $ids, int $currentIndex = 0, bool $repeat = false, bool $shuffle = false, int $updatedAt = 1_000, int $positionMs = 0): array
    {
        return [
            'tracks' => $ids,
            'currentIndex' => $currentIndex,
            'repeat' => $repeat,
            'shuffle' => $shuffle,
            'updatedAt' => $updatedAt,
            'positionMs' => $positionMs,
        ];
    }

    /**
     * Visit a page the way Inertia's own client does.
     *
     * The version comes from the MIDDLEWARE rather than `Inertia::getVersion()`, for the
     * reason SubjectQueueTest documents at length: read before any request has run, the
     * facade's value is an empty string, and the mismatch answers 409 — which in a test
     * looks exactly like the prop being missing.
     */
    private function inertiaVisit(string $url)
    {
        return $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(Request::create($url)),
        ])->get($url);
    }

    public function test_a_guest_cannot_store_a_queue(): void
    {
        $this->putJson('/player/state', $this->payload([]))->assertUnauthorized();
    }

    public function test_it_stores_the_queue_for_the_signed_in_user(): void
    {
        $user = User::factory()->create();
        $tracks = $this->tracks(3);
        $ids = array_map(fn (Track $track) => $track->id, $tracks);

        $this->actingAs($user)
            ->putJson('/player/state', $this->payload($ids, currentIndex: 2, repeat: true, shuffle: true))
            ->assertNoContent();

        $stored = PlayerState::query()->whereKey($user->id)->value('queue');

        // Ids only — the tracks themselves are one join away, and a title stored here would
        // go stale the moment the file is re-tagged.
        $this->assertSame($ids, $stored['tracks']);
        $this->assertSame(2, $stored['currentIndex']);
        $this->assertTrue($stored['repeat']);
        $this->assertTrue($stored['shuffle']);
    }

    public function test_a_second_write_replaces_the_first_rather_than_adding_a_row(): void
    {
        // The row is read and written WHOLESALE (which is why it is one JSON blob), so the
        // upsert is the whole write path — a second row per user would be a queue nobody
        // can resolve.
        $user = User::factory()->create();
        $tracks = $this->tracks(2);

        $this->actingAs($user)->putJson('/player/state', $this->payload([$tracks[0]->id]))->assertNoContent();
        $this->actingAs($user)->putJson('/player/state', $this->payload([$tracks[1]->id], currentIndex: 0))->assertNoContent();

        $this->assertSame(1, PlayerState::query()->count());
        $this->assertSame([$tracks[1]->id], PlayerState::query()->whereKey($user->id)->value('queue')['tracks']);
    }

    public function test_an_emptied_queue_is_synced_as_empty(): void
    {
        // Clearing the queue on one device must not leave the other restoring it forever,
        // so -1 ("nothing loaded") and an empty list are a legitimate state to store.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/player/state', $this->payload([], currentIndex: -1))
            ->assertNoContent();

        $this->assertSame([], PlayerState::query()->whereKey($user->id)->value('queue')['tracks']);
    }

    public function test_it_rejects_a_queue_that_is_not_a_list_of_uuids(): void
    {
        $this->actingAs(User::factory()->create())
            ->putJson('/player/state', $this->payload(['not-a-uuid']))
            ->assertJsonValidationErrors('tracks.0');
    }

    public function test_it_rejects_a_body_missing_the_pointer(): void
    {
        $this->actingAs(User::factory()->create())
            ->putJson('/player/state', ['tracks' => []])
            ->assertJsonValidationErrors(['currentIndex', 'repeat', 'shuffle', 'updatedAt', 'positionMs']);
    }

    public function test_a_write_older_than_the_stored_one_is_ignored(): void
    {
        /*
         * Closing a stale tab must not roll the server back. That tab flushes on its way out
         * and its queue is whatever it was holding when it was abandoned — so two tabs open,
         * fifty tracks queued in the second, close the first, and without this the fifty are
         * gone. The newest stamp wins in both directions: the browser applies the same rule
         * to what it is handed back.
         */
        $user = User::factory()->create();
        $tracks = $this->tracks(2);

        $this->actingAs($user)->putJson('/player/state', $this->payload([$tracks[0]->id, $tracks[1]->id], updatedAt: 2_000));
        // The abandoned tab, flushing a queue it built earlier.
        $this->actingAs($user)->putJson('/player/state', $this->payload([$tracks[0]->id], updatedAt: 1_000))->assertNoContent();

        $this->assertCount(2, PlayerState::query()->whereKey($user->id)->value('queue')['tracks']);
    }

    public function test_a_page_load_carries_the_stored_queue_in_its_stored_order(): void
    {
        $user = User::factory()->create();
        $tracks = $this->tracks(3);
        // Deliberately NOT the order QueuePayload would sort these into: this is a list
        // somebody dragged into shape, and it has to come back the way they left it.
        $ids = [$tracks[2]->id, $tracks[0]->id, $tracks[1]->id];

        $this->actingAs($user)->putJson('/player/state', $this->payload($ids, currentIndex: 1, repeat: true));

        $this->actingAs($user)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->where('playerState.tracks.0.id', $ids[0])
                ->where('playerState.tracks.1.id', $ids[1])
                ->where('playerState.tracks.2.id', $ids[2])
                ->where('playerState.currentIndex', 1)
                ->where('playerState.repeat', true)
                ->where('playerState.shuffle', false)
                // The full client shape, not ids: the browser has no REST API to look
                // them up with.
                ->has('playerState.tracks.0', fn (Assert $track) => $track
                    ->hasAll(['id', 'name', 'artist', 'album', 'duration', 'coverUrl', 'href', 'streamUrl'])
                )
            );
    }

    public function test_a_track_the_library_no_longer_has_is_skipped_and_the_pointer_follows(): void
    {
        $user = User::factory()->create();
        $tracks = $this->tracks(3);
        $ids = array_map(fn (Track $track) => $track->id, $tracks);

        // Playing the third track, then the first file disappears between scans.
        $this->actingAs($user)->putJson('/player/state', $this->payload($ids, currentIndex: 2));
        $tracks[0]->delete();

        $this->actingAs($user)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->count('playerState.tracks', 2)
                ->where('playerState.tracks.0.id', $ids[1])
                // Still the same SONG, one place further up the list.
                ->where('playerState.currentIndex', 1)
            );
    }

    public function test_the_client_stamp_comes_back_untouched(): void
    {
        // It is the CLIENT's clock, and the browser compares it with its own copy's stamp
        // to decide which is newer. A value this server rewrote — with `now()`, say — would
        // be comparing two different clocks, and the newer copy would lose about as often
        // as it won.
        $user = User::factory()->create();
        $tracks = $this->tracks(1);

        $this->actingAs($user)->putJson('/player/state', $this->payload([$tracks[0]->id], updatedAt: 1_754_000_000_000));

        $this->actingAs($user)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page->where('playerState.updatedAt', 1_754_000_000_000));
    }

    public function test_it_stores_and_returns_how_far_into_the_track_the_listener_had_got(): void
    {
        // Milliseconds, and the server's only job is to keep them: whether a position is
        // worth resuming at all — too early in the track, too near its end — is the
        // client's rule, applied where the element and the duration both are.
        $user = User::factory()->create();
        $tracks = $this->tracks(1);

        $this->actingAs($user)->putJson('/player/state', $this->payload([$tracks[0]->id], positionMs: 96_500));

        $this->actingAs($user)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page->where('playerState.positionMs', 96_500));
    }

    public function test_it_rejects_a_position_no_track_could_reach(): void
    {
        // A day in milliseconds is the cap — it bounds a hand-written request rather than
        // anything a listener can do.
        $this->actingAs(User::factory()->create())
            ->putJson('/player/state', $this->payload([], positionMs: 90_000_000))
            ->assertJsonValidationErrors('positionMs');
    }

    public function test_a_user_with_no_stored_queue_gets_null_rather_than_an_empty_one(): void
    {
        // Null is what tells the client to keep what localStorage has. An empty queue here
        // would wipe a perfectly good local one on the first load after signing in.
        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page->where('playerState', null));
    }

    public function test_one_user_never_receives_another_users_queue(): void
    {
        // This instance is deliberately shared with family and friends.
        $tracks = $this->tracks(1);
        $owner = User::factory()->create();
        $this->actingAs($owner)->putJson('/player/state', $this->payload([$tracks[0]->id]));

        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page->where('playerState', null));
    }

    public function test_a_client_side_visit_does_not_carry_the_queue(): void
    {
        // The persistent layout already holds a live queue on those visits, so the prop
        // could only contradict it — and it would cost a queue's worth of JSON per click.
        $user = User::factory()->create();
        $tracks = $this->tracks(2);
        $this->actingAs($user)->putJson('/player/state', $this->payload(array_map(fn (Track $t) => $t->id, $tracks)));

        $this->actingAs($user)
            ->inertiaVisit('/music')
            ->assertOk()
            ->assertJsonPath('props.playerState', null);
    }
}
