<?php

namespace App\Http\Controllers\Music;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Music → Artists sub-section (`GET /music/artists`, route `music.artists`,
 * behind auth) — the full artist listing, linked from the ArtistsWidget footer.
 * Stub for now: renders Music/Artists/ArtistsPage with no data yet.
 */
class ArtistsController extends Controller
{
    /** Render the Artists sub-section page (all artists). */
    public function __invoke(): Response
    {
        return Inertia::render('Music/Artists/ArtistsPage');
    }
}
