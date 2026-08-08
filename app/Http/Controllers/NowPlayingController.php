<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The Now Playing area (`GET /now-playing`, route `now-playing`, behind auth) — the
 * page behind the header entry that appears while the queue holds something.
 *
 * Scaffold: renders the placeholder NowPlaying/NowPlayingPage pending the real view.
 * Single action, so it's invokable.
 *
 * IT TAKES NO PROPS, and that is not an oversight of the scaffold: what this page is
 * about — the queue and the track playing — lives in the browser, because playback has
 * to survive Inertia swapping pages under it (see usePlayerQueue). Whatever this grows
 * into will read those composables rather than be handed a payload.
 */
class NowPlayingController extends Controller
{
    /** Render the Now Playing page. */
    public function __invoke(): Response
    {
        return Inertia::render('NowPlaying/NowPlayingPage');
    }
}
