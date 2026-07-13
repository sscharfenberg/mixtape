<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    /**
     * Response for a successful login.
     *
     * Flashes a quick success toast, then redirects to the intended URL (or
     * config('fortify.home') = /dashboard). The flash reaches the Vue toast via
     * HandleInertiaRequests → ToastContainer; `duration` keeps it short (3000ms)
     * per the login/logout toast spec.
     *
     * @param  mixed  $request
     * @return Response
     */
    public function toResponse($request)
    {
        $request->session()->flash('message', 'Willkommen zurück, '.$request->user()->name.'!');
        $request->session()->flash('type', 'success');
        $request->session()->flash('duration', 3000);

        return redirect()->intended(Fortify::redirects('login'));
    }
}
