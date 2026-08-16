<?php

namespace Tests\Feature\Shares;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Share;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The bytes a share link serves: `/s/{share}/tracks/{track}/stream`, the same track's cover,
 * and the subject's own cover.
 *
 * THE MOST EXPOSED CODE IN THIS APP. These are the only routes here that read the disk with
 * no session behind them, on a box that is deliberately reachable from the internet, so what
 * is pinned is mostly the ways in which they must REFUSE:
 *
 *   - A track outside the grant, even when the share itself is live and the track really
 *     exists. The URL can be typed as easily as followed, and swapping the second UUID must
 *     not walk out of the album you were sent.
 *   - A share whose week is up. Expiry is content on the PAGE (it says so kindly) and a
 *     permission here, which is the split ShareStreamRequest documents.
 *   - A revoked one, which is gone and therefore indistinguishable from a typo.
 *   - Every refusal as a 404, never a 403: a 403 would confirm that the row behind a guessed
 *     URL exists.
 *
 * …and two ways in which they must NOT refuse, both of which are easy to get wrong by copying
 * the authenticated routes: there is no music-only type guard here (an audiobook share
 * streams chapters), and Range must work, or a guest cannot drag the timeline at all.
 *
 * Each test writes a real file into a throwaway media area and points
 * `mixtape.library.paths.music` at it, the arrangement SongStreamTest and AlbumCoverTest use:
 * the whole job of these controllers is handing over bytes from disk.
 */
class ShareMediaTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaRoot;

    /** Point the music area at an empty temp dir, and stream through PHP unless a test says otherwise. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaRoot = sys_get_temp_dir().'/mixtape-share-media-test-'.uniqid();
        File::ensureDirectoryExists($this->mediaRoot);

        config([
            'mixtape.library.paths.music' => $this->mediaRoot,
            'mixtape.library.paths.audiobooks' => $this->mediaRoot,
            // The direct path is the default everywhere but the live box; the hand-off test
            // opts in by setting this itself.
            'mixtape.stream.internal_prefix' => null,
        ]);

        File::deleteDirectory(storage_path('app/private/covers'));
    }

    /** Remove the temp media area and any cover a test cached. */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaRoot);
        File::deleteDirectory(storage_path('app/private/covers'));

        parent::tearDown();
    }

    /** Write $bytes into the temp media area and return the area-relative path `tracks.path` stores. */
    private function mediaFile(string $relativePath, string $bytes): string
    {
        $absolute = $this->mediaRoot.'/'.$relativePath;
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $bytes);

        return $relativePath;
    }

    /** A square JPEG, whose SIZE is the test's fingerprint for which source answered. */
    private function jpeg(int $size): string
    {
        $image = imagecreatetruecolor($size, $size);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 16, 134));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }

    /**
     * Pre-seed a track's cover cache — how "this file has embedded art" is simulated:
     * CoverService::path() answers from the cache without going near the mp3.
     */
    private function cachedTrackCover(Track $track, int $size): void
    {
        File::ensureDirectoryExists(storage_path('app/private/covers'));
        File::put(storage_path('app/private/covers/'.$track->id.'.jpg'), $this->jpeg($size));

        $track->update(['cover' => true]);
    }

    /**
     * An album of one track with real bytes on disk, and a live share of it.
     *
     * @return array{0: Share, 1: Track, 2: Collection}
     */
    private function sharedAlbum(string $bytes = 'abcdefghij'): array
    {
        $album = Collection::factory()->create();
        $track = Track::factory()->create([
            'collection_id' => $album->id,
            'path' => $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3', $bytes),
        ]);

        return [Share::factory()->ofAlbum($album)->create(), $track, $album];
    }

    public function test_a_guest_streams_a_granted_track(): void
    {
        [$share, $track] = $this->sharedAlbum();

        $response = $this->get("/s/{$share->id}/tracks/{$track->id}/stream")
            ->assertOk()
            ->assertHeader('content-type', 'audio/mpeg')
            ->assertHeader('content-length', '10')
            // Announcing this is what makes a browser attempt to seek at all.
            ->assertHeader('accept-ranges', 'bytes');

        $this->assertSame('abcdefghij', $response->streamedContent());

        // `private` matters more here than anywhere: this is the app's only unauthenticated
        // media route, so a shared proxy holding one response would serve it to people who
        // never had the link — and would go on doing so after the share expired.
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));
    }

    public function test_a_range_request_gets_exactly_the_bytes_it_asked_for(): void
    {
        [$share, $track] = $this->sharedAlbum();

        $response = $this->get("/s/{$share->id}/tracks/{$track->id}/stream", ['Range' => 'bytes=3-6'])
            ->assertStatus(206)
            ->assertHeader('content-range', 'bytes 3-6/10');

        // The bytes, not just the status: an off-by-one on the slice is exactly what a
        // status-only assertion sails past, and what makes seeking play the wrong thing.
        $this->assertSame('defg', $response->streamedContent());
    }

    public function test_it_hands_off_to_nginx_when_a_prefix_is_configured(): void
    {
        config(['mixtape.stream.internal_prefix' => '/internal-media']);

        [$share, $track] = $this->sharedAlbum();

        $this->get("/s/{$share->id}/tracks/{$track->id}/stream")
            ->assertOk()
            ->assertHeader('content-type', 'audio/mpeg')
            // Every segment URL-encoded, because nginx DECODES the target — a raw space or
            // `#` truncates the path and 404s a track that plays fine over the direct route.
            ->assertHeader('x-accel-redirect', '/internal-media/music/The%20Storm/Thunder%20Road/02%20-%20Lightning%20Strikes.mp3')
            ->assertSee('', false);
    }

    public function test_a_track_outside_the_grant_is_a_404(): void
    {
        [$share] = $this->sharedAlbum();

        // A real track, a live share, and no relationship between them — which is the whole
        // attack: the URL is guessable in its second half once you hold one link.
        $stranger = Track::factory()->create([
            'path' => $this->mediaFile('Someone Else/Their Album/01 - Not Yours.mp3', 'zzzzzzzzzz'),
        ]);

        $this->get("/s/{$share->id}/tracks/{$stranger->id}/stream")->assertNotFound();
        $this->get("/s/{$share->id}/tracks/{$stranger->id}/cover")->assertNotFound();
    }

    public function test_an_expired_link_serves_nothing(): void
    {
        $album = Collection::factory()->create();
        $track = Track::factory()->create([
            'collection_id' => $album->id,
            'path' => $this->mediaFile('The Storm/Thunder Road/02 - Lightning Strikes.mp3', 'abcdefghij'),
        ]);
        $this->cachedTrackCover($track, 200);

        $share = Share::factory()->ofAlbum($album)->expired()->create();

        // The page still renders (ShowShareTest) — these do not. That split is deliberate:
        // an explanation is worth giving, bytes are not.
        $this->get("/s/{$share->id}/tracks/{$track->id}/stream")->assertNotFound();
        $this->get("/s/{$share->id}/tracks/{$track->id}/cover")->assertNotFound();
        $this->get("/s/{$share->id}/cover")->assertNotFound();
    }

    public function test_a_revoked_link_serves_nothing(): void
    {
        [$share, $track] = $this->sharedAlbum();
        $id = $share->id;

        $share->delete();

        $this->get("/s/{$id}/tracks/{$track->id}/stream")->assertNotFound();
        $this->get("/s/{$id}/tracks/{$track->id}/cover")->assertNotFound();
    }

    public function test_a_missing_file_is_a_404_rather_than_a_500(): void
    {
        $album = Collection::factory()->create();
        // A row whose file went away between library scans — the state the collection is
        // routinely in for a few minutes.
        $track = Track::factory()->create(['collection_id' => $album->id, 'path' => '/music/gone.mp3']);
        $share = Share::factory()->ofAlbum($album)->create();

        $this->get("/s/{$share->id}/tracks/{$track->id}/stream")->assertNotFound();
    }

    /**
     * THE ONE GUARD THIS SPACE DELIBERATELY DOES NOT CARRY. `/music/songs/{song}/stream`
     * refuses an audiobook chapter, because that route is about music; this one is about
     * whatever the link grants, and an audiobook share streams chapters. Copying the
     * authenticated controller wholesale is how that would be got wrong, which is why the two
     * are separate files and why this is asserted rather than assumed.
     */
    public function test_an_audiobook_share_streams_its_chapters(): void
    {
        $audiobook = Collection::factory()->audiobook()->create();
        $chapter = Track::factory()->audiobook()->create([
            'collection_id' => $audiobook->id,
            'path' => $this->mediaFile('Le Guin/The Dispossessed/01.mp3', 'chapterone'),
        ]);

        $share = Share::factory()->ofAlbum($audiobook)->create();

        $this->get("/s/{$share->id}/tracks/{$chapter->id}/stream")
            ->assertOk()
            ->assertHeader('content-type', 'audio/mpeg');
    }

    public function test_it_serves_a_granted_tracks_cover(): void
    {
        [$share, $track] = $this->sharedAlbum();
        $this->cachedTrackCover($track, 200);

        $response = $this->get("/s/{$share->id}/tracks/{$track->id}/cover")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $this->assertSame(200, getimagesizefromstring($response->streamedContent())[0]);
    }

    /**
     * The subject cover exists precisely so an ALBUM's hero is not the first track's picture:
     * CoverService prefers the directory's folder image at album grain and the embedded one at
     * track grain, and drawing the hero through the per-track route would have made a
     * compilation's artwork depend on which track sorts first.
     */
    public function test_the_subject_cover_prefers_the_albums_own_artwork(): void
    {
        [$share, $track] = $this->sharedAlbum();
        File::put($this->mediaRoot.'/The Storm/Thunder Road/folder.jpg', $this->jpeg(300));
        $this->cachedTrackCover($track, 200);

        $response = $this->get("/s/{$share->id}/cover")->assertOk();

        // 300 = the folder image. 200 would mean the hero had been drawn from a track.
        $this->assertSame(300, getimagesizefromstring($response->streamedContent())[0]);
    }

    /**
     * A BOOK'S HERO IS SERVED BY THE SAME ROUTE AS A RECORD'S, and it takes an explicit arm to
     * say so. Let `ShareCoverController` match Song and Album and drop an audiobook to
     * `default => null` and this 404s — invisibly, because `ShareArtwork` has the identical hole
     * and so never points an `<img>` at it; the page just draws its placeholder glyph. Both arms
     * are the album's, which is right for the same reason `AudiobookCoverController` calls
     * `albumPath()`: a book is a `collections` row whose Folder.jpg the scanner records exactly
     * as it does a record's.
     */
    public function test_a_shared_book_serves_its_own_cover(): void
    {
        $book = Collection::factory()->audiobook()->create();
        Track::factory()->audiobook()->create([
            'collection_id' => $book->id,
            'path' => $this->mediaFile('Le Guin/The Dispossessed/01.mp3', 'chapterone'),
        ]);
        File::put($this->mediaRoot.'/Le Guin/The Dispossessed/folder.jpg', $this->jpeg(300));

        $share = Share::factory()->ofAlbum($book)->create();

        $response = $this->get("/s/{$share->id}/cover")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $this->assertSame(300, getimagesizefromstring($response->streamedContent())[0]);
    }

    public function test_an_artist_share_has_no_subject_cover(): void
    {
        $artist = Artist::factory()->create();
        $share = Share::factory()->ofArtist($artist)->create();

        // MixTape stores no artist images, so this 404s — and nothing points an <img> at it:
        // the page sends `coverUrl: null` and fans a few of the artist's own sleeves instead.
        $this->get("/s/{$share->id}/cover")->assertNotFound();
    }

    /**
     * NEITHER DOWNLOAD ROUTE HAS A COUNTERPART UNDER `/s/`, and that is a decision rather
     * than an oversight: a share is permission to LISTEN. "Listen to this" and "here is the
     * file" are different acts, and only the first was asked for.
     *
     * Written as a walk over the route table rather than as requests that 404 — every unknown
     * path under `/s/` 404s anyway, so a request-based test would pass just as happily if
     * somebody added the route tomorrow with a typo in it.
     */
    public function test_the_share_space_offers_no_download_at_all(): void
    {
        $downloads = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 's/'))
            ->filter(fn (RoutingRoute $route): bool => str_contains($route->uri(), 'download'))
            ->map(fn (RoutingRoute $route): string => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $downloads, 'A share grants listening, not files.');
    }
}
