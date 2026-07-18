<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse as FailedTwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class FailedTwoFactorLoginResponse implements FailedTwoFactorLoginResponseContract
{
    /**
     * Response for a failed two-factor challenge.
     *
     * Surfaces the error on whichever field was submitted — `recovery_code`
     * when the user tried a recovery code, `code` otherwise — so the login
     * page can show it inline against the right input. The JSON path (the
     * fetch()-based challenge in useLogin.ts) gets a 422 validation error;
     * the non-JSON path redirects back to /login with the same error.
     *
     * Messages are resolved via the i18n lang files (auth.*).
     *
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        [$key, $message] = $request->filled('recovery_code')
            ? ['recovery_code', __('auth.two_factor_recovery_code_invalid')]
            : ['code', __('auth.two_factor_code_invalid')];

        if ($request->wantsJson()) {
            throw ValidationException::withMessages([
                $key => [$message],
            ]);
        }

        return redirect()->route('login')->withErrors([$key => $message]);
    }
}
