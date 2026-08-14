<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed email-verification link (`GET /verify-email/{id}/{hash}`).
 *
 * PURE AUTHORIZATION: is this link still describing this user's current address? The
 * `signed` middleware has already vouched for the URL's integrity and expiry, so what is
 * left is the `{hash}` — SHA-1 of the address at the time the mail was sent. Re-checking it
 * here is what makes a link stale the moment the address changes.
 *
 * Not behind `auth`, deliberately: registration logs the user out, so the signed params are
 * the only identity this request has.
 *
 * TWO FAILURE CODES, which is why `failedAuthorization` branches. A missing user is a 404 —
 * the link names a row that is gone, exactly what `findOrFail` would answer. A mismatched
 * hash is a 403 — the link is genuine but no longer describes this address, and saying so is
 * not a disclosure, since the caller already holds a signed URL naming that user.
 *
 * The lookup is memoised and exposed, so the controller works with the user this request
 * already resolved rather than fetching it a second time.
 */
class VerifyEmailRequest extends FormRequest
{
    /** Resolved once by `authorize()` and reused by `failedAuthorization()` and the controller. */
    private ?User $verifiable = null;

    /** Whether the lookup has run, so a genuinely absent user isn't looked up twice. */
    private bool $resolved = false;

    public function authorize(): bool
    {
        $user = $this->verifiable();

        return $user !== null
            && hash_equals(sha1($user->getEmailForVerification()), (string) $this->route('hash'));
    }

    /**
     * The user the link names, or null if there is no such row.
     *
     * Public because the controller needs the very same instance — see the class note.
     */
    public function verifiable(): ?User
    {
        if (! $this->resolved) {
            $this->verifiable = User::query()->find($this->route('id'));
            $this->resolved = true;
        }

        return $this->verifiable;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }

    /** 404 for a user that is gone, 403 for a link that no longer matches — see the class note. */
    protected function failedAuthorization(): never
    {
        abort(
            $this->verifiable() === null ? Response::HTTP_NOT_FOUND : Response::HTTP_FORBIDDEN,
            __('auth.verification_link_invalid'),
        );
    }
}
