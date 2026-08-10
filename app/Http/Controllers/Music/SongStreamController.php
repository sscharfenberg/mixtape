<?php

namespace App\Http\Controllers\Music;

use App\Http\Controllers\Controller;
use App\Http\Requests\Music\SongStreamRequest;
use App\Models\Track;
use App\Services\Media\InternalRedirect;
use Illuminate\Http\Response as LaravelResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * One song's audio bytes (`GET /music/songs/{song}/stream`, route
 * `music.songs.stream`, behind auth) — the `src` of the player's <audio> element.
 *
 * Shaped after SongCoverController, and for the same reasons: its own controller
 * because it answers with a file rather than an Inertia page, the same music-only
 * `TrackType` guard (the `tracks` table also holds audiobook chapters and this
 * route is about music), and `->setPrivate()` caching because this instance is
 * internet-facing behind auth — a shared proxy must never end up holding a track.
 *
 * TWO WAYS OF SENDING THE SAME FILE, chosen by config:
 *
 * - **`X-Accel-Redirect`** when `mixtape.stream.internal_prefix` is a non-empty
 *   value (the live box). Streaming a 96 GB collection through php-fpm would tie up
 *   a worker for the whole length of every song, and there are only so many workers;
 *   nginx serves the bytes from an `internal;` location instead and PHP is free again
 *   the moment the header is written. See `docs/self-hosting/files/mixtape.prod.nginx.conf`.
 * - **The file itself** when that setting is empty or absent (a dev machine, the test
 *   suite, `php -S`), where there is no nginx to hand off to and one blocked worker
 *   costs nothing.
 *
 * HTTP RANGE comes free on the direct path — Symfony's BinaryFileResponse::prepare()
 * reads the `Range` header and answers `206` with a `Content-Range` (docs/player.md
 * assumed otherwise; it does not need writing by hand). nginx handles Range natively
 * on the accelerated path. Both matter for the same reason: without `206`, dragging
 * the timeline past what is buffered simply fails.
 */
class SongStreamController extends Controller
{
    /**
     * Send the song's audio, by whichever route the environment allows.
     *
     * A missing file is a 404 rather than a 500: the row and the file go out of
     * step whenever something is deleted between library scans, and a dead <audio>
     * src is the honest answer — the same call SongCoverController makes.
     */
    public function __invoke(SongStreamRequest $request, Track $song): BinaryFileResponse|LaravelResponse
    {
        $path = $song->absolutePath();

        abort_unless(is_file($path) && is_readable($path), Response::HTTP_NOT_FOUND);

        // Null when no prefix is configured — including a BLANK one, which is not a
        // nicety but the trap that once 500'd every stream on the dev site.
        // InternalRedirect carries that reasoning, and the encoding rules with it; the
        // download route beside this one asks the same question.
        $uri = InternalRedirect::uriFor($song->path, $song->type);

        return $uri === null
            ? $this->sendDirectly($path)
            : $this->handOffToNginx($uri);
    }

    /**
     * Stream the file through PHP, letting Symfony answer Range requests.
     *
     * `Accept-Ranges: bytes` is set by `prepare()`, and `setAutoEtag()` is what
     * makes a conditional `If-Range` revalidate rather than silently re-download —
     * cheap here because the etag is hashed once per response, not per byte.
     */
    private function sendDirectly(string $path): BinaryFileResponse
    {
        return response()
            ->file($path, ['Content-Type' => 'audio/mpeg'])
            ->setPrivate()
            ->setMaxAge(60 * 60 * 24 * 30)
            ->setAutoEtag();
    }

    /**
     * Answer with an empty body and let nginx serve the file from its `internal;`
     * location.
     *
     * How the URI is built — off the area key, with every segment `rawurlencode`d
     * because nginx URL-DECODES the target — is InternalRedirect's, since the download
     * route needs the identical string.
     */
    private function handOffToNginx(string $uri): LaravelResponse
    {
        return response('', Response::HTTP_OK, [
            'X-Accel-Redirect' => $uri,
            // nginx serves the bytes, but the Content-Type it would guess comes from
            // its own mime.types — naming it here keeps the two paths identical from
            // the browser's point of view.
            'Content-Type' => 'audio/mpeg',
        ])
            ->setPrivate()
            ->setMaxAge(60 * 60 * 24 * 30);
    }
}
