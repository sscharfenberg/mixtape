<?php

namespace App\Http\Controllers\Music;

use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Track;
use Illuminate\Http\Request;
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
    public function __invoke(Request $request, Track $song): BinaryFileResponse|LaravelResponse
    {
        abort_unless($song->type === TrackType::Music, Response::HTTP_NOT_FOUND);

        $path = $song->absolutePath();

        abort_unless(is_file($path) && is_readable($path), Response::HTTP_NOT_FOUND);

        // EMPTY counts as unset, exactly as an unconfigured library area does
        // (LibraryScanService::scanArea) — and here it is not a nicety. `.env` ships
        // this key blank, and a blank dotenv value arrives as an empty STRING, not
        // null: a `=== null` check therefore turned the hand-off ON with no prefix,
        // which pointed X-Accel-Redirect at a URI nothing serves, bounced it back
        // through `try_files` into index.php, and cost every stream a 500 with an
        // nginx "internal redirection cycle" and nothing at all in Laravel's log.
        $prefix = trim((string) config('mixtape.stream.internal_prefix'));

        return $prefix === ''
            ? $this->sendDirectly($path)
            : $this->handOffToNginx($song, $prefix);
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
     * The URI is built from the AREA KEY rather than by subtracting the media root
     * from the absolute path, so there is no prefix arithmetic to get wrong: each
     * area gets its own `internal;` location whose `alias` is that area's
     * `MIXTAPE_*_PATH`, and the key naming them is the one
     * `config('mixtape.library.paths.*')` already uses.
     *
     * Every segment is `rawurlencode`d because nginx URL-DECODES the redirect
     * target: this collection is full of spaces, umlauts, `#` and `&` in file
     * names, and an unencoded `#` would truncate the path at the fragment and 404
     * a track that plays perfectly well over the direct route.
     */
    private function handOffToNginx(Track $song, string $prefix): LaravelResponse
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($song->path, '/'))));
        $uri = rtrim($prefix, '/').'/'.$song->type->libraryPathKey().'/'.$encoded;

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
