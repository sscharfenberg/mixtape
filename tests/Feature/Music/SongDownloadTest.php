<?php

namespace Tests\Feature\Music;

use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The song download (`/music/songs/{song}/download`, behind auth) — the hero's download
 * button.
 *
 * It sends the same bytes as the stream route beside it, so what is worth testing is
 * only what DIFFERS, plus the guards the two share:
 *
 * - **`Content-Disposition: attachment`, with the file's own name.** Without it the
 *   browser plays the mp3 in a tab instead of saving it, which is not a subtle failure
 *   but is an invisible one from the server's side. The name is asserted with an UMLAUT
 *   in it, because that is the case where a hand-built header goes wrong: this
 *   collection is full of them, and the RFC 5987 `filename*` parameter is the only half
 *   of the header that can carry one.
 * - **The nginx hand-off.** In production PHP sends no bytes at all, so the disposition
 *   has to survive onto a response whose body is empty.
 * - **The gate.** Auth, and the music-only type check — an audiobook chapter shares the
 *   `tracks` table and must not be downloadable under /music.
 *
 * The arrangement is SongStreamTest's: a real file in a throwaway media area with
 * `mixtape.library.paths.music` pointed at it, because the whole point of the route is
 * handing over bytes from disk.
 */
class SongDownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaRoot;

    /** Point the music area at an empty temp dir, and send files directly unless a test says otherwise. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaRoot = sys_get_temp_dir().'/mixtape-download-test-'.uniqid();
        File::ensureDirectoryExists($this->mediaRoot);

        config([
            'mixtape.library.paths.music' => $this->mediaRoot,
            'mixtape.stream.internal_prefix' => null,
        ]);
    }

    /** Remove the temp media area. */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaRoot);

        parent::tearDown();
    }

    /** Write $bytes into the temp media area at $relativePath and return that relative path. */
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

        $this->get("/music/songs/{$song->id}/download")->assertRedirect('/login');
    }

    public function test_it_sends_the_file_as_an_attachment_under_its_own_name(): void
    {
        $song = Track::factory()->create([
            'path' => $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3', 'abcdefghij'),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'audio/mpeg');

        // The FILE's name, not one rebuilt from tags: the collection's naming is
        // deliberate, and a download that renames things fights it.
        $this->assertStringContainsString(
            'attachment; filename="02 - Lightning Strikes.mp3"',
            (string) $response->headers->get('content-disposition')
        );

        $this->assertSame('abcdefghij', $response->streamedContent());

        // `private`, never `public`: this instance is internet-facing behind auth, so a
        // shared proxy must not end up holding somebody's music.
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));
    }

    public function test_a_name_with_an_umlaut_travels_in_both_filename_parameters(): void
    {
        // The case a concatenated header gets wrong. `filename` may hold nothing but
        // printable ASCII, so the umlaut has to survive in `filename*` — and the plain
        // parameter still has to be there for a client that reads only that one.
        $song = Track::factory()->create([
            'path' => $this->mediaFile('Mötley Crüe/Dr. Feelgood/01 - Kickstart My Härz.mp3', 'x'),
        ]);

        $disposition = (string) $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/download")
            ->headers->get('content-disposition');

        $this->assertStringContainsString('filename="01 - Kickstart My H_rz.mp3"', $disposition);
        $this->assertStringContainsString("filename*=utf-8''01%20-%20Kickstart%20My%20H%C3%A4rz.mp3", $disposition);
    }

    public function test_a_percent_sign_is_kept_out_of_the_ascii_fallback(): void
    {
        // A `%` in the fallback is refused outright by the header builder (a reader
        // cannot tell it from the start of a percent-escape), so it must be replaced
        // before it gets there — and the real name still travels in `filename*`.
        $song = Track::factory()->create([
            'path' => $this->mediaFile('Sale/Album/100% Pure.mp3', 'x'),
        ]);

        $disposition = (string) $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/download")
            ->headers->get('content-disposition');

        $this->assertStringContainsString('filename="100_ Pure.mp3"', $disposition);
        $this->assertStringContainsString('100%25%20Pure.mp3', $disposition);
    }

    public function test_it_hands_off_to_nginx_and_still_says_attachment(): void
    {
        // On the live box PHP sends no bytes at all — so the disposition, which is the
        // whole difference between this route and the stream, has to ride on an empty
        // response for nginx to pass through.
        config(['mixtape.stream.internal_prefix' => '/internal-media']);

        $song = Track::factory()->create([
            'path' => $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3', 'abcdefghij'),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/download")
            ->assertOk()
            ->assertHeader(
                'X-Accel-Redirect',
                '/internal-media/music/The%20Storm/Thunder%20Road/02%20-%20Lightning%20Strikes.mp3'
            );

        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition')
        );

        $this->assertSame('', $response->getContent());
    }

    public function test_a_missing_file_is_a_404_rather_than_an_error(): void
    {
        $song = Track::factory()->create(['path' => 'Gone/Missing.mp3']);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/download")
            ->assertNotFound();
    }

    public function test_a_missing_file_is_still_a_404_with_the_nginx_hand_off_on(): void
    {
        // Without the check the app would answer 200 and nginx would serve its own 404
        // page — as an attachment called `.mp3`.
        config(['mixtape.stream.internal_prefix' => '/internal-media']);

        $song = Track::factory()->create(['path' => 'Gone/Missing.mp3']);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/download")
            ->assertNotFound();
    }

    public function test_an_audiobook_chapter_is_not_downloadable_under_music(): void
    {
        $chapter = Track::factory()->audiobook()->create();

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$chapter->id}/download")
            ->assertNotFound();
    }

    public function test_the_page_hands_the_download_url_to_the_hero(): void
    {
        $song = Track::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}")
            ->assertInertia(fn ($page) => $page->where('song.downloadUrl', "/music/songs/{$song->id}/download"));
    }
}
