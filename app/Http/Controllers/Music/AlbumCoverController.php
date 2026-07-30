<?php

namespace App\Http\Controllers\Music;

use App\Enums\CollectionType;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Services\Media\CoverService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * One album's cover art (`GET /music/albums/{album}/cover`, route
 * `music.albums.cover`, behind auth) — the thumbnail in the Albums listing, and the
 * album page's hero image once that page is real.
 *
 * A sibling of SongCoverController rather than a branch of it, because the two
 * answer with a different image for the same directory: this one prefers the
 * album's folder image over any embedded picture (CoverService::albumPath explains
 * why), while a song prefers its own. Same shape otherwise — an image response,
 * guarded by the route's auth, served from a cached scaled JPEG.
 *
 * An album with art from neither source 404s, which the listing never triggers:
 * AlbumsController sends `coverUrl: null` for those rows and the table renders its
 * placeholder instead of pointing an <img> at a 404.
 */
class AlbumCoverController extends Controller
{
    /** Injected so the resolution/caching policy stays in one testable place. */
    public function __construct(private readonly CoverService $covers) {}

    /**
     * Send the cached cover as a private, revalidatable image.
     *
     * `private` for the same reason as a song's: this app is internet-facing behind
     * auth, so a cover must never sit in a shared proxy where an unauthenticated
     * visitor could pick it up.
     *
     * The max-age is a DAY where a song's is a month, and the difference is not
     * caution — it is that this URL is not content-addressed. A song's cover URL
     * carries the track id, which is a hash of the audio, so new bytes are a new URL;
     * an album id is a plain UUID that survives someone dropping a new Folder.jpg in
     * the directory. The cache key behind it does notice (it includes the image's
     * mtime), but the browser's copy would not, so the TTL stays short enough that a
     * replaced cover appears the next day at the latest, and the ETag makes the
     * revalidation in between a 304.
     *
     * The album type check mirrors the listing's: `collections` also holds audiobooks
     * and podcast shows, and this route is about music.
     */
    public function __invoke(Request $request, Collection $album): BinaryFileResponse
    {
        abort_unless($album->type === CollectionType::Album, Response::HTTP_NOT_FOUND);

        $path = $this->covers->albumPath($album);

        abort_if($path === null, Response::HTTP_NOT_FOUND);

        return response()
            ->file($path, ['Content-Type' => 'image/jpeg'])
            ->setPrivate()
            ->setMaxAge(60 * 60 * 24)
            ->setAutoEtag();
    }
}
