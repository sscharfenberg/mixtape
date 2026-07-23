<?php

namespace App\Http\Controllers\Music;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Music → Albums sub-section (`GET /music/albums`, route `music.albums`,
 * behind auth) — the full album listing, linked from the AlbumsWidget footer.
 * Stub for now: renders Music/Albums/AlbumsPage with no data yet.
 */
class AlbumsController extends Controller
{
    /** Render the Albums sub-section page (all albums). */
    public function __invoke(): Response
    {
        return Inertia::render('Music/Albums/AlbumsPage');
    }
}
