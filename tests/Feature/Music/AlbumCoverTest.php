<?php

namespace Tests\Feature\Music;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The album cover endpoint (`/music/albums/{album}/cover`, behind auth) and the
 * album-grain half of CoverService.
 *
 * Two behaviours are worth the disk these tests touch, and both were found in the
 * real collection rather than reasoned about:
 *
 *   1. An album prefers its DIRECTORY image over any embedded picture — the inverse
 *      of a song. Rips exist where every file carries its own inline art, and there
 *      "the embedded cover" makes an album's thumbnail depend on sort order.
 *   2. Candidate names are matched case-insensitively, and an unrecognised name is
 *      only used when it is the directory's only image. Of 951 album directories in
 *      the owner's collection, 923 spell it `folder.jpg` and one spells it
 *      `Folder.jpg`; several also hold `back.jpg` / `cd.jpg` / `inlay.jpg`, every one
 *      of which sorts before the front cover.
 *
 * Like SongCoverTest, each test builds a throwaway media area on disk and points
 * `mixtape.library.paths.music` at it — the service's whole job is reading files.
 */
class AlbumCoverTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaRoot;

    /** Point the music area at an empty temp dir, and keep the cover cache out of the real storage dir. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaRoot = sys_get_temp_dir().'/mixtape-album-cover-test-'.uniqid();
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
     * A square JPEG of the given size as raw bytes. The size doubles as the test's
     * fingerprint: asserting on the width of what comes back is how these tests tell
     * WHICH source answered, without comparing pixels.
     */
    private function jpeg(int $size): string
    {
        $image = imagecreatetruecolor($size, $size);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 16, 134));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }

    /**
     * An album with one track on disk, in its own directory.
     *
     * The mp3 is the committed synthetic fixture, copied rather than tagged: every
     * test here that needs embedded art fakes it through the TRACK cache instead of
     * writing an APIC frame (see cachedTrackCover) — SongCoverTest already covers the
     * real getID3 extraction, and repeating it would only make these tests slower.
     *
     * @return array{0: Collection, 1: Track, 2: string} the album, its track, and the album directory
     */
    private function album(string $directory = 'Godspeed You! Black Emperor/Luciferian Towers'): array
    {
        $relative = $directory.'/01 - Undoing a Luciferian Towers.mp3';
        $absolute = $this->mediaRoot.'/'.$relative;

        File::ensureDirectoryExists(dirname($absolute));
        File::copy(base_path('tests/Fixtures/audio/tagged.mp3'), $absolute);

        $album = Collection::factory()->create([
            'name' => 'Luciferian Towers',
            'album_artist_id' => Artist::factory()->create(['name' => 'Godspeed You! Black Emperor'])->id,
        ]);

        $track = Track::factory()->create([
            'collection_id' => $album->id,
            'path' => $relative,
            'cover' => false,
            'disc' => 1,
            'track' => 1,
        ]);

        return [$album, $track, $this->mediaRoot.'/'.$directory];
    }

    /**
     * Pre-seed a track's cover cache, which is how "this file has embedded art"
     * is simulated: CoverService::path() returns a cached file without going near
     * the mp3, so the fallback path can be exercised at a known image size.
     */
    private function cachedTrackCover(Track $track, int $size): void
    {
        File::ensureDirectoryExists(storage_path('app/private/covers'));
        File::put(storage_path('app/private/covers/'.$track->id.'.jpg'), $this->jpeg($size));

        $track->update(['cover' => true]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        [$album] = $this->album();

        $this->get("/music/albums/{$album->id}/cover")->assertRedirect('/login');
    }

    public function test_the_directory_image_wins_over_an_embedded_picture(): void
    {
        // The case that motivated albumPath(): the album has BOTH, and the folder
        // image — the one picture chosen for the album as a whole — must answer.
        [$album, $track, $directory] = $this->album();
        File::put($directory.'/folder.jpg', $this->jpeg(300));
        $this->cachedTrackCover($track, 200);

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}/cover")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        // 300 = the folder image, 200 would have been the embedded one.
        $this->assertSame(300, getimagesizefromstring($response->streamedContent())[0]);
    }

    public function test_an_embedded_picture_answers_when_there_is_no_directory_image(): void
    {
        [$album, $track] = $this->album();
        $this->cachedTrackCover($track, 200);

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}/cover")
            ->assertOk();

        $this->assertSame(200, getimagesizefromstring($response->streamedContent())[0]);
    }

    public function test_a_candidate_name_is_matched_whatever_its_case(): void
    {
        // The bug this whole list exists for: the collection spells it `folder.jpg`,
        // the config used to say `Folder.jpg`, and on ext4 that matched 1 directory
        // in 951. Either spelling has to resolve.
        [$album, , $directory] = $this->album();
        File::put($directory.'/FOLDER.JPG', $this->jpeg(320));

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}/cover")
            ->assertOk();

        $this->assertSame(320, getimagesizefromstring($response->streamedContent())[0]);
    }

    public function test_the_configured_order_decides_between_several_known_names(): void
    {
        // 63 directories in the real collection hold `cover.jpg` beside `folder.jpg`.
        // The list's order picks, not the filesystem's.
        [$album, , $directory] = $this->album();
        File::put($directory.'/folder.jpg', $this->jpeg(300));
        File::put($directory.'/cover.jpg', $this->jpeg(360));

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}/cover")
            ->assertOk();

        $this->assertSame(300, getimagesizefromstring($response->streamedContent())[0]);
    }

    public function test_a_lone_unrecognised_image_is_used(): void
    {
        // Art named after the album — common, and safe to take precisely because it
        // is the directory's only image.
        [$album, , $directory] = $this->album();
        File::put($directory.'/Luciferian Towers.jpg', $this->jpeg(340));

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}/cover")
            ->assertOk();

        $this->assertSame(340, getimagesizefromstring($response->streamedContent())[0]);
    }

    public function test_several_unrecognised_images_are_never_guessed_between(): void
    {
        // `back.jpg` sorts first and is the one thing that must NOT become the
        // album's cover. With no recognised name and no single candidate, the
        // directory has no answer and the embedded picture takes over.
        [$album, $track, $directory] = $this->album();
        File::put($directory.'/back.jpg', $this->jpeg(300));
        File::put($directory.'/inlay.jpg', $this->jpeg(310));
        $this->cachedTrackCover($track, 200);

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}/cover")
            ->assertOk();

        $this->assertSame(200, getimagesizefromstring($response->streamedContent())[0]);
    }

    public function test_an_album_with_no_art_at_all_is_a_404(): void
    {
        [$album] = $this->album();

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}/cover")
            ->assertNotFound();
    }

    public function test_an_audiobooks_cover_is_not_reachable_under_music(): void
    {
        // `collections` holds audiobooks and podcast shows too; this route is music.
        $audiobook = Collection::factory()->audiobook()->create();

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$audiobook->id}/cover")
            ->assertNotFound();
    }

    public function test_the_recorded_path_answers_without_resolving_anything(): void
    {
        // The point of `collections.cover_path`: the scanner already decided, so the
        // request must not decide again. Proven by making the two answers DIFFER — the
        // directory holds a `folder.jpg` that live resolution would pick, while the
        // column names `chosen.jpg`, which no rule would ever choose on its own (it is
        // not a candidate name, and it is not the only image). Only a column read gets
        // 360 back.
        [$album, , $directory] = $this->album();
        File::put($directory.'/folder.jpg', $this->jpeg(300));
        File::put($directory.'/chosen.jpg', $this->jpeg(360));

        $album->update(['cover_path' => 'Godspeed You! Black Emperor/Luciferian Towers/chosen.jpg']);

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}/cover")
            ->assertOk();

        $this->assertSame(360, getimagesizefromstring($response->streamedContent())[0]);
    }

    public function test_a_recorded_path_that_has_gone_falls_back_to_a_live_resolve(): void
    {
        // Art renamed since the last `app:update`. The row still points at the old
        // name, and rather than 404 the route re-resolves the directory — which is why
        // the resolution rules stayed in CoverService instead of moving into the
        // scanner.
        [$album, , $directory] = $this->album();
        File::put($directory.'/folder.jpg', $this->jpeg(300));

        $album->update(['cover_path' => 'Godspeed You! Black Emperor/Luciferian Towers/gone.jpg']);

        $response = $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}/cover")
            ->assertOk();

        $this->assertSame(300, getimagesizefromstring($response->streamedContent())[0]);
    }

    public function test_a_replaced_directory_image_is_not_served_from_the_old_cache(): void
    {
        // An album id is a plain UUID, not a content hash, so the cache key carries
        // the source image's mtime — otherwise a new Folder.jpg would never be seen.
        [$album, , $directory] = $this->album();
        File::put($directory.'/folder.jpg', $this->jpeg(300));

        $user = User::factory()->create();
        $this->actingAs($user)->get("/music/albums/{$album->id}/cover")->assertOk();

        File::put($directory.'/folder.jpg', $this->jpeg(360));
        touch($directory.'/folder.jpg', time() + 10);

        $response = $this->actingAs($user)->get("/music/albums/{$album->id}/cover")->assertOk();

        $this->assertSame(360, getimagesizefromstring($response->streamedContent())[0]);
    }
}
