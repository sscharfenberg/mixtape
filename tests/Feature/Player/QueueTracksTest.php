<?php

namespace Tests\Feature\Player;

use App\Http\Requests\Player\UpdatePlayerStateRequest;
use App\Models\Collection;
use App\Models\Track;
use App\Models\User;
use App\Services\Playlists\PlaylistAdditions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST /queue/tracks` — what a listing's ticked checkboxes mean, in queue entries.
 *
 * THE ONE THING THIS ENDPOINT DECIDES that nothing else does is the audiobook question, and it
 * answers it the OPPOSITE way to the playlist path on purpose. A `song` selection is exact track
 * ids, so a ticked chapter must survive; every other subject names a container, where "which of
 * its tracks" is the question this app already answers with "the music ones". Those two tests
 * are the reason QueueSelection exists as its own service rather than reusing PlaylistAdditions,
 * so they are the ones that would catch a well-meaning merge of the two.
 *
 * The rest is the shape contract the client cannot check for itself: entries carry the eight
 * fields `QueueTrack` declares, and they arrive in PLAYING order across the whole selection
 * rather than in the order the ids were listed.
 */
class QueueTracksTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_refused(): void
    {
        $track = Track::factory()->create();

        $this->postJson('/queue/tracks', ['subject' => 'song', 'ids' => [$track->id]])
            ->assertUnauthorized();
    }

    public function test_a_song_selection_answers_with_queue_entries(): void
    {
        $track = Track::factory()->create(['name' => 'Everything In Its Right Place', 'cover' => true]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/queue/tracks', ['subject' => 'song', 'ids' => [$track->id]])
            ->assertOk();

        // The eight fields usePlayerQueue's QueueTrack declares, and no ninth: the player takes
        // whatever it is handed and would fail later on the one that was missing.
        $response->assertJsonCount(1)
            ->assertJsonPath('0.id', $track->id)
            ->assertJsonPath('0.name', 'Everything In Its Right Place')
            ->assertJsonPath('0.href', "/music/songs/{$track->id}")
            ->assertJsonPath('0.streamUrl', "/music/songs/{$track->id}/stream")
            ->assertJsonPath('0.coverUrl', "/music/songs/{$track->id}/cover");

        $this->assertSame(
            ['album', 'artist', 'coverUrl', 'duration', 'href', 'id', 'name', 'streamUrl'],
            $this->sortedKeysOf($response->json('0'))
        );
    }

    public function test_a_song_selection_keeps_audiobook_chapters(): void
    {
        // THE DIVERGENCE FROM THE PLAYLIST PATH. A reader ticking chapters on a book's page has
        // named each one; filtering them out here would empty the queue with nothing to explain
        // it, which is exactly the silent failure QueuePayload's nullable filter exists for.
        $chapter = Track::factory()->audiobook()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/queue/tracks', ['subject' => 'song', 'ids' => [$chapter->id]])
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $chapter->id);
    }

    public function test_a_container_subject_leaves_audiobook_chapters_out(): void
    {
        // …and the other half of the same rule: an artist's narration is not part of "play this
        // artist", so a subject that names a container stays music-only.
        $album = Collection::factory()->create();

        $song = Track::factory()->create(['collection_id' => $album->id]);
        Track::factory()->audiobook()->create(['collection_id' => $album->id]);

        $this->actingAs(User::factory()->create())
            ->postJson('/queue/tracks', ['subject' => 'album', 'ids' => [$album->id]])
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $song->id);
    }

    public function test_several_subjects_arrive_in_one_playing_order(): void
    {
        // Ordered across the whole selection rather than id-list by id-list — the ids go up
        // oldest-first so the two possible answers differ.
        $older = Collection::factory()->create(['year' => 1994]);
        $newer = Collection::factory()->create(['year' => 2001]);

        $olderTrack = Track::factory()->create(['collection_id' => $older->id, 'disc' => 1, 'track' => 1]);
        $newerTrack = Track::factory()->create(['collection_id' => $newer->id, 'disc' => 1, 'track' => 1]);

        $this->actingAs(User::factory()->create())
            ->postJson('/queue/tracks', ['subject' => 'album', 'ids' => [$older->id, $newer->id]])
            ->assertOk()
            ->assertJsonPath('0.id', $newerTrack->id)
            ->assertJsonPath('1.id', $olderTrack->id);
    }

    public function test_an_id_with_no_row_simply_comes_back_without_it(): void
    {
        // Ids are checked for SHAPE, not existence — a selection naming a row the scanner has
        // since removed answers with what is left rather than failing whole.
        $track = Track::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/queue/tracks', [
                'subject' => 'song',
                'ids' => [$track->id, (string) Str::uuid()],
            ])
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $track->id);
    }

    public function test_the_answer_is_never_stored(): void
    {
        $track = Track::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/queue/tracks', ['subject' => 'song', 'ids' => [$track->id]])
            // Symfony sorts the directives, which is why this reads back the other way round
            // from how the controller sets it — SearchTest pins the same normalised form.
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_a_body_naming_no_subject_or_no_ids_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/queue/tracks', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject', 'ids']);
    }

    public function test_an_empty_id_list_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/queue/tracks', ['subject' => 'album', 'ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');
    }

    public function test_a_subject_this_app_has_no_kind_for_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/queue/tracks', ['subject' => 'playlist', 'ids' => [(string) Str::uuid()]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subject');
    }

    public function test_more_subjects_than_the_ceiling_are_rejected(): void
    {
        $ids = array_map(fn (): string => (string) Str::uuid(), range(1, PlaylistAdditions::MAX_SUBJECTS + 1));

        $this->actingAs(User::factory()->create())
            ->postJson('/queue/tracks', ['subject' => 'album', 'ids' => $ids])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');
    }

    public function test_the_expansion_ceiling_is_wired_to_something_real(): void
    {
        /*
         * THE ONE BRANCH NO FIXTURE CAN REACH. Tripping it needs a selection resolving to more
         * than twenty thousand tracks, which is a database this suite has no business building
         * for one assertion — so what is checked instead is the two ways it could be silently
         * broken and still look fine in review:
         *
         *   - the ceiling naming a constant that has moved or been renamed, which is a fatal on
         *     a request nothing else exercises;
         *   - the message resolving to its own key, which is what a missing lang file looks like
         *     — and it would reach a reader as the literal string "selection.too_large".
         *
         * The rule ITSELF (that a too-large selection is refused) is held by the code path
         * being one comparison long. This is about the two references either side of it.
         */
        $this->assertGreaterThan(0, UpdatePlayerStateRequest::MAX_TRACKS);

        $message = __('player.validation')['selection.too_large'];

        $this->assertIsString($message);
        $this->assertNotSame('selection.too_large', $message);
        $this->assertStringNotContainsString('player.validation', $message);
    }

    /**
     * One entry's field names, sorted — so the shape assertion reads as a set rather than
     * depending on the order QueuePayload happens to build them in.
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function sortedKeysOf(array $entry): array
    {
        $keys = array_keys($entry);
        sort($keys);

        return $keys;
    }
}
