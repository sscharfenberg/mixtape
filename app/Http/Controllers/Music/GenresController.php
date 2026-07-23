<?php

namespace App\Http\Controllers\Music;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Music → Genres sub-section (`GET /music/genres`, route `music.genres`,
 * behind auth) — the full genre listing, linked from the GenresWidget footer.
 * Stub for now: renders Music/Genres/GenresPage with no data yet.
 */
class GenresController extends Controller
{
    /** Render the Genres sub-section page (all genres). */
    public function __invoke(): Response
    {
        return Inertia::render('Music/Genres/GenresPage');
    }
}
