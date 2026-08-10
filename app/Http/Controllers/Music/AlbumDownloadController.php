<?php

namespace App\Http\Controllers\Music;

use App\Http\Controllers\Concerns\SendsAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Music\AlbumDownloadRequest;
use App\Models\Collection;
use App\Services\Media\ZipStream;
use App\Services\Music\AlbumArchive;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A whole album as a .zip (`GET /music/albums/{album}/download`, route
 * `music.albums.download`, behind auth) — the hero's download button.
 *
 * What goes in is AlbumArchive's decision (the tracks from the database, the non-audio
 * files from their directories, multi-disc structure preserved) and how it is written is
 * ZipStream's (stored, streamed, exact length). What is left here is the response: an
 * attachment, and the two headers that make a gigabyte behave.
 *
 * IT STREAMS RATHER THAN BUILDING A FILE FIRST — ZipStream's docblock has the measured
 * reasoning. The one cost that lands in this controller is the one the song routes avoid
 * with `X-Accel-Redirect`: a zip does not exist on disk, so there is nothing for nginx to
 * serve and php-fpm holds a worker for the length of the transfer. That is acceptable
 * here where it is not for streaming audio — a download is a deliberate, occasional act
 * on a home server, not something the player fires per track.
 */
class AlbumDownloadController extends Controller
{
    use SendsAttachments;

    /**
     * Answer with the album's archive.
     *
     * An album with nothing to send is a 404 rather than an empty zip — the same inline
     * check the cover routes make, and for the same reason: only the service, holding the
     * model, can know that the files behind these rows have gone.
     */
    public function __invoke(AlbumDownloadRequest $request, Collection $album): StreamedResponse
    {
        $archive = new ZipStream(AlbumArchive::entries($album));

        abort_if($archive->isEmpty(), Response::HTTP_NOT_FOUND);

        return response()->stream(fn () => $archive->stream(), Response::HTTP_OK, [
            'Content-Type' => 'application/zip',
            // Known exactly before a byte is written, because nothing is compressed —
            // which is what gives the browser a progress bar instead of a spinner.
            'Content-Length' => (string) $archive->contentLength(),
            'Content-Disposition' => $this->attachment(AlbumArchive::filename($album), 'album.zip'),
            // Tell nginx not to buffer this response. Its default is to spool a large
            // upstream reply through a temp file before forwarding it, which on a 1 GB
            // album would write the whole archive to the system disk — precisely the
            // temp file streaming exists to avoid — and hold the first byte back until
            // it was done.
            'X-Accel-Buffering' => 'no',
        ])->setPrivate();
    }
}
