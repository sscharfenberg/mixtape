<?php

declare(strict_types=1);

namespace App\Http\Controllers\Playlists;

use App\Http\Controllers\Concerns\SendsAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Playlists\ExportPlaylistsRequest;
use App\Models\Playlist;
use App\Services\Media\ZipStream;
use App\Services\Playlists\PlaylistArchive;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every playlist the reader keeps, as one .zip (`GET /playlists/export`, route
 * `playlists.export.all`, behind auth) — the listing's "export all" button.
 *
 * A COLLECTION ROUTE, like `PUT /playlists/order` beside it: the subject is the SET, and it
 * cannot be mistaken for a member of it because the `{playlist}` routes are UUID-constrained.
 * There are no ids in the request either — what a reader may export is what the query below
 * returns, which is scoped to them, so there is no ownership check to forget.
 *
 * WHY ONE ARCHIVE RATHER THAN N DOWNLOADS is PlaylistArchive's docblock: a page gets one
 * navigation, and the workarounds run into the browser's own "allow multiple downloads?"
 * prompt, after which a refusal loses every file silently.
 *
 * IT STREAMS, and the one cost that lands here is the one the song routes avoid with
 * `X-Accel-Redirect`: a zip does not exist on disk, so php-fpm holds a worker for the transfer.
 * That is cheap in a way an album's gigabyte is not — a reader's whole playlist collection is
 * kilobytes of text — and it is a deliberate, occasional act rather than something the player
 * fires per track.
 *
 * The three options are validated by the same trait the single export uses: one playlist and
 * all of them ask the same three questions.
 */
class PlaylistsExportController extends Controller
{
    use SendsAttachments;

    /**
     * Render every playlist and answer with the archive.
     *
     * ORDERED AS THE LISTING IS — the reader's own arrangement, `name` breaking the tie — so the
     * archive they unpack is in the order of the page they pressed the button on.
     *
     * A reader with no playlists is a 404 rather than an empty zip, the rule the album download
     * follows: the button is not drawn for them, so this is a hand-written URL, and an archive
     * of nothing is not an answer to it.
     */
    public function __invoke(ExportPlaylistsRequest $request): StreamedResponse
    {
        $options = $request->validated();

        $playlists = Playlist::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        abort_if($playlists->isEmpty(), Response::HTTP_NOT_FOUND);

        $archive = ZipStream::ofContents(
            PlaylistArchive::entries($playlists, $options['format'], $options['encoding'], $options['prefix']),
            // One instant for every entry, taken here rather than inside the zip writer: the
            // archive is made now, and a per-entry clock would stamp a long export unevenly.
            now()->getTimestamp()
        );

        return response()->stream(fn () => $archive->stream(), Response::HTTP_OK, [
            'Content-Type' => 'application/zip',
            // Exact before a byte is written, because nothing is compressed — the browser gets a
            // progress bar rather than a spinner.
            'Content-Length' => (string) $archive->contentLength(),
            'Content-Disposition' => $this->attachment(PlaylistArchive::filename(), 'playlists.zip'),
            // Tell nginx not to spool this through a temp file before forwarding it, which is
            // its default for a large upstream reply.
            'X-Accel-Buffering' => 'no',
        ])->setPrivate();
    }
}
