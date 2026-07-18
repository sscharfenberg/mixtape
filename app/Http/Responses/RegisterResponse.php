<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response after a successful registration (ported from cantrip.me).
 *
 * Fortify's RegisteredUserController auto-logs the new user in. With email
 * verification enabled the account is not usable yet (login is blocked until the
 * address is confirmed), so we undo that login and bounce to the guest landing
 * page with a "check your e-mail" toast; the user returns via the verification
 * link and then signs in. With verification off we keep the session and go
 * straight to the dashboard.
 */
class RegisterResponse implements RegisterResponseContract
{
    /**
     * @param  mixed  $request
     */
    public function toResponse($request): JsonResponse|Response
    {
        $request->session()->flash('message', __('flash.register.success'));
        $request->session()->flash('type', 'success');

        if (Features::enabled(Features::emailVerification())) {
            Auth::logout();

            return redirect()->route('home');
        }

        return redirect(config('fortify.home'));
    }
}
