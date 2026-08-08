<?php

declare(strict_types=1);

namespace App\Http\Controllers\Player;

use App\Http\Controllers\Controller;
use App\Http\Requests\Player\UpdatePlayerStateRequest;
use App\Services\Player\PlayerStatePayload;
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
 * WHAT IT ACCEPTS lives in UpdatePlayerStateRequest, including the cap that is deliberately
 * above the whole library — a listener can queue a genre of several thousand in one press.
 */
class PlayerStateController extends Controller
{
    /**
     * Replace the stored queue with the one in the request.
     *
     * What is accepted — including the cap above the whole library and the -1 that means
     * "nothing loaded" — is UpdatePlayerStateRequest's.
     */
    public function __invoke(UpdatePlayerStateRequest $request): SymfonyResponse
    {
        $validated = $request->validated();

        PlayerStatePayload::store(
            $request->user(),
            array_values($validated['tracks']),
            (int) $validated['currentIndex'],
            (bool) $validated['repeat'],
            (bool) $validated['shuffle'],
            (int) $validated['updatedAt'],
            (int) $validated['positionMs'],
        );

        return response()->noContent(Response::HTTP_NO_CONTENT);
    }
}
