<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Share;
use App\Services\Shares\ShareGrant;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * THE READER'S OWN SHARE LINKS (`GET /dashboard/shared`, route `dashboard.shares`, behind
 * auth) — the page the share modal has been promising since minting was built, and the only
 * place a link can be revoked (docs/sharing.md → "Revoking").
 *
 * A DASHBOARD SUBPAGE RATHER THAN A DASHBOARD SECTION, because a section would have to be
 * either a stub or a list of unknown length in the middle of a settings page. What sits on the
 * dashboard is a heading and a link; the list lives here, where it can be as long as it is.
 *
 * IT IS SCOPED TO THE READER AND SENT AS TWO LISTS. Two people
 * sharing the same album hold a link each — `ShareController::store` mints per user — so this
 * is a list of what THIS reader has sent, split into the links that still work and the ones
 * that have run out of days. Both halves are here rather than the dead ones being hidden: a
 * link that has died is still a thing they made, and a list that quietly shrank would read as
 * links going missing. Splitting them is what the single mixed list could not do — one glance
 * says how much is live, and the row a reader means to copy cannot be a dead one.
 *
 * EACH HALF IS SORTED BY WHAT THE READER IS LOOKING FOR IN IT, which is why the two orders are
 * opposite. A live link is found by "which one dies next", so those run soonest-first; a dead
 * one is found by "the link I sent last week has stopped working", so those run most-recently-
 * dead first. One query, ordered ascending, partitioned and the dead half reversed.
 *
 * THE SUBJECT'S NAME COMES FROM ShareGrant, not from a match written here, for the reason
 * CLAUDE.md states about that class: anything under the share feature asks it rather than
 * re-deriving what a share is about. The relations are eager-loaded, so a list of thirty links
 * is four queries rather than ninety.
 */
class SharesController extends Controller
{
    /**
     * The reader's links, live and expired, each half already in the order it is read in.
     *
     * ONE QUERY FOR BOTH, partitioned in PHP rather than fetched twice: the split is a
     * comparison against `now()` that the rows carry with them, and two queries would also be
     * two chances for a link to expire between them — a link is at its most likely to cross the
     * line exactly while its owner is looking at the page.
     */
    public function __invoke(Request $request): Response
    {
        [$live, $dead] = Share::query()
            ->where('user_id', $request->user()->id)
            // `collection:id,name,TYPE` — the type is not decoration here: `collection_id` is
            // the one FK that does not name its own kind, so ShareGrant::subject() reads the
            // row to tell an audiobook share from an album one. Select only id and name and
            // every book in this list calls itself an album.
            ->with(['track:id,name', 'collection:id,name,type', 'artist:id,name', 'playlist:id,name'])
            ->orderBy('valid_until')
            ->get()
            ->partition(fn (Share $share): bool => $share->isLive());

        return Inertia::render('Dashboard/Shares/SharesPage', [
            'shares' => $this->rows($live),
            // REVERSED, because the query sorted by expiry ascending and that puts the oldest
            // corpse on top. What a reader comes to this half asking is "where has the link I
            // sent on Monday gone?", so the one that died most recently is the one to meet first.
            'expiredShares' => $this->rows($dead->reverse()),
        ]);
    }

    /**
     * One half of the list, as rows the page can draw.
     *
     * `kind` and `name` describe the SUBJECT; there is no "expired" among them, because which
     * list a row is in is the answer to that — see resources/app/types/shares.ts. The page
     * prints the pair as a pip beside the name, which is why the two travel apart: a single
     * pre-formatted string would be a translation the server has no business making.
     *
     * @param  Collection<int, Share>  $shares  live or dead, already in reading order
     * @return list<array<string, mixed>> rows in the shape `ShareRow` expects
     */
    private function rows(Collection $shares): array
    {
        return $shares
            ->map(function (Share $share): ?array {
                $grant = ShareGrant::for($share);
                $subject = $grant->subject();

                // A row whose subject this app cannot resolve. Every column the table permits
                // has a case, so this can only be
                // a row with no subject at all, which the CHECK forbids. It is dropped rather
                // than drawn as a blank anyway: a line saying nothing, with a revoke button
                // beside it, is worse than a row the reader never made being absent.
                return $subject === null ? null : [
                    'id' => $share->id,
                    'kind' => $subject->value,
                    'name' => $grant->subjectName(),
                    // ABSOLUTE, and from the model rather than built on the client. The copy
                    // button puts this straight into a chat window, where a root-relative
                    // path is not a link at all — and `Share::url()` is the same string the
                    // mint response hands back, so what a reader copies here and what they
                    // copied from the modal cannot come to differ.
                    'url' => $share->url(),
                    // Raw, like every instant this app sends — the page formats it in the
                    // reader's own locale and timezone.
                    'validUntil' => $share->valid_until->toIso8601String(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
