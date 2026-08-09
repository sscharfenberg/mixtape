<?php

namespace App\Http\Controllers\Playlists;

use App\Http\Controllers\Controller;
use App\Http\Requests\Playlists\ExportPlaylistRequest;
use App\Models\Playlist;
use App\Services\Playlists\PlaylistExport;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download one playlist as an .m3u file (`GET /playlists/{playlist}/export`, route
 * `playlists.export`, behind auth).
 *
 * A GET, and that is the one decision here. The obvious alternative is a POST answered with a
 * blob the page turns into an object URL and clicks — and it buys nothing: the browser already
 * knows how to download a URL, and doing it natively means the file streams to disk rather
 * than through JavaScript's memory, the progress and the cancel are the browser's own, and
 * there is no object URL to remember to revoke. The options are query params because they are
 * options ON a read, and the request is idempotent and cacheable in principle — a reader can
 * bookmark one export shape and get the current playlist every time.
 *
 * NOT WRITTEN TO DISK ANYWHERE. Legacy put the file in `storage/app/downloads` and served it
 * from there, leaving a copy per playlist forever and racing two concurrent exports of the same
 * one; PlaylistExport builds the bytes and they go straight out. See its docblock for the other
 * five things that changed.
 *
 * Ownership and the option list live in ExportPlaylistRequest — 404, never 403, for a
 * playlist that is not the reader's.
 */
class PlaylistExportController extends Controller
{
    /**
     * Render the playlist and answer with it as an attachment.
     *
     * `Content-Length` is set because the body is a string we already hold, and a length turns
     * a browser's download from an unknown quantity into a progress bar.
     *
     * The filename goes through both parameters of `Content-Disposition`: `filename` for
     * clients that read only ASCII, `filename*` (RFC 5987) for the rest, which is what carries
     * an umlaut in a playlist's name intact. Symfony builds the pair, and PlaylistExport has
     * already stripped what would break the header.
     */
    public function __invoke(ExportPlaylistRequest $request, Playlist $playlist): Response
    {
        $options = $request->validated();
        $body = PlaylistExport::render($playlist, $options['format'], $options['encoding'], $options['prefix']);
        $filename = PlaylistExport::filename($playlist);

        return response($body, Response::HTTP_OK, [
            // The real type for an .m3u, where legacy sent `application/vnd` — not a media type
            // at all. The charset rides along so a player that honours it does not have to
            // guess, which is the entire point of offering the choice.
            'Content-Type' => 'audio/x-mpegurl; charset='.$options['encoding'],
            'Content-Length' => (string) strlen($body),
            'Content-Disposition' => $this->attachment($filename),
        ]);
    }

    /**
     * The `Content-Disposition` value, with both filename spellings.
     *
     * Symfony's helper rather than a hand-built string: it escapes the ASCII fallback and
     * percent-encodes the UTF-8 one, which is exactly the pair of mistakes a concatenated
     * header makes. The fallback is derived by dropping anything non-ASCII, since that
     * parameter may hold nothing else.
     */
    private function attachment(string $filename): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'playlist.m3u';

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $ascii);
    }
}
