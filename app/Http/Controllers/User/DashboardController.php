<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the authenticated user's dashboard.
     *
     * The landing page after login (Fortify redirects here — config/fortify.php
     * → 'home' => '/dashboard'). Stubbed for now; the real dashboard (playlists,
     * listen history, account settings — mirroring cantrip.me's) lands later.
     */
    public function __invoke(): Response
    {
        return Inertia::render('User/DashboardPage');
    }
}
