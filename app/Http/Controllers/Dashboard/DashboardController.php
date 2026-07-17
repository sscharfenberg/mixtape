<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class DashboardController extends Controller
{
    /**
     * Display the authenticated user's dashboard.
     *
     * The landing page after login (Fortify redirects here — config/fortify.php
     * → 'home' => '/dashboard'). Account settings (profile, password, two-factor
     * auth, account deletion) are in place; playlists / listen history land later.
     *
     * The two-factor section (Dashboard/TwoFactor) is driven by three props:
     * whether the user currently has 2FA enabled, and — read from the feature's
     * options — whether enrollment needs a TOTP confirmation step and whether
     * management actions need a fresh password confirmation.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard/DashboardPage', [
            'twoFactorEnabled' => $request->user()->hasEnabledTwoFactorAuthentication(),
            'requiresConfirmation' => Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'),
            'requiresPasswordConfirmation' => Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
        ]);
    }
}
