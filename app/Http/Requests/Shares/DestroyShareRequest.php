<?php

declare(strict_types=1);

namespace App\Http\Requests\Shares;

use App\Models\Share;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Revoke this link" — the guard on `DELETE /shares/{share}`, pressed from the reader's own
 * list of shares (docs/sharing.md → "Revoking").
 *
 * REVOKING IS A DELETE, AND THAT IS THE WHOLE MECHANISM. There is no `revoked_at` column and
 * no tombstone: the row IS the capability, so removing it removes the ability to play what it
 * named. Everything under `/s/{share}` resolves the row by implicit binding, so a revoked link
 * 404s at the router before any controller runs — the page, the subject cover, the per-track
 * cover and the stream alike — and it 404s indistinguishably from a typo, which is the right
 * answer to give a stranger holding a dead link.
 *
 * ONLY THE MINTER MAY REVOKE. `shares.user_id` is who made it, and this is the first place in
 * the share feature where ownership means anything at all: minting needs none (every account
 * sees the whole library, so there is no subject one user may share and another may not), and
 * the `/s/` space needs none (holding the id IS the permission). A list of links is different
 * — it is a list of things one person decided to send, and another account revoking from it
 * would be deleting somebody else's capability.
 *
 * A 404 RATHER THAN A 403, per CLAUDE.md: this instance is internet-facing and shared between
 * family and friends, so "you may not revoke that" confirms that the id names a real, live
 * share belonging to somebody else. "There is no such link" tells a caller nothing they did
 * not already bring with them.
 *
 * NO `rules()`, because there is no input: the URL names the row and the act has no body. What
 * a reader is asked to confirm ("whoever has this link will not be able to use it") is a modal
 * on the page, not a field — a checkbox that must be ticked would be the same click twice.
 */
class DestroyShareRequest extends FormRequest
{
    /** Whether the signed-in reader is the one who minted this link. */
    public function authorize(): bool
    {
        $share = $this->route('share');

        return $share instanceof Share && $share->user_id === $this->user()?->id;
    }

    /** See the class note: a 403 here would confirm somebody else's link exists. */
    protected function failedAuthorization(): void
    {
        abort(Response::HTTP_NOT_FOUND);
    }
}
