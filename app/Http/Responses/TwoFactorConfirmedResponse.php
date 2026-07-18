<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorConfirmedResponse as TwoFactorConfirmedResponseContract;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorConfirmedResponse implements TwoFactorConfirmedResponseContract
{
    /**
     * Response after the user verifies their authenticator (the final step of
     * 2FA enrollment — scan the QR code, enter the TOTP code).
     *
     * The confirm step is posted via the Inertia router (not fetch), so the
     * non-JSON branch runs: it flashes a success toast and returns back(), and
     * the Inertia reload flips the dashboard's `twoFactorEnabled` prop so the UI
     * swaps to the enabled state. Copy is resolved via the i18n lang files.
     *
     * @param  mixed  $request
     * @return Response
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 200);
        }

        $request->session()->flash('message', __('flash.two_factor.activated'));
        $request->session()->flash('type', 'success');

        return back();
    }
}
