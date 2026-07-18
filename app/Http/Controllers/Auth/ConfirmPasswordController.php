<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
     * that follows passes the middleware. A wrong password returns a 422 with
     * the error keyed on `password` so the inline field can show it.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($request->password, $request->user()->password)) {
            return response()->json([
                'errors' => ['password' => [__('auth.password')]],
            ], 422);
        }

        $request->session()->passwordConfirmed();

        return response()->json(['confirmed' => true]);
    }
}
