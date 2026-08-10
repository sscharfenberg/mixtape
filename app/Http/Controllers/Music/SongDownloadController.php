<?php

namespace App\Http\Controllers\Music;

use App\Http\Controllers\Concerns\SendsAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Music\SongDownloadRequest;
use App\Models\Track;
use App\Services\Media\InternalRedirect;
use Illuminate\Http\Response as LaravelResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * One song as a file to keep (`GET /music/songs/{song}/download`, route
 * `music.songs.download`, behind auth) — the hero's download button.
 *
 * THE FILE AS IT IS: the mp3 off the share, byte for byte, under its own name. Nothing
 * is re-encoded and no tag is rewritten, so what lands in the reader's Downloads folder
 * is what would land there over Samba. That is also why the filename is the file's own
 * (`basename` of the stored path) rather than one built from tags: the collection's
 * naming is deliberate, and a download that renames things fights it.
 *
 * The only difference from SongStreamController — which sends the same bytes to the
 * player — is `Content-Disposition: attachment`, which is what turns a navigation into a
 * download instead of a tab playing music. Both halves of the send are shared with it:
 * the nginx hand-off when one is configured (InternalRedirect), the file itself when it
 * is not. A download is the heavier of the two cases for that hand-off, being the whole
 * file at whatever speed the link allows.
 */
class SongDownloadController extends Controller
{
    use SendsAttachments;

    /**
     * Send the song as an attachment, by whichever route the environment allows.
     *
     * A missing file is a 404 rather than a 500, the same call the stream and cover
     * routes make: the row and the file go out of step whenever something is deleted
     * between library scans, and the honest answer is that there is nothing to download.
     * The check runs on BOTH paths — skipping it under the hand-off would answer 200 and
     * let nginx serve its own 404 page as an attachment called `.mp3`.
     */
    public function __invoke(SongDownloadRequest $request, Track $song): BinaryFileResponse|LaravelResponse
    {
        $path = $song->absolutePath();

        abort_unless(is_file($path) && is_readable($path), Response::HTTP_NOT_FOUND);

        $headers = [
            'Content-Type' => 'audio/mpeg',
            // Built once and used on both paths, so the two cannot spell the header
            // differently — a name with an umlaut in it is the case that would show it.
            'Content-Disposition' => $this->attachment(basename($song->path), 'song.mp3'),
        ];

        $uri = InternalRedirect::uriFor($song->path, $song->type);

        return $uri === null
            ? response()->file($path, $headers)->setPrivate()
            : response('', Response::HTTP_OK, $headers + ['X-Accel-Redirect' => $uri])->setPrivate();
    }
}
