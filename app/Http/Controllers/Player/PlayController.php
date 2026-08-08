<?php

declare(strict_types=1);

namespace App\Http\Controllers\Player;

use App\Http\Controllers\Controller;
use App\Http\Requests\Player\StorePlayRequest;
use App\Models\Play;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Record one listen (`POST /player/plays`, route `player.plays.store`, behind auth) — the
 * beacon the client fires once it has heard enough of a track (data-model.md → listen
 * history; docs/play-queue.md for what "enough" means and why the browser decides it).
 *
 * ONE ROW PER LISTEN, not a counter, and the arithmetic is why: a household of five
 * listening three hours a day writes about 25 MB a year, against a 96 GB collection on the
 * same disk. What a counter would save in space it would delete in questions — "what did I
 * play last Tuesday", "most played this month", and the distinct-days ranking that is immune
 * to a track left on repeat overnight. A counter can always be built from events; events can
 * never be rebuilt from a counter.
 *
 * `played_at` IS THE SERVER'S CLOCK. The beacon fires live, so `now()` is within a round
 * trip of the truth and cannot be spoofed or skewed by a device with a wrong clock — unlike
 * the queue's `updatedAt`, which has to be the client's precisely because it is compared
 * against another copy the client holds.
 *
 * No de-duplication, deliberately: fifteen listens are fifteen rows. Looping a song ten
 * times IS ten plays, and the pathological case (something left on repeat all night) is a
 * question for the ranking query rather than a reason to throw away what happened.
 */
class PlayController extends Controller
{
    /** Write the listen. What is accepted, and why the track is not type-checked, is StorePlayRequest's. */
    public function __invoke(StorePlayRequest $request): SymfonyResponse
    {
        Play::query()->create([
            'user_id' => $request->user()->id,
            'track_id' => $request->validated('trackId'),
            'played_at' => now(),
        ]);

        return response()->noContent();
    }
}
