<?php

declare(strict_types=1);

namespace App\Http\Requests\Shares;

use App\Models\Share;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Give this dead link another seven days" — the guard on `PATCH /shares/{share}/renew`, pressed
 * from the expired half of the reader's own list (docs/sharing.md → "Re-activating").
 *
 * IT IS WHAT MAKES THE THIRTY-DAY GRACE PERIOD MEAN SOMETHING. Expired rows are kept for
 * `Share::PRUNE_AFTER_DAYS` so their minter can still see them, and without this the only verbs
 * on a dead row are "revoke" and "mint a second link to the same thing". With it, the three weeks
 * after a link dies are a window in which the person who sent it can simply switch it back on —
 * the same URL, already sitting in somebody's chat window, working again.
 *
 * ONLY THE MINTER MAY, exactly as with revoking, and for the same reason: this list is a list of
 * things one person decided to send, and another account reviving a row from it would be handing
 * out somebody else's capability. A 404 rather than a 403, per CLAUDE.md — on an internet-facing
 * instance "you may not renew that" confirms that the id names a real share belonging to
 * somebody else.
 *
 * A LIVE LINK CANNOT BE RENEWED, and that refusal is the load-bearing half of this class. The
 * seven-day rule is only a rule while it cannot be reset on demand: minting deliberately hands
 * back the reader's existing link rather than a fresh week (ShareController::store), so a "renew"
 * that accepted a live row would be the extension that decision exists to prevent — press it
 * every Monday and the link never dies. A DEAD row is different in kind: reviving one is a
 * decision taken after the link has already stopped working, about a URL somebody may no longer
 * have, and it is bounded by the sweep. So the guard is "mine, and finished", which the UI agrees
 * with — the button exists only on rows in the expired half.
 *
 * NO `rules()`: the URL names the row and the act has no body. How long it lives is the app's
 * constant, not a field — the same reason there is no per-link expiry anywhere in this feature.
 */
class RenewShareRequest extends FormRequest
{
    /** Whether this is the caller's own link, and whether it has actually expired. */
    public function authorize(): bool
    {
        $share = $this->route('share');

        return $share instanceof Share
            && $share->user_id === $this->user()?->id
            && ! $share->isLive();
    }

    /**
     * See the class note. Both refusals answer 404, which is right for different reasons: a
     * stranger's link must not be confirmed to exist, and for a LIVE row there is genuinely no
     * such action — nothing in the app offers renewing one, so a caller reaching here has
     * addressed something that is not there.
     */
    protected function failedAuthorization(): void
    {
        abort(Response::HTTP_NOT_FOUND);
    }
}
