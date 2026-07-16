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
     * → 'home' => '/dashboard'). Account settings (profile, password, account
     * deletion) are in place; playlists / listen history land later.
     */
    public function __invoke(): Response
    {
        return Inertia::render('User/DashboardPage');
    }
}
