<?php

namespace Tests\Feature\Music;

use App\Models\Track;
use App\Models\User;
use getID3;
use getid3_writetags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The song cover endpoint (`/music/songs/{song}/cover`, behind auth) and the
 * CoverService behind it: extract from the file once, cache the JPEG, serve it.
 *
 * Every test builds its own throwaway media area on disk and points
 * `mixtape.library.paths.music` at it, because the whole point of the service is
 * reading real files — an embedded ID3v2 picture (written here with getID3's own
 * tag writer, so the extraction is exercised against a genuine APIC frame) or a
 * `Folder.jpg` beside the audio.
 */
class SongCoverTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaRoot;

    /** Point the music area at an empty temp dir, and keep the cover cache out of the real storage dir. */
    protected function setUp(): void
    {
        parent::setUp();

        // The system temp dir, not somewhere under the repo: this is throwaway
        // media, and a test should not leave a directory behind in the tree.
        $this->mediaRoot = sys_get_temp_dir().'/mixtape-cover-test-'.uniqid();
        File::ensureDirectoryExists($this->mediaRoot);

        config(['mixtape.library.paths.music' => $this->mediaRoot]);

        File::deleteDirectory(storage_path('app/private/covers'));
    }

    /** Remove the temp media area and any cover this test cached. */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaRoot);
        File::deleteDirectory(storage_path('app/private/covers'));

        parent::tearDown();
    }

    /**
     * A square JPEG of the given size, as raw bytes — stands in for both an
     * embedded picture and a Folder.jpg. Bigger than the configured cover width by
     * default so the scale-down is what gets asserted.
     */
    private function jpeg(int $size = 900): string
    {
        $image = imagecreatetruecolor($size, $size);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 16, 134)); // hot pink, why not

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }

    /**
     * Copy the committed synthetic mp3 into the temp media area at $relativePath,
     * optionally writing an ID3v2 APIC frame carrying $picture into it. Returns the
     * relative path, which is what `tracks.path` stores.
     */
    private function mediaFile(string $relativePath, ?string $picture = null): string
    {
        $absolute = $this->mediaRoot.'/'.$relativePath;
        File::ensureDirectoryExists(dirname($absolute));
        File::copy(base_path('tests/Fixtures/audio/tagged.mp3'), $absolute);

        if ($picture !== null) {
            // getID3's writer refuses to load unless getid3.php came first, and the
            // composer autoloader alone doesn't satisfy that — so touch getID3
            // before naming the writer class.
            new getID3;

            $writer = new getid3_writetags;
            $writer->filename = $absolute;
            $writer->tagformats = ['id3v2.3'];
            $writer->tag_encoding = 'UTF-8';
            $writer->tag_data = [
                'title' => ['Cover Test'],
                'attached_picture' => [[
                    'data' => $picture,
                    'picturetypeid' => 3, // front cover
                    'description' => 'cover',
                    'mime' => 'image/jpeg',
                ]],
            ];

            $this->assertTrue($writer->WriteTags(), 'Could not write the test APIC frame: '.implode(', ', $writer->errors));
        }

        return $relativePath;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $song = Track::factory()->create();

        $this->get("/music/songs/{$song->id}/cover")->assertRedirect('/login');
    }

    public function test_an_embedded_picture_is_extracted_scaled_and_cached(): void
    {
        $path = $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3', $this->jpeg(900));
        $song = Track::factory()->create(['path' => $path, 'cover' => true]);

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/cover")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        // Scaled down to the configured long edge, not served at its original size.
        $dimensions = getimagesizefromstring($response->streamedContent());
        $this->assertSame(config('mixtape.covers.width'), $dimensions[0]);
        $this->assertSame(config('mixtape.covers.width'), $dimensions[1]);

        // Cached under the track id, so the next request never re-reads the mp3.
        $cached = storage_path('app/private/covers/'.$song->id.'.jpg');
        $this->assertFileExists($cached);

        // Proof the cache is what answers a second request: delete the source file
        // and ask again. A miss would 404 here.
        File::delete($this->mediaRoot.'/'.$path);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/cover")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_a_folder_image_is_used_when_the_file_has_no_embedded_picture(): void
    {
        // The other half of the fallback: no APIC, but the album directory carries an
        // image. Written under the FIRST configured candidate name, since that list —
        // not a single spelling — is what CoverService looks for.
        $path = $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3');
        File::put(
            $this->mediaRoot.'/The Storm/Thunder Road/'.config('mixtape.covers.folder_images')[0],
            $this->jpeg(300)
        );

        $song = Track::factory()->create(['path' => $path, 'cover' => false]);

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/cover")
            ->assertOk();

        // 300px is under the 450px cap, so it is cached as-is rather than upscaled.
        $this->assertSame(300, getimagesizefromstring($response->streamedContent())[0]);
    }

    public function test_a_song_with_no_cover_anywhere_is_a_404(): void
    {
        $song = Track::factory()->create([
            'path' => $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3'),
            'cover' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/cover")
            ->assertNotFound();
    }

    public function test_a_missing_file_is_a_404_rather_than_an_error(): void
    {
        // The row says it has a cover but the file is gone (deleted between scans):
        // a 404 and a placeholder on the page, never a 500.
        $song = Track::factory()->create(['path' => 'Gone/Missing.mp3', 'cover' => true]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$song->id}/cover")
            ->assertNotFound();
    }

    public function test_an_audiobook_chapters_cover_is_not_reachable_under_music(): void
    {
        $chapter = Track::factory()->audiobook()->create(['cover' => true]);

        $this->actingAs(User::factory()->create())
            ->get("/music/songs/{$chapter->id}/cover")
            ->assertNotFound();
    }

    public function test_the_page_sends_a_cover_url_only_when_there_is_a_cover(): void
    {
        $withCover = Track::factory()->create([
            'path' => $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3', $this->jpeg(600)),
            'cover' => true,
        ]);
        $withoutCover = Track::factory()->create([
            'path' => $this->mediaFile('The Storm/Quiet Road/01 - Silence.mp3'),
            'cover' => false,
        ]);

        $user = User::factory()->create();

        // `coverUrl` is decided WITHOUT extracting anything — the page must not pay
        // for a cover it only links to.
        $this->actingAs($user)
            ->get("/music/songs/{$withCover->id}")
            ->assertInertia(fn ($page) => $page->where('song.coverUrl', route('music.songs.cover', $withCover)));
        $this->assertFileDoesNotExist(storage_path('app/private/covers/'.$withCover->id.'.jpg'));

        $this->actingAs($user)
            ->get("/music/songs/{$withoutCover->id}")
            ->assertInertia(fn ($page) => $page->where('song.coverUrl', null));
    }
}
