<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    /**
     * Display the login page.
     *
     * Passes the session status (e.g. a "password reset successful" notice, once
     * that flow exists) to the Inertia view so it can be surfaced to the user.
     */
    public function loginView(Request $request): Response
    {
        return Inertia::render('Auth/LoginPage', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Display the registration page — but only for a valid invite.
     *
     * Onboarding is invite-only: the `code` query parameter (from the link minted
     * by `php artisan app:invite`) must match an outstanding, unexpired invite. A
     * missing / unknown / expired / already-spent code never reaches the form; it
     * bounces to the login page with an error toast. This is a friendly guard
     * only — POST /register re-validates and consumes the invite authoritatively
     * (App\Actions\Fortify\CreateNewUser), so a bypassed check changes nothing.
     * The valid code is handed to the page so it can be posted back with the form.
     */
    public function registerView(Request $request): Response|RedirectResponse
    {
        $code = $request->query('code');

        $valid = is_string($code) && $code !== '' && Invite::query()
            ->where('token', Invite::hashCode($code))
            ->where('valid_until', '>', now())
            ->exists();

        if (! $valid) {
            return redirect()->route('login')
                ->with('message', __('rules.invite_invalid'))
                ->with('type', 'error')
                ->with('duration', 5000);
        }

        return Inertia::render('Auth/RegisterPage', [
            'code' => $code,
        ]);
    }
}
