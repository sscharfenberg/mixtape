<?php

declare(strict_types=1);

namespace App\Http\Controllers\Player;

use App\Enums\PlaylistSubject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Player\QueueTracksRequest;
use App\Services\Player\QueueSelection;
use Illuminate\Http\JsonResponse;

/**
 * The queue entries behind a table SELECTION (`POST /queue/tracks`, route `queue.tracks`,
 * behind auth) — what a listing's "play" and "add to queue" bulk actions call once a reader has
 * ticked some rows.
 *
 * JSON RATHER THAN AN INERTIA PARTIAL RELOAD, the same architectural exception SearchController
 * argues at length and the fourth of its kind here (after minting a share and syncing the player
 * state). The four detail pages fetch their queue entries as an OPTIONAL `queueTracks` prop,
 * which works because such a page IS one subject: the prop belongs to the page and the id it
 * resolves is in the URL. A listing is about nothing in particular, so there is no such prop to
 * ask for — and a selection could not be asked for that way regardless, since it exists only in
 * the browser and only until the next click.
 *
 * A POST DESPITE BEING A READ, which is the one place this route departs from what the verb
 * usually says. A hundred ticked rows is some 3,700 characters of UUID; as a query string that
 * is a URL near the limit of what proxies accept, written into the reader's history, and
 * logged in full by nginx. None of that is true of a body.
 *
 * The response is a bare list, in the shape `QueueTrack` expects, so `usePlayerQueue` can take
 * it as-is — the same shape and the same order the `queueTracks` prop delivers, because both
 * come out of QueuePayload.
 */
class QueueTracksController extends Controller
{
    /**
     * Resolve the selection and answer with its queue entries.
     *
     * A thin action: the request owns what may be asked (including the ceiling, which is the one
     * bound a rule cannot express), and QueueSelection owns which tracks a kind and its ids
     * actually mean — including the audiobook question the two verbs disagree with the playlist
     * path about.
     *
     * `private, no-store` for the same reason SearchController sets it, minus the personal half:
     * nothing here is per-reader, but a selection is a one-shot answer to a question the URL
     * does not describe, so there is nothing a cache could correctly do with it.
     */
    public function __invoke(QueueTracksRequest $request): JsonResponse
    {
        $data = $request->validated();

        $tracks = QueueSelection::payload(
            PlaylistSubject::from($data['subject']),
            array_values($data['ids'])
        );

        return response()
            ->json($tracks)
            ->header('Cache-Control', 'private, no-store');
    }
}
