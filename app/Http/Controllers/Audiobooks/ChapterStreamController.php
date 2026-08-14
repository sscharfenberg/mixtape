<?php

namespace App\Http\Controllers\Audiobooks;

use App\Http\Controllers\Concerns\SendsTrackAudio;
use App\Http\Controllers\Controller;
use App\Http\Requests\Audiobooks\ChapterStreamRequest;
use App\Models\Track;
use Illuminate\Http\Response as LaravelResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * One chapter's audio bytes (`GET /audiobooks/chapters/{chapter}/stream`, route
 * `audiobooks.chapters.stream`, behind auth) — the `src` of the player's <audio> element
 * when what is playing is a book.
 *
 * THE ROUTE IS WHAT WAS MISSING, not the plumbing. Every part of serving these bytes was
 * already type-agnostic — `Track::absolutePath()` resolves the area root from the track's own
 * type, `InternalRedirect` takes that type as an argument, and the production vhost has
 * carried an `internal;` location for the audiobook area since before any of this existed. A
 * chapter queued before today got a `/music/songs/…` URL and 404'd on the music guard; this
 * route and QueuePayload::entry() are the two halves of fixing that.
 *
 * The sending itself is {@see SendsTrackAudio}, shared with the song stream.
 */
class ChapterStreamController extends Controller
{
    use SendsTrackAudio;

    /**
     * Stream one chapter's audio. The guard is the request's; the bytes are {@see SendsTrackAudio}'s,
     * shared with the song stream — the two routes differ only in which guard admits the track.
     */
    public function __invoke(ChapterStreamRequest $request, Track $chapter): BinaryFileResponse|LaravelResponse
    {
        return $this->sendAudio($chapter);
    }
}
