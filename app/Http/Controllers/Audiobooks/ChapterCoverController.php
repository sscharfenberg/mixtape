<?php

namespace App\Http\Controllers\Audiobooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audiobooks\ChapterCoverRequest;
use App\Models\Track;
use App\Services\Media\CoverService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * One chapter's cover art (`GET /audiobooks/chapters/{chapter}/cover`, route
 * `audiobooks.chapters.cover`, behind auth) — what the play queue and the player bar draw
 * beside a playing chapter.
 *
 * The chapter grain rather than the book's, for the reason the queue needs it: a queue row is
 * a TRACK, and `QueuePayload::entry()` already decides per track whether there is an image to
 * point at. `CoverService::path()` is type-blind — it prefers the file's embedded picture and
 * falls back to the directory image — so a chapter with no art of its own still shows its
 * book's `Folder.jpg`, which is what most rips have.
 *
 * A chapter with art from neither source 404s, which the queue never triggers: `entry()`
 * sends `coverUrl: null` for those and the row draws its placeholder.
 */
class ChapterCoverController extends Controller
{
    /** Injected so the extraction/caching policy stays in one testable place. */
    public function __construct(private readonly CoverService $covers) {}

    /**
     * Send the cached cover as a private, long-lived, revalidatable image.
     *
     * `private` because this instance is internet-facing behind auth. The long max-age is
     * safe for the same reason it is on a song: the URL carries the track id, and a track id
     * belongs to one set of audio bytes.
     */
    public function __invoke(ChapterCoverRequest $request, Track $chapter): BinaryFileResponse
    {
        $path = $this->covers->path($chapter);

        abort_if($path === null, Response::HTTP_NOT_FOUND);

        return response()
            ->file($path, ['Content-Type' => 'image/jpeg'])
            ->setPrivate()
            ->setMaxAge(60 * 60 * 24 * 30)
            ->setAutoEtag();
    }
}
