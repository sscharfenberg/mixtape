<?php

namespace App\Http\Controllers;

use App\Services\Library\LibraryStats;
use App\Services\Player\ListeningTotals;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The site root (`GET /`, route `home`) — the public welcome page shown to every visitor and
 * the jumping-off point to login. A single action, so it's an invokable controller (the repo
 * convention for one-shot pages).
 *
 * IT SENDS BOTH STATS CARDS, and to a caller with no session: this route sits outside the auth
 * group, so what it hands over is readable by anyone who reaches the domain. That is the
 * intent — the numbers are what the landing page is FOR, an answer to "what is this?" for
 * somebody who was sent a link — and it is safe precisely because they are totals. Not one
 * title, artist or file name leaves here; a visitor learns how much there is, never what.
 * Everything that names a row stays behind `auth` or behind a share.
 *
 * The two sets come from LibraryStats rather than from queries of their own, so the counts a
 * guest reads here and the counts the Music / Audiobooks pages show a member are the same
 * numbers derived the same way.
 */
class HomeController extends Controller
{
    /**
     * Render the welcome screen (Inertia page Guest/WelcomePage) with the collection's totals.
     *
     * Closures, like the browse pages: a partial reload for one card re-runs only that card's
     * aggregates, and a full load still evaluates both.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Guest/WelcomePage', [
            'musicStats' => fn (): array => LibraryStats::music(),
            'audiobookStats' => fn (): array => LibraryStats::audiobooks(),
            // HOW LONG THIS READER HAS SPENT IN EACH AREA, which is what orders the two buttons
            // a signed-in reader gets instead of one — the area they actually use, first. Zeroes
            // for a guest without a query, since the page draws them a sign-in button instead
            // and there is nothing to ask on a stranger's behalf.
            'listening' => fn (): array => ListeningTotals::forUser($request->user()),
        ]);
    }
}
