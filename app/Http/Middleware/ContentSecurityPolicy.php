<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Sends the site's Content-Security-Policy on every response, with a fresh script nonce per request.
 *
 * Everything the app loads comes from its own origin: Vite's bundles, the self-hosted fonts, the
 * covers, the audio (a native `<audio>` pointed at a same-origin stream route, docs/player.md) and
 * `fetch` to its own routes. So the policy allows `'self'` and little else:
 *
 * - `script-src` adds the request's nonce. Vite's tags carry it (`Vite::useCspNonce()`), and so does
 *   the one inline script, the pre-paint theme snippet in app.blade.php (`Vite::cspNonce()`). The
 *   nonce is what lets that snippet run without `'unsafe-inline'`, which would let an injected
 *   script run as well. Inertia's page data is a `type="application/json"` block and never executes.
 * - `style-src` allows inline styles: Vue writes `style` attributes and the progress bar a `<style>`
 *   element at runtime. A nonce in this directive would switch `'unsafe-inline'` off, so it carries
 *   none.
 * - `media-src 'self'` is the audio. `default-src` would cover it; it is spelled out because it is
 *   the directive a player library wrapping audio in a MediaSource would need widened (`blob:`), and
 *   this player does not.
 * - `frame-ancestors 'self'` matches nginx's `X-Frame-Options`.
 * - `upgrade-insecure-requests` only on an enforced policy over HTTPS. Browsers ignore it in a
 *   report-only header, with a console warning; over plain HTTP — the end-to-end server — it would
 *   rewrite the page's own requests to an `https://` nobody answers.
 *
 * Report-only until `mixtape.csp.enforce` is set: violations then show in the browser console without
 * breaking anything, which is how a policy change is tried on real traffic first.
 *
 * Skipped while Vite's dev server runs (`public/hot`), whose scripts and websocket come from another
 * origin, and for the exception page in debug mode, which renders with inline scripts. The prerendered
 * maintenance page never reaches the app, so it has no policy. Registered as global middleware, so a
 * 404 that never reaches the web group carries the policy too.
 */
class ContentSecurityPolicy
{
    /**
     * Mint the request's nonce before the response renders, so every tag and the inline theme script
     * can carry it, then attach the policy in the header the configuration chooses.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Vite::isRunningHot()) {
            return $next($request);
        }

        $nonce = Vite::useCspNonce();
        $response = $next($request);

        if ($this->isDebugExceptionPage($response)) {
            return $response;
        }

        $enforce = (bool) config('mixtape.csp.enforce');
        $response->headers->set(
            $enforce ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only',
            self::policy($nonce, upgradeInsecureRequests: $enforce && $request->isSecure()),
        );

        return $response;
    }

    /**
     * Laravel renders its debug page for an exception that is not an HTTP exception while `app.debug`
     * is on, and that page runs inline scripts the policy would block; a 404 or 403 gets the error view
     * either way, and so the policy.
     */
    private function isDebugExceptionPage(Response $response): bool
    {
        return config('app.debug')
            && isset($response->exception)
            && ! $response->exception instanceof HttpExceptionInterface;
    }

    /**
     * The policy, with the request's script nonce. Static so the tests can compare a response's header
     * with exactly what this class means to send, rather than with a copy that drifts.
     */
    public static function policy(string $nonce, bool $upgradeInsecureRequests = false): string
    {
        return implode('; ', array_filter([
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self'",
            "font-src 'self'",
            "connect-src 'self'",
            "media-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            $upgradeInsecureRequests ? 'upgrade-insecure-requests' : null,
        ]));
    }
}
