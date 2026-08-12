<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Share;
use App\Services\Shares\ShareGrant;
use Illuminate\Http\Request;
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
 * IT IS SCOPED TO THE READER AND SORTED BY WHAT DIES SOONEST. Two people sharing the same album
 * hold a link each — `ShareController::store` mints per user — so this is a list of what THIS
 * reader has sent, and the one nearest expiry is the one they are most likely to be looking
 * for. Expired rows are listed too, rather than hidden: a link that has died is still a thing
 * they made, and a list that quietly shrank would read as links going missing. They can be
 * revoked like any other, which is how a reader tidies up before pruning exists.
 *
 * THE SUBJECT'S NAME COMES FROM ShareGrant, not from a match written here, for the reason
 * CLAUDE.md states about that class: anything under the share feature asks it rather than
 * re-deriving what a share is about. The relations are eager-loaded, so a list of thirty links
 * is four queries rather than ninety.
 */
class SharesController extends Controller
{
    /**
     * The reader's links: what each is about, when it dies, and whether it already has.
     *
     * `kind` and `name` describe the SUBJECT; `expired` is about the link. The page prints
     * them as "(Artist) Black Sabbath", which is why the two travel apart — a single
     * pre-formatted string would be a translation the server has no business making.
     */
    public function __invoke(Request $request): Response
    {
        $shares = Share::query()
            ->where('user_id', $request->user()->id)
            ->with(['track:id,name', 'collection:id,name', 'artist:id,name'])
            ->orderBy('valid_until')
            ->get()
            ->map(function (Share $share): ?array {
                $grant = ShareGrant::for($share);
                $subject = $grant->subject();

                // A row whose subject this app cannot resolve — only a hand-written
                // `playlist_id` today, since the enum has no case to mint one with. It is
                // dropped rather than drawn as a blank: a line saying nothing, with a revoke
                // button beside it, is worse than a link the reader never made being absent.
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
                    'expired' => ! $share->isLive(),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return Inertia::render('Dashboard/Shares/SharesPage', ['shares' => $shares]);
    }
}
