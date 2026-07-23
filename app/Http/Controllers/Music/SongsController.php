<?php

namespace App\Http\Controllers\Music;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Music → Songs sub-section (`GET /music/songs`, route `music.songs`,
 * behind auth) — the full song listing, linked from the SongsWidget footer.
 * Stub for now: renders Music/Songs/SongsPage with no data yet.
 */
class SongsController extends Controller
{
    /** Render the Songs sub-section page (all songs). */
    public function __invoke(): Response
    {
        return Inertia::render('Music/Songs/SongsPage');
    }
}
