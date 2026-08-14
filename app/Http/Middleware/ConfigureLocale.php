<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Resolve and activate the request locale (ported from cantrip.me).
 *
 * Precedence, highest to lowest:
 *   1. the authenticated user's `users.locale` column (their saved choice),
 *   2. the guest's session `locale` (a prior switch, or the browser value
 *      remembered on first visit),
 *   3. the browser's Accept-Language header (first visit) — parsed, validated
 *      against the Locale enum, and written into the session so it sticks.
 *
 * Registered in bootstrap/app.php ahead of HandleInertiaRequests so the resolved
 * locale is already active when Inertia shares it (and `<html lang>` renders it).
 */
class ConfigureLocale
{
    /**
     * Pick the best supported locale from the browser's Accept-Language header.
     *
     * Splits the header into weighted tags, strips the region subtag (en-US → en),
     * sorts by q-factor, and returns the top tag if it is a supported locale —
     * otherwise falls back to the app default.
     */
    private function parseHttpLocale(Request $request): string
    {
        $header = $request->server('HTTP_ACCEPT_LANGUAGE');
        if (! $header) {
            return config('app.locale');
        }

        $locales = Collection::make(explode(',', $header))->map(function ($locale) {
            $parts = explode(';', $locale);
            $tag = explode('-', trim($parts[0]))[0];
            // Both halves default rather than assume: a fragment may carry no `;` at all, and
            // one that does need not carry a `=` after it. `de;x` and `en;q` are malformed but
            // perfectly sendable, and reading `[1]` of either raises "Undefined array key 1" —
            // an ErrorException, i.e. a 500. This branch runs for every visitor with no session
            // locale, so that is a 500 on a stranger's FIRST request, including `/` and the
            // guest `/s/{share}` space. An unreadable weight is treated as "no preference
            // stated", which is what the specification's own default of 1 means.
            $factor = (float) (explode('=', $parts[1] ?? '')[1] ?? 1.0);

            return ['locale' => $tag, 'factor' => $factor];
        })->sortByDesc('factor');

        $browserLocale = $locales->first()['locale'];

        return Locale::tryFrom($browserLocale) !== null
            ? $browserLocale
            : config('app.locale');
    }

    /**
     * Resolve the locale by precedence and activate it for this request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Auth::user();

        if ($user) { // authenticated: their saved preference wins
            app()->setLocale($user->locale?->value ?? config('app.locale'));
        } elseif ($sessionLocale = session('locale')) { // guest with a stored choice
            app()->setLocale($sessionLocale);
        } else { // first-time guest: sniff the browser and remember it
            $browserLocale = $this->parseHttpLocale($request);
            session(['locale' => $browserLocale]);
            app()->setLocale($browserLocale);
        }

        return $next($request);
    }
}
