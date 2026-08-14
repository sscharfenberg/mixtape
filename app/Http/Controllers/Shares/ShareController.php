<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares;

use App\Enums\ShareSubject;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shares\DestroyShareRequest;
use App\Http\Requests\Shares\RenewShareRequest;
use App\Http\Requests\Shares\StoreShareRequest;
use App\Models\Share;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

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
 * THE URL THIS HANDS BACK IS SERVED (SharePageController and the two media routes beside it), and
 * `destroy` below is the revoke the modal promises the reader, reachable from `/dashboard/shared`.
 *
 * THREE VERBS, ONE LIFECYCLE, and they are here together on purpose: mint, renew, revoke are the
 * only three things that ever happen to a `shares` row, and reading them in one file is what
 * keeps their rules consistent — most of all the one they share, that a link's seven days are the
 * app's decision rather than the reader's (`store` re-hands rather than extends, `renew` refuses a
 * live row, and neither takes a duration).
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

    /**
     * Re-activate an expired link: the same URL, live again for another seven days.
     *
     * THIS IS WHAT THE GRACE PERIOD IS FOR. A dead row is kept for `Share::PRUNE_AFTER_DAYS` so
     * its minter can still see it — and without renewal all they could do with it is revoke it or
     * mint a second link to the same subject, which leaves the link already sitting in somebody's
     * chat window broken and the recipient needing a new address. Renewing puts the original back
     * to work, so "it expired, send it again" is a button rather than a conversation.
     *
     * SEVEN DAYS FROM NOW, not seven added to what was there: the row is finished, so there is no
     * remainder to extend, and `now()` is the only honest start. Which is also why the request
     * refuses a LIVE row — that direction is the extension the mint route's reuse rule exists to
     * prevent (see RenewShareRequest, which carries the whole argument).
     *
     * A reader who already minted a replacement while this one was dead now holds two live links
     * to the same subject. That is not a bug to guard: both were deliberate acts, and `store`
     * above hands back whichever it finds first — one link revoked is one row deleted, and the
     * other goes on working.
     *
     * A redirect back rather than JSON, like `destroy` below: the caller is on their list of
     * links and expects to see the row move from the expired half to the live one.
     */
    public function renew(RenewShareRequest $request, Share $share): RedirectResponse
    {
        $share->update(['valid_until' => now()->addDays(Share::LIFETIME_DAYS)]);

        return back()->with([
            'message' => __('flash.share.renewed', ['days' => Share::LIFETIME_DAYS]),
            'type' => 'success',
        ]);
    }

    /**
     * Revoke a link — which is to say, delete the row that IS the link.
     *
     * NOTHING ELSE HAS TO HAPPEN, and that is the payoff of the row-not-a-signature decision
     * this feature was built on (docs/sharing.md → "Why a row and not a signed URL"). Every
     * route under `/s/{share}` resolves the share by implicit binding, so the moment this
     * returns, the page, both cover routes and the stream all 404 at the router — for the
     * holder of the link and for anybody they forwarded it to. There is no cache to purge and
     * no token list to append to; a signature would have needed both.
     *
     * WHO MAY DO IT IS THE REQUEST'S, and a stranger's id gets a 404 rather than a 403 — see
     * DestroyShareRequest.
     *
     * A REDIRECT BACK RATHER THAN JSON, unlike `store` above, because the caller here IS
     * navigating: the reader is on their list of links and expects to see one row fewer. The
     * flash rides back with it, which is the only announcement the act gets — a revoked link
     * leaves nothing on screen to point at.
     */
    public function destroy(DestroyShareRequest $request, Share $share): RedirectResponse
    {
        $share->delete();

        return back()->with([
            'message' => __('flash.share.revoked'),
            'type' => 'success',
        ]);
    }
}
