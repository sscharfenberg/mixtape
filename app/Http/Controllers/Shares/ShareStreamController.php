<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shares\ShareStreamRequest;
use App\Models\Share;
use App\Models\Track;
use App\Services\Media\InternalRedirect;
use Illuminate\Http\Response as LaravelResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A shared track's audio (`GET /s/{share}/tracks/{track}/stream`, route
 * `shares.tracks.stream`, NO auth) — the `src` the guest's player loads.
 *
 * ITS OWN CONTROLLER RATHER THAN A BRANCH OF SongStreamController, and the reason is one
 * line of that one: the music-only `TrackType` guard. `/music/songs/{song}/stream` is about
 * music and must refuse an audiobook chapter; this route is about whatever the link grants,
 * and an audiobook share streams audiobook tracks. A shared controller would have to take
 * that guard as a parameter, which is a flag deciding what a route means — easier to get
 * wrong once than to keep right forever, and the wrong way round here opens the library.
 *
 * WHAT IT MAY SERVE IS NOT DECIDED HERE. ShareStreamRequest asks ShareGrant, over the same
 * query the guest page was drawn from, so the page and this route cannot come to disagree
 * about which tracks belong to a link.
 *
 * EVERYTHING BELOW THE GUARD IS SongStreamController's, deliberately and to the byte: the
 * same `X-Accel-Redirect` hand-off to nginx when `mixtape.stream.internal_prefix` is set,
 * the same direct BinaryFileResponse when it is not, the same `Accept-Ranges` and `206`
 * behaviour that make dragging the timeline work at all (docs/player.md). A guest's
 * playback must not be a second implementation of playback.
 *
 * `setPrivate()` for the same reason as the authenticated route, and if anything a firmer
 * one: a shared proxy holding a track from this URL would be serving it to people who never
 * had the link, and would go on doing so after the share expired.
 */
class ShareStreamController extends Controller
{
    /**
     * Send the shared track's audio, by whichever route the environment allows.
     *
     * A missing file is a 404 rather than a 500 — the row and the file go out of step
     * whenever something is deleted between library scans, and a dead <audio> src is the
     * honest answer.
     */
    public function __invoke(ShareStreamRequest $request, Share $share, Track $track): BinaryFileResponse|LaravelResponse
    {
        $path = $track->absolutePath();

        abort_unless(is_file($path) && is_readable($path), Response::HTTP_NOT_FOUND);

        // Null when no prefix is configured — including a BLANK one, which is the trap that
        // once 500'd every stream on the dev site (InternalRedirect carries the reasoning).
        $uri = InternalRedirect::uriFor($track->path, $track->type);

        return $uri === null
            ? $this->sendDirectly($path)
            : $this->handOffToNginx($uri);
    }

    /**
     * Stream the file through PHP, letting Symfony answer Range requests.
     *
     * A SHORTER max-age than the authenticated route's month, and that is the one number
     * this controller changes: a browser told to keep the bytes for thirty days would go on
     * playing a share for weeks after it expired, from a URL the server has begun refusing.
     * A day keeps a reload cheap without outliving the link by much, and the ETag makes the
     * revalidation in between a 304.
     */
    private function sendDirectly(string $path): BinaryFileResponse
    {
        return response()
            ->file($path, ['Content-Type' => 'audio/mpeg'])
            ->setPrivate()
            ->setMaxAge(60 * 60 * 24)
            ->setAutoEtag();
    }

    /**
     * Answer with an empty body and let nginx serve the file from its `internal;` location.
     *
     * How the URI is built — off the area key, with every segment `rawurlencode`d because
     * nginx URL-DECODES the target — is InternalRedirect's, unchanged.
     */
    private function handOffToNginx(string $uri): LaravelResponse
    {
        return response('', Response::HTTP_OK, [
            'X-Accel-Redirect' => $uri,
            // nginx would guess a type from its own mime.types; naming it here keeps the two
            // paths identical from the browser's point of view.
            'Content-Type' => 'audio/mpeg',
        ])
            ->setPrivate()
            ->setMaxAge(60 * 60 * 24);
    }
}
