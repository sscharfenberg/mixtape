<?php

namespace App\Http\Controllers\Audiobooks;

use App\Http\Controllers\Concerns\SendsAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audiobooks\AudiobookDownloadRequest;
use App\Models\Collection;
use App\Services\Media\ZipStream;
use App\Services\Music\AlbumArchive;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A whole book as a .zip (`GET /audiobooks/{audiobook}/download`, route
 * `audiobooks.download`, behind auth) — the hero's download button.
 *
 * AlbumArchive unchanged, despite the name: it asks the collection which area it belongs to
 * (`$album->type->trackType()`) and resolves every path from that, so it has been able to
 * build an audiobook's archive since the day it was written — multi-disc structure and the
 * non-audio files beside the chapters included, which for a book means the `Folder.jpg`. The
 * only thing that was missing is a route allowed to ask.
 *
 * A BOOK IS THE BIGGEST ZIP THIS APP MAKES — the largest here runs to 673 chapters — which is
 * what the streaming and the two headers below are for; ZipStream's docblock carries the
 * measured reasoning. php-fpm holds a worker for the length of the transfer, acceptable for a
 * deliberate, occasional download on a home server in a way it is not for playback.
 */
class AudiobookDownloadController extends Controller
{
    use SendsAttachments;

    /**
     * Answer with the book's archive.
     *
     * A book with nothing to send is a 404 rather than an empty zip — only the service,
     * holding the model, can know that the files behind these rows have gone.
     */
    public function __invoke(AudiobookDownloadRequest $request, Collection $audiobook): StreamedResponse
    {
        $archive = new ZipStream(AlbumArchive::entries($audiobook));

        abort_if($archive->isEmpty(), Response::HTTP_NOT_FOUND);

        return response()->stream(fn () => $archive->stream(), Response::HTTP_OK, [
            'Content-Type' => 'application/zip',
            // Known exactly before a byte is written, because nothing is compressed — which
            // is what gives the browser a progress bar instead of a spinner.
            'Content-Length' => (string) $archive->contentLength(),
            'Content-Disposition' => $this->attachment(AlbumArchive::filename($audiobook), 'audiobook.zip'),
            // Tell nginx not to buffer: its default spools a large upstream reply through a
            // temp file first, which on a multi-gigabyte book would write the whole archive
            // to the system disk and hold the first byte back until it was done.
            'X-Accel-Buffering' => 'no',
        ])->setPrivate();
    }
}
