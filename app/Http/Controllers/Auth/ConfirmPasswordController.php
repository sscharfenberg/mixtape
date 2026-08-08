<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmPasswordRequest;
use Illuminate\Http\JsonResponse;

class ConfirmPasswordController extends Controller
{
    /**
     * Validate the user's password and mark it confirmed for the session.
     *
     * The 2FA management routes are behind Fortify's `password.confirm`
     * middleware, which — for a JSON/fetch request — aborts with 423 until the
     * password has been freshly confirmed. The 2FA composable (useTwoFactorAuth)
     * posts here first (fetch, JSON) to set `auth.password_confirmed_at` via
     * $request->session()->passwordConfirmed(), so the real management request
     * that follows passes the middleware. A wrong password is ConfirmPasswordRequest's
     * business, and answers 422 with the error keyed on `password` so the inline field can
     * show it.
     */
    public function store(ConfirmPasswordRequest $request): JsonResponse
    {
        $request->session()->passwordConfirmed();

        return response()->json(['confirmed' => true]);
    }
}
