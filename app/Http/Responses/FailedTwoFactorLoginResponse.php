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
     * German messages are inlined (this app has no i18n layer).
     *
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        [$key, $message] = $request->filled('recovery_code')
            ? ['recovery_code', 'Der angegebene Zwei-Faktor-Wiederherstellungscode ist ungültig.']
            : ['code', 'Der angegebene Zwei-Faktor-Authentifizierungscode ist ungültig.'];

        if ($request->wantsJson()) {
            throw ValidationException::withMessages([
                $key => [$message],
            ]);
        }

        return redirect()->route('login')->withErrors([$key => $message]);
    }
}
