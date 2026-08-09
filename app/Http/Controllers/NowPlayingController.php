<?php

namespace App\Http\Controllers;

use App\Http\Requests\NowPlaying\ShowNowPlayingRequest;
use App\Services\Player\NowPlayingFacts;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Now Playing area (`GET /now-playing`, route `now-playing`, behind auth) — the page behind
 * the header entry that appears while the queue holds something. Single action, so it's invokable.
 *
 * IT IS ALMOST PROP-FREE, and that is not an oversight: what this page is about — the loaded
 * track, the two either side of it, and the queue below — lives in the browser, because playback
 * has to survive Inertia swapping pages under it (usePlayerQueue). Anything the server sent would
 * be a second, staler copy of state the page already holds.
 *
 * THE ONE EXCEPTION IS `facts`, and it earns its place by being everything on the page the queue
 * does not carry: the genre, the URLs that make artist / album / genre into links, the year and
 * the play counts. It is keyed off ids the page sends once it knows which three tracks it is
 * drawing, so an ordinary visit — which names none — costs no query at all, and a track change
 * costs one small lookup fetched with `only: ["facts"]` rather than a whole re-render. See
 * App\Services\Player\NowPlayingFacts for why none of it is simply stored on the queue entry.
 *
 * A CLOSURE RATHER THAN `Inertia::optional`, which is what this was first. An optional prop is
 * withheld unless the request carries the partial-reload HEADER, so it is absent on a plain visit
 * however many ids the query string names — and the empty case here is already free, since the
 * service returns before building a query. Unconditional also means the page never has to tell
 * "no facts" from "did not ask".
 */
class NowPlayingController extends Controller
{
    /** Render the page, and answer with the drawn tracks' facts when it asks for them. */
    public function __invoke(ShowNowPlayingRequest $request): Response
    {
        return Inertia::render('NowPlaying/NowPlayingPage', [
            'facts' => fn (): array => NowPlayingFacts::forTracks($request->trackIds(), $request->user()),
        ]);
    }
}
