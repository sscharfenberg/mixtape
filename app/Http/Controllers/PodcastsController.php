<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The Podcasts area (`GET /podcasts`, route `podcasts`, behind auth) — the
 * browse view for podcast shows. Scaffold: renders the placeholder
 * Podcasts/PodcastsPage pending the real browse UI. Single action, so it's
 * invokable.
 */
class PodcastsController extends Controller
{
    /** Render the Podcasts browse page. */
    public function __invoke(): Response
    {
        return Inertia::render('Podcasts/PodcastsPage');
    }
}
