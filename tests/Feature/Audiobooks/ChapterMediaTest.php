<?php

namespace Tests\Feature\Audiobooks;

use App\Models\Collection;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * The audiobook media routes — a chapter's audio and cover, a book's cover and .zip.
 *
 * WHAT IS ACTUALLY NEW HERE IS THE ROUTE, not the plumbing: `Track::absolutePath()`,
 * `InternalRedirect`, `CoverService` and `AlbumArchive` were all type-agnostic already, each
 * resolving the area root from the collection's or track's own type. So these tests are
 * pointed at the two things that were missing and the one that can silently rot:
 *
 * - **The area root is the audiobook one.** A chapter's bytes live under
 *   `mixtape.library.paths.audiobooks`, and a route that resolved the music root would 404 on
 *   every book while every test using a music fixture still passed.
 * - **The guards, both ways.** `tracks` and `collections` are unified tables, so a song's id
 *   must not stream through the chapter route and an album's id must not download through the
 *   book route — and each must answer 404, not 403, because at that URL the row is not the
 *   kind of thing being asked for.
 * - **The nginx hand-off.** In production PHP sends no bytes at all, so the `X-Accel-Redirect`
 *   URI is the whole of what stands between a listener and the file. Books on this share sit
 *   in directories like `+ Diverse/Necrophobia 1/[Disc 2]/`, so the encoding is not
 *   theoretical.
 */
class ChapterMediaTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaRoot = sys_get_temp_dir().'/mixtape-audiobook-test-'.uniqid();
        File::ensureDirectoryExists($this->mediaRoot);

        config([
            'mixtape.library.paths.audiobooks' => $this->mediaRoot,
            // The direct path is the default everywhere but the live box; the hand-off test
            // opts in by setting this itself.
            'mixtape.stream.internal_prefix' => null,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaRoot);

        parent::tearDown();
    }

    /** Write bytes into the temp audiobook area and return the relative path `tracks.path` stores. */
    private function mediaFile(string $relativePath, string $bytes): string
    {
        $absolute = $this->mediaRoot.'/'.$relativePath;
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $bytes);

        return $relativePath;
    }

    /** A chapter whose file really exists, in a directory shaped like the real share's. */
    private function chapter(string $bytes = 'abcdefghij'): Track
    {
        return Track::factory()->audiobook()->create([
            'path' => $this->mediaFile('+ Diverse/Necrophobia 1/[Disc 2]/CD2 - 01 - Die Ratten.mp3', $bytes),
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $chapter = Track::factory()->audiobook()->create();

        $this->get("/audiobooks/chapters/{$chapter->id}/stream")->assertRedirect('/login');
    }

    public function test_it_streams_a_chapter_from_the_audiobook_area(): void
    {
        $chapter = $this->chapter();

        $response = $this->actingAs(User::factory()->create())
            ->get("/audiobooks/chapters/{$chapter->id}/stream")
            ->assertOk()
            ->assertHeader('content-type', 'audio/mpeg')
            ->assertHeader('accept-ranges', 'bytes');

        $this->assertSame('abcdefghij', $response->streamedContent());
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));
    }

    public function test_a_range_request_gets_exactly_the_bytes_it_asked_for(): void
    {
        // It matters more for a book than for a song: without a 206 you cannot seek into
        // hour three of a chapter, which is most of what listening to a book is.
        $chapter = $this->chapter();

        $response = $this->actingAs(User::factory()->create())
            ->get("/audiobooks/chapters/{$chapter->id}/stream", ['Range' => 'bytes=3-6'])
            ->assertStatus(206)
            ->assertHeader('content-range', 'bytes 3-6/10');

        $this->assertSame('defg', $response->streamedContent());
    }

    public function test_it_hands_off_to_nginx_with_an_encoded_uri(): void
    {
        config(['mixtape.stream.internal_prefix' => '/internal-media']);
        $chapter = $this->chapter();

        $this->actingAs(User::factory()->create())
            ->get("/audiobooks/chapters/{$chapter->id}/stream")
            ->assertOk()
            // The area key is `audiobooks` (TrackType::libraryPathKey), not the enum value,
            // and every segment is encoded — nginx URL-decodes the target, so a raw space or
            // `[` truncates the path and 404s a chapter that plays fine over the direct route.
            ->assertHeader(
                'X-Accel-Redirect',
                '/internal-media/audiobooks/%2B%20Diverse/Necrophobia%201/%5BDisc%202%5D/CD2%20-%2001%20-%20Die%20Ratten.mp3'
            );
    }

    public function test_a_song_cannot_be_streamed_through_the_chapter_route(): void
    {
        $song = Track::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/audiobooks/chapters/{$song->id}/stream")
            ->assertNotFound();
    }

    public function test_a_chapter_cannot_be_streamed_through_the_song_route(): void
    {
        // The mirror of the case above, and the one that was broken until today: a chapter
        // queued by the player asked /music/songs/{id}/stream and was refused by the music
        // guard, so a book played silence.
        $chapter = $this->chapter();

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$chapter->id}/stream")
            ->assertNotFound();
    }

    public function test_a_missing_file_is_a_404_rather_than_a_500(): void
    {
        // Rows and files go out of step whenever something is deleted between scans.
        $chapter = Track::factory()->audiobook()->create(['path' => 'gone/nothing.mp3']);

        $this->actingAs(User::factory()->create())
            ->get("/audiobooks/chapters/{$chapter->id}/stream")
            ->assertNotFound();
    }

    public function test_an_album_cannot_be_downloaded_through_the_audiobook_route(): void
    {
        $album = Collection::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/audiobooks/{$album->id}/download")
            ->assertNotFound();
    }

    public function test_an_album_cover_cannot_be_fetched_through_the_audiobook_route(): void
    {
        $album = Collection::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/audiobooks/{$album->id}/cover")
            ->assertNotFound();
    }

    public function test_a_book_downloads_as_a_zip_of_its_chapters(): void
    {
        $book = Collection::factory()->audiobook()->create(['name' => 'Necrophobia 1']);
        Track::factory()->audiobook()->create([
            'collection_id' => $book->id,
            'track' => 1,
            'path' => $this->mediaFile('Necrophobia/01.mp3', 'chapter-one'),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get("/audiobooks/{$book->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/zip');

        $body = $response->streamedContent();

        // Streamed, with an exact length known before a byte is written — which is what gives the
        // browser a progress bar rather than a spinner on a 673-chapter book. Asserted AGAINST THE
        // BODY rather than merely non-empty: if the arithmetic and the writer disagree, what the
        // browser sees is a truncated download.
        $this->assertSame((string) strlen($body), $response->headers->get('content-length'));
        $this->assertStringContainsString('Necrophobia', (string) $response->headers->get('content-disposition'));

        // THE ARCHIVE IS OPENED, which is the whole point of the test: a writer emitting a corrupt
        // central directory, the wrong file or no entries at all passes every header check above.
        // `CHECKCONS` is what makes the read a verification rather than a formality.
        $file = tempnam(sys_get_temp_dir(), 'mixtape-zip-');
        File::put($file, $body);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file, ZipArchive::CHECKCONS) === true, 'The archive did not open.');
        $this->assertSame(1, $zip->numFiles);
        $this->assertSame('chapter-one', $zip->getFromIndex(0));
        $zip->close();
        File::delete($file);
    }

    public function test_a_book_with_no_files_left_is_a_404_rather_than_an_empty_zip(): void
    {
        $book = Collection::factory()->audiobook()->create();
        Track::factory()->audiobook()->create(['collection_id' => $book->id, 'path' => 'gone/nothing.mp3']);

        $this->actingAs(User::factory()->create())
            ->get("/audiobooks/{$book->id}/download")
            ->assertNotFound();
    }
}
