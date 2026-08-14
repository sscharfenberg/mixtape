<?php

namespace App\Http\Controllers\Dev;

use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Track;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dev-only Web Audio probe (`GET /dev/audio-probe`, route `dev.audio-probe`, behind auth) —
 * one page, one question: DOES AUDIO SURVIVE THE SCREEN GOING OFF WHEN THE ELEMENT IS ROUTED
 * THROUGH AN AudioContext?
 *
 * WHY IT EXISTS. The Now Playing page wants an EQ visualiser, and the only way to read levels
 * off a playing element is `createMediaElementSource()`, which permanently redirects that
 * element's output into an audio graph — `disconnect()` gives silence, not the element back,
 * and a second `createMediaElementSource()` on the same element throws. If a browser suspends
 * the context while the page is hidden, the music stops in the worst possible way: the element
 * is still "playing", the timeline still advances, the lock screen still says playing, and
 * there is no sound. Screen-off playback on Android is a headline feature of this app, so that
 * risk has to be measured rather than reasoned about — browser
 * behaviour here differs by engine, by version, and between "tab backgrounded" and "screen
 * off".
 *
 * Not linked from anywhere and registered only outside production, like the icon gallery. It
 * takes `auth` all the same, because the thing it plays is behind auth: the stream route is,
 * so a page that rendered for a guest would offer a player that could only 302 to the login
 * form.
 *
 * IT IS THROWAWAY. Once the question is answered — and the answer is written down — this page
 * and its route can go. It deliberately shares nothing with the real player: its own <audio>,
 * its own context, no usePlayerAudio, so whatever it proves is a fact about the browser rather
 * than about this app's wiring.
 */
class AudioProbeController extends Controller
{
    /**
     * Render the probe over the longest music track in the library.
     *
     * THE LONGEST ONE, deliberately: the test is "lock the phone for two minutes", and a track
     * that ends while the screen is off answers a different question — `ended` fires, the
     * element stops legitimately, and the journal would read as a stall. Ordering by duration
     * costs nothing here and removes that whole class of false negative.
     *
     * Null when the library holds no music at all, which the page renders as a plain "nothing
     * to play" rather than a dead player.
     */
    public function __invoke(): Response
    {
        $track = Track::query()
            ->where('type', TrackType::Music)
            ->whereNotNull('duration')
            ->with('artist:id,name')
            ->orderByDesc('duration')
            ->first();

        return Inertia::render('Dev/AudioProbePage', [
            'track' => $track === null ? null : [
                'name' => $track->name,
                'artist' => $track->artist?->name,
                // Raw seconds, formatted on the page — the project's server-sends-raw rule
                // holds even on a dev page, since the formatter is already there.
                'duration' => $track->duration,
                'streamUrl' => route('music.songs.stream', $track, absolute: false),
            ],
        ]);
    }
}
