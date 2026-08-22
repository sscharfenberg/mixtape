<?php

namespace App\Http\Responses;

use App\Services\Auth\LandingPage;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    /**
     * Response for a successful login (for a user WITHOUT 2FA — a 2FA user is
     * short-circuited earlier by RedirectsIfTwoFactorAuthenticatable, which
     * returns { two_factor: true } and never reaches here).
     *
     * The login form submits via fetch() with Accept: application/json (see
     * useLogin.ts) so Fortify can answer { two_factor: true } instead of
     * redirecting, keeping any 2FA challenge on the login page. For that JSON
     * path we return { two_factor: false, redirect } and the frontend navigates
     * itself via router.visit(); the non-JSON path keeps the plain redirect (and
     * is what the feature tests and any no-JS fallback exercise).
     *
     * WHERE IT LANDS IS NO LONGER ONE CONFIG VALUE. `LandingPage::path()` answers with the
     * area the library actually holds most of, or the public landing page for an instance
     * with no media at all — the reasoning is there. It is handed to `intended()`, so it is
     * only the fallback: a reader bounced here from a deep link still gets that link.
     *
     * The success toast is flashed before the wantsJson() branch either way:
     * the frontend's subsequent Inertia GET (router.visit) carries it into the
     * dashboard props, where ToastContainer renders it. `duration` keeps it short
     * (3000ms) per the login/logout toast spec.
     *
     * @param  mixed  $request
     * @return Response
     */
    public function toResponse($request)
    {
        $request->session()->flash('message', __('flash.login.welcome', ['name' => $request->user()->name]));
        $request->session()->flash('type', 'success');
        $request->session()->flash('duration', 3000);

        if ($request->wantsJson()) {
            return new JsonResponse([
                'two_factor' => false,
                'redirect' => redirect()->intended(LandingPage::path())->getTargetUrl(),
            ]);
        }

        return redirect()->intended(LandingPage::path());
    }
}
