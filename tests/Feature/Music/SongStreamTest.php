<?php

namespace Tests\Feature\Music;

use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The song stream endpoint (`/music/songs/{song}/stream`, behind auth) — the
 * `src` the player's <audio> element loads.
 *
 * Three things here are worth a test each, because each fails in a way the app
 * cannot recover from and none of them is visible from the page:
 *
 * - **HTTP Range.** Without a `206` plus a correct `Content-Range`, dragging the
 *   timeline past what is already buffered simply does nothing — the browser asks
 *   for a byte offset, gets the whole file with a `200`, and gives up on seeking.
 *   The bytes are asserted, not just the status: an off-by-one on the slice is
 *   exactly the mistake a status-only test sails past.
 * - **The nginx hand-off.** In production PHP sends no bytes at all, so the only
 *   thing standing between a listener and the file is the `X-Accel-Redirect` URI.
 *   It is built from the area key and URL-encoded, and this collection is full of
 *   spaces, umlauts and `#` in file names — an unencoded one truncates the path
 *   at the fragment and 404s a track that plays fine over the direct route.
 * - **The guards.** Auth, the music-only type check (the `tracks` table also holds
 *   audiobook chapters), and a row whose file has gone missing between scans.
 *
 * Each test writes a real file into a throwaway media area and points
 * `mixtape.library.paths.music` at it, the same arrangement SongCoverTest uses:
 * the whole point of the controller is handing over bytes from disk.
 */
class SongStreamTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaRoot;

    /** Point the music area at an empty temp dir, and stream through PHP unless a test says otherwise. */
    protected function setUp(): void
    {
        parent::setUp();

        // The system temp dir rather than somewhere under the repo: throwaway media,
        // and a test should not leave a directory behind in the tree.
        $this->mediaRoot = sys_get_temp_dir().'/mixtape-stream-test-'.uniqid();
        File::ensureDirectoryExists($this->mediaRoot);

        config([
            'mixtape.library.paths.music' => $this->mediaRoot,
            // The direct path is the default everywhere but the live box; the
            // hand-off tests opt in by setting this themselves.
            'mixtape.stream.internal_prefix' => null,
        ]);
    }

    /** Remove the temp media area. */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaRoot);

        parent::tearDown();
    }

    /**
     * Write $bytes into the temp media area at $relativePath and return that
     * relative path, which is what `tracks.path` stores.
     *
     * Synthetic bytes rather than the committed mp3 fixture: what is under test is
     * byte-range arithmetic, and a known, countable payload is what makes an
     * off-by-one visible.
     */
    private function mediaFile(string $relativePath, string $bytes): string
    {
        $absolute = $this->mediaRoot.'/'.$relativePath;
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $bytes);

        return $relativePath;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $song = Track::factory()->create();

        $this->get("/music/songs/{$song->id}/stream")->assertRedirect('/login');
    }

    public function test_it_sends_the_whole_file_with_a_range_offer(): void
    {
        $song = Track::factory()->create([
            'path' => $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3', 'abcdefghij'),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/stream")
            ->assertOk()
            ->assertHeader('content-type', 'audio/mpeg')
            ->assertHeader('content-length', '10')
            // Announcing this is what makes a browser attempt to seek at all.
            ->assertHeader('accept-ranges', 'bytes');

        $this->assertSame('abcdefghij', $response->streamedContent());

        // `private`, never `public`: this instance is internet-facing behind auth, so
        // a shared proxy must not end up holding somebody's music.
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));
    }

    public function test_a_range_request_gets_exactly_the_bytes_it_asked_for(): void
    {
        $song = Track::factory()->create([
            'path' => $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3', 'abcdefghij'),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/stream", ['Range' => 'bytes=3-6'])
            ->assertStatus(206)
            ->assertHeader('content-range', 'bytes 3-6/10')
            ->assertHeader('content-length', '4');

        $this->assertSame('defg', $response->streamedContent());
    }

    public function test_an_open_ended_range_runs_to_the_end_of_the_file(): void
    {
        // What a browser actually sends when it resumes after a seek: "from here on".
        $song = Track::factory()->create([
            'path' => $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3', 'abcdefghij'),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/stream", ['Range' => 'bytes=7-'])
            ->assertStatus(206)
            ->assertHeader('content-range', 'bytes 7-9/10');

        $this->assertSame('hij', $response->streamedContent());
    }

    public function test_a_range_past_the_end_of_the_file_is_refused(): void
    {
        $song = Track::factory()->create([
            'path' => $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3', 'abcdefghij'),
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/stream", ['Range' => 'bytes=50-60'])
            ->assertStatus(416)
            ->assertHeader('content-range', 'bytes */10');
    }

    public function test_an_empty_internal_prefix_streams_the_file_instead_of_handing_off(): void
    {
        /*
         * The regression that took the dev site down, and the reason `=== null` is the
         * wrong test. `.env` ships this key BLANK, and a blank dotenv value arrives as
         * an empty STRING — so a null check ran the nginx hand-off with no prefix at
         * all, emitting `X-Accel-Redirect: /music/<path>`. nginx internally redirected
         * to a URI nothing serves, `try_files` bounced it back into index.php, and the
         * result was a 500 ("rewrite or internal redirection cycle") with NOTHING in
         * Laravel's log, because no PHP exception was ever thrown.
         *
         * Empty means "no nginx in front" — the same rule an unconfigured library area
         * follows (LibraryScanService::scanArea).
         */
        config(['mixtape.stream.internal_prefix' => '']);

        $song = Track::factory()->create([
            'path' => $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3', 'abcdefghij'),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/stream")
            ->assertOk()
            ->assertHeaderMissing('X-Accel-Redirect');

        $this->assertSame('abcdefghij', $response->streamedContent());
    }

    public function test_a_whitespace_only_internal_prefix_is_also_treated_as_unset(): void
    {
        // Same rule, one stray space further on. `trim()` rather than a bare `=== ''`
        // for the same reason the scanner trims its area paths: a hand-edited `.env`
        // picks up trailing whitespace, and " " must not mean "hand off to nginx".
        config(['mixtape.stream.internal_prefix' => '   ']);

        $song = Track::factory()->create([
            'path' => $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3', 'abcdefghij'),
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/stream")
            ->assertOk()
            ->assertHeaderMissing('X-Accel-Redirect');
    }

    public function test_it_hands_off_to_nginx_when_an_internal_prefix_is_configured(): void
    {
        config(['mixtape.stream.internal_prefix' => '/internal-media']);

        $song = Track::factory()->create([
            'path' => $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3', 'abcdefghij'),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/stream")
            ->assertOk()
            // `music` is the AREA key (TrackType::libraryPathKey), which is what the
            // vhost's `internal;` location is named after — no path arithmetic.
            ->assertHeader(
                'X-Accel-Redirect',
                '/internal-media/music/The%20Storm/Thunder%20Road/02%20-%20Lightning%20Strikes.mp3'
            );

        // PHP sends nothing at all — that is the entire point of the hand-off.
        $this->assertSame('', $response->getContent());
    }

    public function test_the_hand_off_uri_survives_the_characters_this_collection_actually_uses(): void
    {
        // Real file names here carry `#`, `&`, `+` and umlauts. nginx URL-DECODES the
        // redirect target, so an unencoded `#` would cut the path at the fragment and
        // 404 a track that plays perfectly well over the direct route. The slashes,
        // though, must stay slashes — they are the directory structure.
        config(['mixtape.stream.internal_prefix' => '/internal-media']);

        $song = Track::factory()->create([
            'path' => $this->mediaFile('Mötley Crüe/Girls, Girls & Girls/01 - #1 Hit + More.mp3', 'x'),
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/stream")
            ->assertHeader(
                'X-Accel-Redirect',
                '/internal-media/music/M%C3%B6tley%20Cr%C3%BCe/Girls%2C%20Girls%20%26%20Girls/01%20-%20%231%20Hit%20%2B%20More.mp3'
            );
    }

    public function test_a_missing_file_is_a_404_rather_than_an_error(): void
    {
        // The row and the file go out of step whenever something is deleted between
        // library scans. A dead <audio> src is the honest answer; a 500 is not.
        $song = Track::factory()->create(['path' => 'Gone/Missing.mp3']);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/stream")
            ->assertNotFound();
    }

    public function test_a_missing_file_is_still_a_404_with_the_nginx_hand_off_on(): void
    {
        // The check must not be skipped just because nginx would serve the bytes:
        // without it the app would answer 200 and nginx would answer its own 404
        // page — an HTML body under a Content-Type of audio/mpeg.
        config(['mixtape.stream.internal_prefix' => '/internal-media']);

        $song = Track::factory()->create(['path' => 'Gone/Missing.mp3']);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/stream")
            ->assertNotFound();
    }

    public function test_an_audiobook_chapter_is_not_streamable_under_music(): void
    {
        $chapter = Track::factory()->audiobook()->create();

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$chapter->id}/stream")
            ->assertNotFound();
    }

    public function test_the_page_hands_the_stream_url_to_the_queue(): void
    {
        // Unlike `coverUrl` this is unconditional — a track always has bytes, and the
        // queue stores whole tracks rather than ids, so the URL has to travel with them.
        $song = Track::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertInertia(fn ($page) => $page->where('song.streamUrl', "/music/songs/{$song->id}/stream"));
    }
}
