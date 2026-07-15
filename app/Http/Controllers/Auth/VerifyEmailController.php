<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a user's e-mail address as verified from the signed link in the
 * verification e-mail (ported from cantrip.me).
 *
 * Not behind auth: the user is identified by the signed {id}/{hash} params, so
 * verification works even though registration logged them out. `signed`
 * middleware enforces the URL's integrity and expiry; the {hash} is re-checked
 * here against the current e-mail. On success it flashes a toast and sends the
 * user to the login page to sign in.
 */
class VerifyEmailController extends Controller
{
    /**
     * @param  string  $id  The user's primary key, from the signed URL.
     * @param  string  $hash  SHA-1 of the user's e-mail, for integrity.
     */
    public function __invoke(Request $request, string $id, string $hash): Response
    {
        $user = User::findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403, 'Ungültiger Bestätigungslink.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        $request->session()->flash('message', 'Deine E-Mail-Adresse wurde erfolgreich bestätigt.');
        $request->session()->flash('type', 'success');

        return redirect()->route('login');
    }
}
