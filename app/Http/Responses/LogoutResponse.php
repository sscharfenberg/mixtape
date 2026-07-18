<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class LogoutResponse implements LogoutResponseContract
{
    /**
     * Response for a successful logout.
     *
     * Flashes a quick success toast, then redirects home ('/'). The session was
     * already invalidated by the controller, so this flash is written to the
     * fresh session and surfaces on the next request (see LoginResponse for how
     * the flash reaches the Vue toast).
     *
     * @param  mixed  $request
     * @return Response
     */
    public function toResponse($request)
    {
        $request->session()->flash('message', __('flash.logout'));
        $request->session()->flash('type', 'success');
        $request->session()->flash('duration', 3000);

        return redirect(Fortify::redirects('logout', '/'));
    }
}
