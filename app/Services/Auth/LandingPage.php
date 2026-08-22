<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\TrackType;
use App\Models\Track;

/**
 * WHERE A READER LANDS WHEN THEY SIGN IN.
 *
 * It used to be `/dashboard` — one config value, for everybody, forever — and that is a page
 * about the ACCOUNT: its password, its two-factor secret, its deletion. Nobody signs in to a
 * music collection to read their own settings. They sign in to listen, so they land where the
 * music is.
 *
 * WHICH AREA IS DECIDED BY WHAT THE LIBRARY ACTUALLY HOLDS, not by a preference nobody set: the
 * bigger of the two, because on any given instance one of them is the collection and the other
 * is a handful of files somebody tried out. An instance with neither — a fresh install, or one
 * whose media paths are unconfigured — gets the public landing page, which is the one page that
 * says what this is rather than showing an empty table.
 *
 * IT IS ONLY EVER A DEFAULT. Both callers hand it to `redirect()->intended()`, so a reader
 * bounced to the login form from a deep link still lands on the link they asked for; this
 * answers the case where there is nothing to return to.
 *
 * REGISTRATION AND PASSWORD RESET DELIBERATELY STILL GO TO THE DASHBOARD
 * (`config('fortify.home')`). Both arrive there having just done something TO THE ACCOUNT — a
 * new account that may want two-factor set up, a password just changed — so the settings page is
 * the honest destination for them in a way it is not for signing in.
 */
final class LandingPage
{
    /** The public landing page — what an instance with no library at all can honestly offer. */
    private const WELCOME = '/';

    /**
     * The path to send a reader who has just signed in to.
     *
     * ONE GROUPED QUERY rather than two counts, over `type` — the leading half of the
     * `(type, created_at)` index, so this is an index scan across two distinct values rather
     * than a table read. No cache: the numbers change only when the scanner runs, and a stale
     * one would send every reader of a freshly imported library to the wrong area, which is the
     * same failure mode the site menu's own gating refuses a cache for.
     *
     * A TIE GOES TO MUSIC, which decides the one case the rule above does not: it is the
     * larger area on every real instance, and an arbitrary answer that is always the same
     * answer beats one that depends on which row the database returned first.
     */
    public static function path(): string
    {
        $counts = Track::query()
            ->groupBy('type')
            ->selectRaw('type, count(*) as total')
            ->pluck('total', 'type');

        $music = (int) ($counts[TrackType::Music->value] ?? 0);
        $audiobooks = (int) ($counts[TrackType::Audiobook->value] ?? 0);

        if ($music === 0 && $audiobooks === 0) {
            return self::WELCOME;
        }

        return $audiobooks > $music ? '/audiobooks' : '/music';
    }
}
