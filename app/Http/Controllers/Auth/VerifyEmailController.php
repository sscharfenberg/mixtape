<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailRequest;
use Illuminate\Auth\Events\Verified;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a user's e-mail address as verified from the signed link in the
 * verification e-mail (ported from cantrip.me).
 *
 * Not behind auth: the user is identified by the signed {id}/{hash} params, so
 * verification works even though registration logged them out. `signed`
 * middleware enforces the URL's integrity and expiry, and VerifyEmailRequest
 * re-checks the {hash} against the current e-mail. On success it flashes a toast
 * and sends the user to the login page to sign in.
 */
class VerifyEmailController extends Controller
{
    /**
     * Mark the address verified and send the user on to sign in.
     *
     * Both checks the link needs — that the user exists, and that the `{hash}` still matches
     * their current address — are VerifyEmailRequest's, which also decides which of 404 and
     * 403 each failure gets. `verifiable()` hands back the user it already resolved doing
     * that, so this never looks the row up twice.
     */
    public function __invoke(VerifyEmailRequest $request): Response
    {
        $user = $request->verifiable();

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        $request->session()->flash('message', __('flash.email.verified'));
        $request->session()->flash('type', 'success');

        return redirect()->route('login');
    }
}
