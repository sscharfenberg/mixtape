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
}
