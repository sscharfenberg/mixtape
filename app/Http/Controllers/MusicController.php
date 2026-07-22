<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The Music area (`GET /music`, route `music`, behind auth) — the browse view
 * for the music collection. Scaffold: renders the placeholder Music/MusicPage
 * pending the real browse UI. Single action, so it's invokable.
 */
class MusicController extends Controller
{
    /** Render the Music browse page. */
    public function __invoke(): Response
    {
        return Inertia::render('Music/MusicPage');
    }
}
