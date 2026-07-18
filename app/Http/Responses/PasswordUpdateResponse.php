<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
use Symfony\Component\HttpFoundation\Response;

class PasswordUpdateResponse implements PasswordUpdateResponseContract
{
    /**
     * Response for a successful password change.
     *
     * Flashes a quick success toast, then redirects back to the dashboard.
     * Overrides Fortify's default (a plain `status` session flash with no
     * message text) so the dashboard's password form gets the same toast
     * feedback as login/logout (see LoginResponse).
     *
     * @param  mixed  $request
     * @return Response
     */
    public function toResponse($request)
    {
        $request->session()->flash('message', __('flash.password.updated'));
        $request->session()->flash('type', 'success');
        $request->session()->flash('duration', 3000);

        return back();
    }
}
