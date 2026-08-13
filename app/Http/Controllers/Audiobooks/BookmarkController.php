<?php

namespace App\Http\Controllers\Audiobooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audiobooks\UpdateBookmarkRequest;
use App\Models\AudiobookBookmark;
use App\Models\Collection;
use Illuminate\Http\Response;

/**
 * Where the reader has got to in one book (`PUT /audiobooks/{audiobook}/bookmark`, route
 * `audiobooks.bookmark`, behind auth).
 *
 * A PUT rather than a POST because it is idempotent by nature — one row per (reader, book),
 * written over and over as a chapter plays. 204 rather than the row: the browser sent the
 * value and has nothing to learn from it coming back, and this fires on a heartbeat.
 *
 * UPSERT ON THE COMPOSITE KEY, which the database enforces: two rows for one book could only
 * ever disagree about where the reader is.
 */
class BookmarkController extends Controller
{
    /** Store the reader's place in this book. */
    public function __invoke(UpdateBookmarkRequest $request, Collection $audiobook): Response
    {
        AudiobookBookmark::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'collection_id' => $audiobook->id],
            [
                'track_id' => $request->validated('trackId'),
                'position_ms' => $request->validated('positionMs'),
                // Written explicitly: `updateOrCreate` touches timestamps for us, but only
                // when something CHANGED — and a reader who pauses in the same spot for an
                // hour is still reading this book, which is what a Continue Listening shelf
                // will sort on.
                'updated_at' => now(),
            ],
        );

        return response()->noContent();
    }
}
