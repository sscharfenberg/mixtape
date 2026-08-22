<?php

namespace App\Http\Responses;

use App\Services\Auth\LandingPage;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
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
     * The default behind `intended()` is {@see LandingPage}'s, the same one the password-only
     * login uses — a challenge answered is still a sign-in, and the two landing in different
     * places would be a difference nobody chose.
     *
     * @param  mixed  $request
     * @return Response
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse([
                'redirect' => redirect()->intended(LandingPage::path())->getTargetUrl(),
            ]);
        }

        return redirect()->intended(LandingPage::path());
    }
}
