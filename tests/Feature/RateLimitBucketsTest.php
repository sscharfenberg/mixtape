<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every rate-limited route must own its bucket.
 *
 * `throttle:max,decay` keys the limiter on the CALLER ALONE — the authenticated user's
 * id, or the IP for a guest (ThrottleRequests::resolveRequestSignature); the route is not
 * in the key at all. Without the optional third argument, which prefixes it, every
 * throttled route in this app therefore shares ONE counter per reader and only the
 * ceiling differs. The route with the smallest number is refused first, for traffic that
 * never touched it: a listener whose player has been writing queue states (60/min) would
 * be refused an album download (10/min) by their own listening.
 *
 * It is written as a walk over the route table rather than as a request that expects a
 * 429, because the failure it guards against is a NEW route being added without a prefix
 * — which no test of the existing ones would ever notice. The concrete behaviour is
 * pinned once, next to the route that found the problem
 * (AlbumDownloadTest::test_its_rate_limit_is_its_own_and_not_the_rest_of_the_apps).
 *
 * Named limiters (`throttle:login`, `throttle:auth-mail`, `throttle:two-factor` —
 * FortifyServiceProvider) are left alone: Laravel keys those by the limiter's name plus
 * whatever the callback returns, so they already have buckets of their own.
 */
class RateLimitBucketsTest extends TestCase
{
    public function test_no_route_shares_the_default_throttle_bucket(): void
    {
        $unprefixed = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                // `throttle:30,1` — a numeric limit with nothing naming its bucket.
                // `throttle:30,1,playlist-create` and `throttle:login` both pass.
                if (is_string($middleware) && preg_match('/^throttle:\d+,\d+$/', $middleware) === 1) {
                    $unprefixed[] = ($route->getName() ?? $route->uri()).' → '.$middleware;
                }
            }
        }

        $this->assertSame([], $unprefixed, implode("\n", [
            'These routes share one rate-limit bucket with every other unprefixed throttle,',
            'so the lowest ceiling in the app refuses them. Add a third argument naming the',
            "bucket — `throttle:30,1,playlist-create`:\n",
            ...$unprefixed,
        ]));
    }

    /**
     * Every throttled route, and the ceiling it carries.
     *
     * THE WALK ABOVE CANNOT SEE A THROTTLE THAT IS SIMPLY GONE. Delete
     * `->middleware('throttle:10,1,album-download')` and it stays green — the route just
     * becomes unlimited, which on a box reachable from the internet is the opposite of what
     * anybody wanted. A gigabyte-a-request download, a search running five ranked queries per
     * keystroke and the queue's write endpoint are exactly the places where an ABSENCE is
     * invisible.
     *
     * So this is an exact map rather than a rule: a new throttled route fails until it is
     * listed, a removed throttle fails, and a retuned ceiling fails until the number here is
     * changed to match. That last one is friction on purpose — the list doubles as the only
     * place every limit in the app can be read at once, and a ceiling worth changing is worth
     * seeing next to its neighbours while you change it.
     *
     * Named limiters (`login`, `auth-mail`, `two-factor`) appear by name: their numbers live
     * in FortifyServiceProvider, keyed by the limiter name plus whatever the callback returns,
     * so they have buckets of their own and nothing to prefix.
     */
    public function test_every_throttled_route_carries_the_ceiling_it_is_supposed_to(): void
    {
        $expected = [
            'audiobooks.bookmark' => 'throttle:120,1,audiobook-bookmark',
            'audiobooks.download' => 'throttle:10,1,audiobook-download',
            'dashboard.presets.default' => 'throttle:60,1,preset-default',
            'dashboard.presets.destroy' => 'throttle:30,1,preset-delete',
            'dashboard.presets.store' => 'throttle:30,1,preset-create',
            'dashboard.presets.update' => 'throttle:30,1,preset-update',
            'forgot.store' => 'throttle:auth-mail',
            'locale' => 'throttle:30,1,locale',
            'login.store' => 'throttle:login',
            'music.albums.download' => 'throttle:10,1,album-download',
            'music.songs.download' => 'throttle:30,1,song-download',
            'password.confirm' => 'throttle:6,1,password-confirm',
            'password.entropy' => 'throttle:60,1,password-entropy',
            'password.reset.store' => 'throttle:30,1,password-reset',
            'player.plays.store' => 'throttle:60,1,player-plays',
            'player.state.update' => 'throttle:60,1,player-state',
            'playlists.destroy' => 'throttle:10,1,playlist-delete',
            'playlists.export' => 'throttle:30,1,playlist-export',
            'playlists.order' => 'throttle:60,1,playlist-order',
            'playlists.store' => 'throttle:30,1,playlist-create',
            'playlists.tracks.destroy' => 'throttle:60,1,playlist-track-delete',
            'playlists.tracks.order' => 'throttle:60,1,playlist-track-order',
            'playlists.tracks.store' => 'throttle:30,1,playlist-tracks',
            'playlists.update' => 'throttle:30,1,playlist-update',
            'queue.tracks' => 'throttle:20,1,queue-tracks',
            'register.store' => 'throttle:30,1,register',
            'search' => 'throttle:60,1,search',
            'shares.cover' => 'throttle:60,1,share-cover',
            'shares.destroy' => 'throttle:30,1,share-revoke',
            'shares.renew' => 'throttle:20,1,share-renew',
            'shares.show' => 'throttle:60,1,share-page',
            'shares.store' => 'throttle:20,1,share-create',
            'shares.tracks.cover' => 'throttle:240,1,share-track-cover',
            'shares.tracks.stream' => 'throttle:120,1,share-stream',
            'two-factor.login.store' => 'throttle:two-factor',
            'user-password.update' => 'throttle:30,1,password-update',
            'user-profile-information.update' => 'throttle:30,1,profile-update',
            'user.delete' => 'throttle:6,1,account-delete',
            'verification.resend.store' => 'throttle:auth-mail',
            'verify-email' => 'throttle:6,1,verify-email',
        ];

        $actual = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
                    $actual[$route->getName() ?? $route->uri()] = $middleware;
                }
            }
        }

        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual, implode("\n", [
            'The set of throttled routes, or one of their ceilings, has changed.',
            'Left = what this test expects, right = what the route table says.',
            'A route that GAINED a throttle: add it here. A route that LOST one: put it back,',
            'or delete the entry deliberately. A retuned ceiling: update the number here too.',
        ]));
    }
}
