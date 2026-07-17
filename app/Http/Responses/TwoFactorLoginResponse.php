<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    /**
     * Response for a successful two-factor challenge.
     *
     * The challenge is submitted via fetch() with Accept: application/json (see
     * useLogin.ts) so the code/recovery-code step can stay on the login page
     * rather than round-tripping through a Fortify redirect. For that JSON path
     * we hand the frontend the intended URL and it navigates via router.visit();
     * the non-JSON branch keeps the plain redirect for completeness.
     *
     * @param  mixed  $request
     * @return Response
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse([
                'redirect' => redirect()->intended(Fortify::redirects('login'))->getTargetUrl(),
            ]);
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}
