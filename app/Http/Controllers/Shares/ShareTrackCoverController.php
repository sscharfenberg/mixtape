<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shares\ShareTrackCoverRequest;
use App\Models\Share;
use App\Models\Track;
use App\Services\Media\CoverService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * One shared track's cover art (`GET /s/{share}/tracks/{track}/cover`, route
 * `shares.tracks.cover`, NO auth) — the thumbnail beside a row of the guest page's list, in
 * the play queue panel, and on the player bar.
 *
 * The same shape as SongCoverController and the same CoverService call (a track prefers its
 * own embedded picture, falling back to the album directory's image). What differs is the
 * gate: whoever holds the link, for as long as it lives, and only for tracks the link
 * grants — ShareTrackCoverRequest, over the grant the page itself was drawn from.
 *
 * A track with no cover from either source 404s, which the page never triggers: the queue
 * entries carry `coverUrl: null` for those and the panel draws its placeholder instead.
 */
class ShareTrackCoverController extends Controller
{
    /** Injected so the extraction/caching policy stays the one testable place it is everywhere else. */
    public function __construct(private readonly CoverService $covers) {}

    /**
     * Send the cached cover as a private, revalidatable image.
     *
     * A DAY rather than the authenticated route's month, the same trade the shared stream
     * makes: this URL dies when the link does, and a browser holding the image for thirty
     * days would outlive it considerably. `private` matters more here than anywhere else in
     * the app — this is its only unauthenticated media route, so a shared proxy caching one
     * response would hand the artwork to people who never had the link.
     */
    public function __invoke(ShareTrackCoverRequest $request, Share $share, Track $track): BinaryFileResponse
    {
        $path = $this->covers->path($track);

        abort_if($path === null, Response::HTTP_NOT_FOUND);

        return response()
            ->file($path, ['Content-Type' => 'image/jpeg'])
            ->setPrivate()
            ->setMaxAge(60 * 60 * 24)
            ->setAutoEtag();
    }
}
