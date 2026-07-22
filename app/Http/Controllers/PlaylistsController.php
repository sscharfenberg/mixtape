<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The Playlists area (`GET /playlists`, route `playlists`, behind auth) — the
 * user's saved playlists. Scaffold: renders the placeholder
 * Playlists/PlaylistsPage pending the real playlists UI. Single action, so
 * it's invokable.
 */
class PlaylistsController extends Controller
{
    /** Render the Playlists browse page. */
    public function __invoke(): Response
    {
        return Inertia::render('Playlists/PlaylistsPage');
    }
}
