<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The Audiobooks area (`GET /audiobooks`, route `audiobooks`, behind auth) —
 * the browse view for the audiobook collection. Scaffold: renders the
 * placeholder Audiobooks/AudiobooksPage pending the real browse UI. Single
 * action, so it's invokable.
 */
class AudiobooksController extends Controller
{
    /** Render the Audiobooks browse page. */
    public function __invoke(): Response
    {
        return Inertia::render('Audiobooks/AudiobooksPage');
    }
}
