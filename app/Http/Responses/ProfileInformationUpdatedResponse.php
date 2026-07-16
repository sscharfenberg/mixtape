<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse as ProfileInformationUpdatedResponseContract;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response after a successful profile update (ported from cantrip.me).
 *
 * Changing the email address revokes verification (App\Actions\Fortify\
 * UpdateUserProfileInformation), and the dashboard route is guarded by the
 * `verified` middleware. Fortify's default response just redirects back to the
 * dashboard regardless, which would immediately hit that middleware — and
 * since this app has no route named `verification.notice` (Laravel's default
 * redirect target), it would throw a RouteNotFoundException instead of
 * redirecting. So: log the user out and bounce to the guest landing page with
 * a toast, same shape as RegisterResponse — they sign back in once they've
 * confirmed the new address. Keeping the same email just flashes success and
 * stays on the dashboard.
 */
class ProfileInformationUpdatedResponse implements ProfileInformationUpdatedResponseContract
{
    /**
     * @param  mixed  $request
     */
    public function toResponse($request): JsonResponse|Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 200);
        }

        if (Features::enabled(Features::emailVerification()) && is_null($request->user()->email_verified_at)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $request->session()->flash('message', 'Dein Profil wurde aktualisiert. Bitte bestätige deine neue E-Mail-Adresse — wir haben dir einen Link geschickt.');
            $request->session()->flash('type', 'success');

            return redirect()->route('home');
        }

        $request->session()->flash('message', 'Dein Profil wurde aktualisiert.');
        $request->session()->flash('type', 'success');

        return back();
    }
}
