<?php

namespace Tests\Feature\Music;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * The album download (`/music/albums/{album}/download`, behind auth) — the record as a
 * .zip.
 *
 * The archive is written by hand (App\Services\Media\ZipStream — PHP's own zip extension
 * cannot stream), so these tests do not assert on bytes or headers: every one of them
 * puts the response through **`ZipArchive`**, which validates the central directory, the
 * offsets and every CRC. If the writer is wrong, opening fails or a file comes out
 * corrupt, and either way that is what shows up here rather than a diff of pack() output.
 *
 * What is worth a test:
 *
 * - **The archive opens, and its contents are byte-exact.** The whole feature.
 * - **What goes in.** The tracks, plus the non-audio files beside them — the cover and
 *   the booklet a listener downloading a record expects — and specifically NOT another
 *   album's mp3 sharing a directory, which is the one way this could hand over the wrong
 *   music.
 * - **Multi-disc structure.** This collection spells discs as subdirectories with the
 *   booklet one level up; flattening that collapses two "01 - …" tracks into one name.
 * - **`Content-Length`.** Promised before a byte is written, so an error in the size
 *   arithmetic is a download the browser reports as failed. Asserted against the body
 *   that actually arrives.
 * - **The gate**, and an album whose files have gone.
 */
class AlbumDownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaRoot;

    /** Point the music area at an empty temp dir — these tests need real files on disk. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaRoot = sys_get_temp_dir().'/mixtape-album-download-test-'.uniqid();
        File::ensureDirectoryExists($this->mediaRoot);

        config(['mixtape.library.paths.music' => $this->mediaRoot]);
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

    /**
     * Download the album and hand the archive back as an open ZipArchive.
     *
     * The body goes to a temp file because that is the only thing ZipArchive can open —
     * which makes this the most honest possible check of a streaming writer: the archive
     * is read by an implementation that had no part in producing it.
     */
    private function openDownload(Collection $album): ZipArchive
    {
        $response = $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/zip');

        $body = $response->streamedContent();

        // The length is promised in a header before anything is written; if the
        // arithmetic and the writer disagree, the browser sees a truncated download.
        $this->assertSame((string) strlen($body), $response->headers->get('content-length'));

        $file = tempnam(sys_get_temp_dir(), 'mixtape-zip-');
        File::put($file, $body);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file, ZipArchive::CHECKCONS) === true, 'The archive did not open.');

        return $zip;
    }

    /** The entry names in the archive, sorted so an assertion does not depend on write order. */
    private function names(ZipArchive $zip): array
    {
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        sort($names);

        return $names;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $album = Collection::factory()->create();
        Track::factory()->for($album, 'collection')->create();

        $this->get("/music/albums/{$album->id}/download")->assertRedirect('/login');
    }

    public function test_it_sends_the_tracks_and_the_files_beside_them(): void
    {
        $album = Collection::factory()->create(['name' => 'Thunder Road']);

        Track::factory()->for($album, 'collection')->create([
            'track' => 1,
            'path' => $this->mediaFile('The Storm/Thunder Road/01 - Lightning.mp3', 'first'),
        ]);
        Track::factory()->for($album, 'collection')->create([
            'track' => 2,
            'path' => $this->mediaFile('The Storm/Thunder Road/02 - Thunder.mp3', 'second'),
        ]);

        // Everything a listener expects to find on the shelf beside the songs. The `.m3u8`
        // is not invented: this collection has one sitting in an album directory.
        $this->mediaFile('The Storm/Thunder Road/folder.jpg', 'JPEG');
        $this->mediaFile('The Storm/Thunder Road/booklet.pdf', 'PDF');
        $this->mediaFile('The Storm/Thunder Road/Thunder Road.m3u8', 'M3U');
        // Junk the cleanup step would delete anyway — never worth shipping to a reader.
        $this->mediaFile('The Storm/Thunder Road/.DS_Store', 'junk');

        $zip = $this->openDownload($album);

        $this->assertSame(
            ['01 - Lightning.mp3', '02 - Thunder.mp3', 'Thunder Road.m3u8', 'booklet.pdf', 'folder.jpg'],
            $this->names($zip)
        );

        // Byte-exact, and read back through an implementation that did not write them.
        $this->assertSame('first', $zip->getFromName('01 - Lightning.mp3'));
        $this->assertSame('JPEG', $zip->getFromName('folder.jpg'));
    }

    public function test_it_never_picks_up_a_neighbouring_albums_audio(): void
    {
        // The reason tracks come from the DATABASE and only the extras from the
        // directory: a folder can hold audio that belongs to something else — a single, a
        // rip filed under the wrong artist — and taking every mp3 in it would put another
        // album's music in this download.
        $album = Collection::factory()->create();

        Track::factory()->for($album, 'collection')->create([
            'path' => $this->mediaFile('Shared/Folder/01 - Ours.mp3', 'ours'),
        ]);

        $other = Collection::factory()->create();
        Track::factory()->for($other, 'collection')->create([
            'path' => $this->mediaFile('Shared/Folder/02 - Theirs.mp3', 'theirs'),
        ]);

        $this->assertSame(['01 - Ours.mp3'], $this->names($this->openDownload($album)));
    }

    public function test_a_multi_disc_album_keeps_its_directories(): void
    {
        // Spelled the way this collection spells it: a disc per subdirectory, the booklet
        // at the album level, and a second booklet inside one of the discs. Flattened,
        // the two "01 - …" tracks would collide and one of them would be lost.
        $album = Collection::factory()->create();

        Track::factory()->for($album, 'collection')->create([
            'disc' => 1,
            'track' => 1,
            'path' => $this->mediaFile('Ayreon/[2008] 01011001/[Disc 1]/01 - Age Of Shadows.mp3', 'one'),
        ]);
        Track::factory()->for($album, 'collection')->create([
            'disc' => 2,
            'track' => 1,
            'path' => $this->mediaFile('Ayreon/[2008] 01011001/[Disc 2]/01 - The Fifth Extinction.mp3', 'two'),
        ]);

        $this->mediaFile('Ayreon/[2008] 01011001/01011001.m3u8', 'M3U');
        $this->mediaFile('Ayreon/[2008] 01011001/[Disc 2]/booklet.pdf', 'PDF');

        $zip = $this->openDownload($album);

        $this->assertSame([
            '01011001.m3u8',
            '[Disc 1]/01 - Age Of Shadows.mp3',
            '[Disc 2]/01 - The Fifth Extinction.mp3',
            '[Disc 2]/booklet.pdf',
        ], $this->names($zip));

        $this->assertSame('two', $zip->getFromName('[Disc 2]/01 - The Fifth Extinction.mp3'));
    }

    public function test_the_archive_is_named_after_the_artist_and_the_album(): void
    {
        $album = Collection::factory()->create([
            'name' => 'Kind of Blue',
            'album_artist_id' => Artist::factory()->create(['name' => 'Miles Davis']),
        ]);

        Track::factory()->for($album, 'collection')->create([
            'path' => $this->mediaFile('Miles Davis/Kind of Blue/01 - So What.mp3', 'x'),
        ]);

        // One parameter only, because an all-ASCII name needs no second spelling — the
        // header builder adds `filename*` when the two would differ, which the umlaut
        // case in SongDownloadTest covers.
        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}/download")
            ->assertHeader('content-disposition', 'attachment; filename="Miles Davis - Kind of Blue.zip"');
    }

    public function test_a_compilation_with_no_album_artist_is_named_after_itself(): void
    {
        // And a slash in the title must not turn the filename into a path.
        $album = Collection::factory()->create([
            'name' => 'Now That\'s What I Call Music 12/13',
            'album_artist_id' => null,
        ]);

        Track::factory()->for($album, 'collection')->create([
            'path' => $this->mediaFile('Various/Now 12/01 - A Song.mp3', 'x'),
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}/download")
            ->assertHeader(
                'content-disposition',
                'attachment; filename="Now That_s What I Call Music 12_13.zip"'
            );
    }

    public function test_an_album_whose_files_have_gone_is_a_404(): void
    {
        // The rows and the files go out of step whenever something is deleted between
        // scans. An empty zip would be a worse answer than saying there is nothing here.
        $album = Collection::factory()->create();
        Track::factory()->for($album, 'collection')->create(['path' => 'Gone/Missing/01.mp3']);

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}/download")
            ->assertNotFound();
    }

    public function test_an_audiobook_is_not_downloadable_under_music(): void
    {
        $audiobook = Collection::factory()->audiobook()->create();

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$audiobook->id}/download")
            ->assertNotFound();
    }

    public function test_its_rate_limit_is_its_own_and_not_the_rest_of_the_apps(): void
    {
        /*
         * `throttle:max,1` keys its bucket by the USER ALONE
         * (ThrottleRequests::resolveRequestSignature) — the route plays no part — so
         * without the prefix in the route definition every throttled route in this app
         * shares one counter per reader and only the ceiling differs. This album route
         * has the lowest ceiling of any of them (10, because one press can cost a
         * gigabyte), so it is the one that would break first: a listener whose player had
         * just written a handful of queue states would be refused a download for reasons
         * that have nothing to do with downloading.
         *
         * Eleven song downloads stand in for that traffic — one past this route's own
         * ceiling, so a shared counter would have to answer 429.
         *
         * ASSERTED ON THE RATE-LIMIT HEADERS, not merely on a 200, because they distinguish more
         * states: a shared bucket that eleven requests had not yet exhausted would still answer
         * `assertOk()` while reading a `Remaining` far below the album route's own. The pair is
         * the same signal PrecognitionThrottleTest relies on for the same kind of claim.
         */
        $user = User::factory()->create();

        $song = Track::factory()->create([
            'path' => $this->mediaFile('Someone/Else/01 - Track.mp3', 'x'),
        ]);

        for ($i = 0; $i < 11; $i++) {
            $this->actingAs($user)->get("/music/songs/{$song->id}/download")->assertOk();
        }

        $album = Collection::factory()->create();
        Track::factory()->for($album, 'collection')->create([
            'path' => $this->mediaFile('Someone/Else/02 - Another.mp3', 'y'),
        ]);

        $this->actingAs($user)
            ->get("/music/albums/{$album->id}/download")
            ->assertOk()
            // This route's own ceiling, untouched by the eleven requests above it.
            ->assertHeader('X-RateLimit-Limit', '10')
            ->assertHeader('X-RateLimit-Remaining', '9');
    }

    public function test_the_page_hands_the_download_url_to_the_hero(): void
    {
        $album = Collection::factory()->create();
        Track::factory()->for($album, 'collection')->create();

        $this->actingAs(User::factory()->create())
            ->get("/music/albums/{$album->id}")
            ->assertInertia(fn ($page) => $page->where('album.downloadUrl', "/music/albums/{$album->id}/download"));
    }
}
