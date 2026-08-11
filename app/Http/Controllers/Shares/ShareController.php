<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares;

use App\Enums\ShareSubject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shares\StoreShareRequest;
use App\Models\Share;
use Illuminate\Http\JsonResponse;

/**
 * Mint a share link (`POST /shares`, route `shares.store`, behind auth) — what the "share"
 * button in a detail page's hero presses before its modal can say anything.
 *
 * JSON RATHER THAN AN INERTIA REDIRECT, which is the whole reason it is not shaped like
 * `PlaylistTracksController` beside it: the caller is not navigating, it is asking for a
 * string to put in front of the reader. A visit would re-render the detail page — and, on a
 * page carrying a queue and a table, re-key components that have nothing to do with sharing —
 * to deliver one URL. Same reasoning as `PlayerStateController`, and the same `fetch()` on
 * the client side that `useDeleteAccount` uses.
 *
 * IT REUSES THE READER'S OWN LIVE LINK for the same subject rather than minting a second.
 * Pressing "share" twice — or coming back to an album a day later to send it to somebody
 * else — should hand back the one link, for three reasons: the "My shares" list stays a list
 * of THINGS SHARED rather than of presses, revoking is then one decision rather than a hunt
 * for duplicates, and the first recipient is not left holding a link that looks superseded.
 * The cost is that the second send inherits the first's remaining days rather than a fresh
 * seven, which is the right way round — extending a link by re-sending it is how a
 * seven-day rule quietly becomes no rule at all.
 *
 * Scoped to the reader's OWN shares, not to anybody's: two users sharing the same album get
 * a link each, so one revoking theirs cannot break the other's.
 *
 * WHAT IT DOES NOT DO YET: the URL this hands back is served (SharePageController and the two
 * media routes beside it, built the same day), but "revoke it from your dashboard" — which the
 * modal promises the reader — has no page yet, so revoking means deleting the row by hand.
 */
class ShareController extends Controller
{
    /**
     * Hand back the reader's link for this subject, minting one if they have none live.
     *
     * The subject → column mapping is the enum's, so this never names a foreign key; what a
     * share GRANTS is a separate mapping that also lives there, and deliberately delegates to
     * `PlaylistSubject` so "share this artist" and "play this artist" cannot drift apart.
     */
    public function store(StoreShareRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $subject = ShareSubject::from($validated['subject']);
        $id = (string) $validated['id'];

        $share = Share::query()
            ->where('user_id', $request->user()->id)
            ->live()
            ->ofSubject($subject, $id)
            ->first()
            ?? Share::query()->create([
                'user_id' => $request->user()->id,
                $subject->foreignKey() => $id,
                'valid_until' => now()->addDays(Share::LIFETIME_DAYS),
            ]);

        // RAW, as every controller here sends it: an ISO-8601 instant, formatted by the
        // client in the reader's own locale and timezone (Utils/formatting.ts).
        return response()->json([
            'url' => $share->url(),
            'validUntil' => $share->valid_until->toIso8601String(),
        ]);
    }
}
