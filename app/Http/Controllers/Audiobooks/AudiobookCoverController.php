<?php

namespace App\Http\Controllers\Audiobooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audiobooks\AudiobookCoverRequest;
use App\Models\Collection;
use App\Services\Media\CoverService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * One book's cover art (`GET /audiobooks/{audiobook}/cover`, route `audiobooks.cover`,
 * behind auth) — the tile in the books listing and the hero image on the book's page.
 *
 * A sibling of AlbumCoverController, calling the same `CoverService::albumPath()`: the
 * service is named for albums but keyed on the collection, and it already resolves an
 * audiobook's area root from `$collection->type` (`Track::absolutePathFor`). Books on this
 * share carry `Folder.jpg` beside the chapters, which the scanner records into
 * `collections.cover_path`, so nothing here touches the filesystem to find it.
 *
 * A book with no art 404s, which the listing never triggers: the controller sends
 * `coverUrl: null` for those rows and the tile draws its placeholder.
 */
class AudiobookCoverController extends Controller
{
    /** Injected so the resolution/caching policy stays in one testable place. */
    public function __construct(private readonly CoverService $covers) {}

    /**
     * Send the cached cover as a private, revalidatable image.
     *
     * A DAY rather than a month, the same call the album route makes and for the same
     * reason: this URL carries a plain collection UUID, which survives someone dropping a new
     * Folder.jpg into the directory. The cache key behind it notices (it includes the image's
     * mtime); a browser's copy would not, so the TTL stays short enough that a replaced cover
     * appears the next day at the latest, with the ETag making the revalidation a 304.
     */
    public function __invoke(AudiobookCoverRequest $request, Collection $audiobook): BinaryFileResponse
    {
        $path = $this->covers->albumPath($audiobook);

        abort_if($path === null, Response::HTTP_NOT_FOUND);

        return response()
            ->file($path, ['Content-Type' => 'image/jpeg'])
            ->setPrivate()
            ->setMaxAge(60 * 60 * 24)
            ->setAutoEtag();
    }
}
