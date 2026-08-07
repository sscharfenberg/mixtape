<?php

declare(strict_types=1);

namespace App\Http\Controllers\Player;

use App\Http\Controllers\Controller;
use App\Services\Player\PlayerStatePayload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Store the signed-in user's play queue (`PUT /player/state`, route
 * `player.state.update`, behind auth) — the write half of the sync the client's
 * `flushQueueWrites()` drives.
 *
 * NOT AN INERTIA RESPONSE, and that is the point of it being its own controller: the
 * client is not navigating, it is telling the server where it got to. An Inertia visit
 * would re-render a page nobody asked for and hand back props the player would then have
 * to ignore. This answers `204` and nothing else — cheap enough to fire on every track
 * change, including from a tab that is being closed.
 *
 * IDS ONLY, because the server already has the tracks; see PlayerStatePayload for why the
 * two directions are shaped differently.
 *
 * THE CAP IS ABOVE THE WHOLE LIBRARY (12,058 tracks at the time of writing), deliberately:
 * a listener can queue a genre of several thousand in one press, and a sync that silently
 * refused the queue they can see would be worse than a large row. It exists to bound what a
 * hand-written request can put in the database, not to police ordinary use.
 */
class PlayerStateController extends Controller
{
    /** Above anything the library can produce — see the class note. */
    private const MAX_TRACKS = 20000;

    /**
     * Replace the stored queue with the one in the request.
     *
     * `currentIndex` accepts -1, which is the client's "nothing loaded" — an empty queue is
     * a state worth syncing, since clearing the queue on one device should not leave the
     * other restoring it forever.
     */
    public function __invoke(Request $request): SymfonyResponse
    {
        $validated = $request->validate([
            'tracks' => ['present', 'array', 'max:'.self::MAX_TRACKS],
            'tracks.*' => ['string', 'uuid'],
            'currentIndex' => ['required', 'integer', 'min:-1', 'max:'.self::MAX_TRACKS],
            'repeat' => ['required', 'boolean'],
            'shuffle' => ['required', 'boolean'],
            // The client's own clock, in milliseconds. Stored verbatim and handed back on
            // the next page load, where the browser compares it with its local copy to
            // decide which is newer — so it is data, not something to trust or correct.
            'updatedAt' => ['required', 'integer', 'min:0'],
        ]);

        PlayerStatePayload::store(
            $request->user(),
            array_values($validated['tracks']),
            (int) $validated['currentIndex'],
            (bool) $validated['repeat'],
            (bool) $validated['shuffle'],
            (int) $validated['updatedAt'],
        );

        return response()->noContent(Response::HTTP_NO_CONTENT);
    }
}
