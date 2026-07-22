<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The site root (`GET /`, route `home`) — renders the public welcome page shown
 * to every visitor and the jumping-off point to login / registration. A single
 * action, so it's an invokable controller (the repo convention for one-shot pages).
 */
class HomeController extends Controller
{
    /** Render the welcome screen (Inertia page Guest/WelcomePage); no props yet. */
    public function __invoke(): Response
    {
        return Inertia::render('Guest/WelcomePage');
    }
}
