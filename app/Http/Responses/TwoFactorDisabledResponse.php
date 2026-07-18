<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorDisabledResponse implements TwoFactorDisabledResponseContract
{
    /**
     * Response after two-factor authentication is disabled.
     *
     * Disable is posted via the Inertia router (not fetch), so the non-JSON
     * branch runs: it flashes a success toast and returns back(), and the
     * Inertia reload flips the dashboard's `twoFactorEnabled` prop back to the
     * disabled state. Copy is resolved via the i18n lang files.
     *
     * @param  mixed  $request
     * @return Response
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 200);
        }

        $request->session()->flash('message', __('flash.two_factor.deactivated'));
        $request->session()->flash('type', 'success');

        return back();
    }
}
