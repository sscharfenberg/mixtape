<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
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
        $request->session()->flash('message', 'Willkommen zurück, '.$request->user()->name.'!');
        $request->session()->flash('type', 'success');
        $request->session()->flash('duration', 3000);

        if ($request->wantsJson()) {
            return new JsonResponse([
                'two_factor' => false,
                'redirect' => redirect()->intended(Fortify::redirects('login'))->getTargetUrl(),
            ]);
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}
