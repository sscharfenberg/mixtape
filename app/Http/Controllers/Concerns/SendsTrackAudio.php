<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Track;
use App\Services\Media\InternalRedirect;
use Illuminate\Http\Response as LaravelResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send one track's audio bytes, by whichever route the environment allows.
 *
 * Shared by the song and the audiobook-chapter stream routes, which differ only in which guard
 * admits the track — a second copy of the hand-off would be the copy that stops matching. Nothing in here reads the track's TYPE —
 * `Track::absolutePath()` resolves the area root from it, and InternalRedirect takes it as an
 * argument, so both areas are already served by the same two lines.
 *
 * TWO WAYS OF SENDING THE SAME FILE, chosen by config:
 *
 * - **`X-Accel-Redirect`** when `mixtape.stream.internal_prefix` is a non-empty value (the
 *   live box). Streaming a 96 GB collection through php-fpm would tie up a worker for the
 *   whole length of every track, and there are only so many workers; nginx serves the bytes
 *   from an `internal;` location instead and PHP is free again the moment the header is
 *   written. See `docs/self-hosting/files/mixtape.prod.nginx.conf`, whose audiobook location
 *   was written before this route existed.
 * - **The file itself** when that setting is empty or absent (a dev machine, the test suite,
 *   `php -S`), where there is no nginx to hand off to and one blocked worker costs nothing.
 *
 * HTTP RANGE comes free on the direct path — Symfony's BinaryFileResponse::prepare() reads the
 * `Range` header and answers `206` with a `Content-Range`. nginx handles Range natively on the
 * accelerated path. Both matter for the same reason, and matter MORE for an audiobook than for
 * a song: without `206`, seeking into hour three of a chapter simply fails.
 */
trait SendsTrackAudio
{
    /**
     * The response for a track's audio.
     *
     * A missing file is a 404 rather than a 500: the row and the file go out of step whenever
     * something is deleted between library scans, and a dead <audio> src is the honest answer.
     */
    protected function sendAudio(Track $track): BinaryFileResponse|LaravelResponse
    {
        $path = $track->absolutePath();

        abort_unless(is_file($path) && is_readable($path), Response::HTTP_NOT_FOUND);

        // Null when no prefix is configured — including a BLANK one, which is not a nicety
        // but the trap that once 500'd every stream on the dev site. InternalRedirect carries
        // that reasoning, and the encoding rules with it.
        $uri = InternalRedirect::uriFor($track->path, $track->type);

        return $uri === null
            ? $this->sendDirectly($path)
            : $this->handOffToNginx($uri);
    }

    /**
     * Stream the file through PHP, letting Symfony answer Range requests.
     *
     * `Accept-Ranges: bytes` is set by `prepare()`, and `setAutoEtag()` is what makes a
     * conditional `If-Range` revalidate rather than silently re-download — cheap here because
     * the etag is hashed once per response, not per byte.
     *
     * `private` caching because this instance is internet-facing behind auth: a shared proxy
     * must never end up holding a track.
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
     * Answer with an empty body and let nginx serve the file from its `internal;` location.
     *
     * How the URI is built — off the area key, with every segment `rawurlencode`d because
     * nginx URL-DECODES the target — is InternalRedirect's, since the download route needs
     * the identical string.
     */
    private function handOffToNginx(string $uri): LaravelResponse
    {
        return response('', Response::HTTP_OK, [
            'X-Accel-Redirect' => $uri,
            // nginx serves the bytes, but the Content-Type it would guess comes from its own
            // mime.types — naming it here keeps the two paths identical to the browser.
            'Content-Type' => 'audio/mpeg',
        ])
            ->setPrivate()
            ->setMaxAge(60 * 60 * 24 * 30);
    }
}
