<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests as FrameworkThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app's `throttle` middleware: the framework's, plus a bucket of its own for
 * VALIDATE-ONLY traffic. Aliased in bootstrap/app.php, so every `throttle:` in the app —
 * numeric or named, ours or Fortify's — goes through this.
 *
 * WHY IT EXISTS. A Precognition form validates against THE ROUTE IT SUBMITS TO, with the
 * same verb: `PUT /playlists/{id}` carrying `Precognition: true` and
 * `Precognition-Validate-Only: description` is the same route and the same caller as the
 * save, and therefore the same bucket. So a reader tabbing through a two-field form spends
 * THREE of the route's allowance per save, and their own typing is what refuses their write.
 * It is the lesson of the third `throttle:` argument (RateLimitBucketsTest) one level down:
 * naming the route stops unrelated routes sharing a counter, but two kinds of traffic still
 * share the name.
 *
 * MEASURED, 2026-08-10: fifteen scripted saves through the playlist form — thirty
 * validate-on-blur requests and fifteen writes — hit a hard 429 on
 * `throttle:30,1,playlist-create` halfway through. The ceilings on `register` and
 * `password-reset` had already been inflated by hand for the same reason, and `auth-mail`
 * carries a branch that was written to fix it (see FortifyServiceProvider).
 *
 * IT KEYS ON `Precognition-Validate-Only`, NOT ON `Precognition`, and that difference is a
 * bypass. A request that merely CLAIMS precognition can still perform the write — measured
 * against the playlist update: `Precognition: true` alone answers 302 and stores the new
 * value, because the only thing standing between a precognitive request and the controller
 * is the FormRequest's own after-hook, and that hook fires only when the validate-only
 * header is present (Illuminate\Foundation\Precognition::afterValidationHook). Key the
 * separate bucket on the broader claim and any client could help itself to the larger
 * allowance AND write with it. On validate-only it cannot: the request is answered 204
 * before the action runs.
 *
 * AND IT ASKS WHETHER THE ROUTE ENFORCES ANY OF THAT, because that 204 also depends on
 * `HandlePrecognitiveRequests` having marked the request — on a route without it the headers
 * mean nothing and the write goes through. The claim is only believed where the route makes
 * it binding.
 */
class ThrottleRequests extends FrameworkThrottleRequests
{
    /**
     * How much validate-only traffic a route allows, as a multiple of its own ceiling.
     *
     * FIVE, because it is the ratio the owner reached for by hand the first time this bit
     * (`auth-mail`: thirty validations against six sends), and because the traffic says the
     * same thing — a form spends one validation per field and another per correction against
     * a single write, so the validation bucket has to sit well clear of the write's or it
     * becomes the thing that refuses the save, which is what separating them was for.
     */
    private const PRECOGNITION_MULTIPLIER = 5;

    /**
     * What keeps validate-only traffic out of the write's counter.
     *
     * Legible rather than hashed, deliberately: it lands between the route's prefix and the
     * caller's hash, so a counter found in a cache dump says which of the two kinds of
     * traffic filled it.
     */
    private const BUCKET = 'precognition|';

    /**
     * The caller's bucket — the framework's, moved aside for validate-only traffic.
     *
     * This is the whole mechanism for a numeric `throttle:max,decay,prefix`: the key is
     * `prefix . signature`, so a different signature is a different counter.
     *
     * @param  Request  $request
     */
    protected function resolveRequestSignature($request): string
    {
        return ($this->isValidateOnly($request) ? self::BUCKET : '').parent::resolveRequestSignature($request);
    }

    /**
     * The ceiling, raised for validate-only traffic — see {@see self::PRECOGNITION_MULTIPLIER}.
     *
     * Called only on the numeric path, which is why the named one is handled separately
     * below rather than here.
     *
     * @param  Request  $request
     * @param  int|string  $maxAttempts
     */
    protected function resolveMaxAttempts($request, $maxAttempts): int
    {
        $ceiling = parent::resolveMaxAttempts($request, $maxAttempts);

        return $this->isValidateOnly($request) ? $ceiling * self::PRECOGNITION_MULTIPLIER : $ceiling;
    }

    /**
     * A NAMED limiter (`throttle:auth-mail`) separated the same way, in the one place it can be.
     *
     * Its key does not come from `resolveRequestSignature` at all — the framework builds it
     * from the limiter's own `by()` (`md5($name.$limit->key)`), which is exactly why the
     * hand-rolled `isPrecognitive()` branch in FortifyServiceProvider never separated
     * anything: both of its arms passed the same `by()`, so both shared one counter and only
     * the ceiling differed.
     *
     * The callback is WRAPPED rather than the parent reimplemented, so `Unlimited`, a limiter
     * that returns a Response, several limits at once, and whatever that method grows next
     * all keep working.
     *
     * @param  Request  $request
     * @param  string  $limiterName
     */
    protected function handleRequestUsingNamedLimiter($request, Closure $next, $limiterName, Closure $limiter)
    {
        if (! $this->isValidateOnly($request)) {
            return parent::handleRequestUsingNamedLimiter($request, $next, $limiterName, $limiter);
        }

        return parent::handleRequestUsingNamedLimiter($request, $next, $limiterName, function ($request) use ($limiter) {
            $limits = $limiter($request);

            if ($limits instanceof Response || $limits instanceof Unlimited) {
                return $limits;
            }

            return Collection::wrap($limits)->map(fn ($limit) => $this->separate($limit))->all();
        });
    }

    /**
     * The same limit, in a bucket of its own and with the validate-only ceiling.
     *
     * A CLONE, because `Limit` is mutable and `by()` hands back the same instance: rewriting
     * the object the limiter returned would rewrite the limiter's own, and a limiter that
     * caches or reuses one would then leak the marker into real traffic.
     */
    private function separate(Limit $limit): Limit
    {
        if ($limit instanceof Unlimited) {
            return $limit;
        }

        $separated = clone $limit;
        $separated->key = self::BUCKET.$limit->key;
        $separated->maxAttempts = $limit->maxAttempts * self::PRECOGNITION_MULTIPLIER;

        return $separated;
    }

    /**
     * Whether this request is one that CANNOT write, and may therefore be counted apart.
     *
     * Both halves are load-bearing, and the class note has the measurements: the
     * validate-only header is what makes the FormRequest answer 204 before the action runs,
     * and the route's own middleware is what makes that hook fire at all.
     */
    private function isValidateOnly(Request $request): bool
    {
        return $request->isAttemptingPrecognition()
            && $request->hasHeader('Precognition-Validate-Only')
            && $this->routeEnforcesPrecognition($request);
    }

    /**
     * Whether the route will actually hold a precognitive request to its promise.
     *
     * It reads the ROUTE rather than trusting the header, because the header is the client's
     * word. `gatherMiddleware()` is what RateLimitBucketsTest walks for a related reason, and
     * the reason it is the right question here: it includes middleware a route inherited from
     * its GROUP, which is where both dashboard forms get their precognition.
     *
     * `Request::isPrecognitive()` would be the obvious check and is useless here: it reads an
     * attribute that `HandlePrecognitiveRequests` sets, and the throttle deliberately runs
     * BEFORE that middleware, so at this point it is always false.
     */
    private function routeEnforcesPrecognition(Request $request): bool
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && is_a(Str::before($middleware, ':'), HandlePrecognitiveRequests::class, true)) {
                return true;
            }
        }

        return false;
    }
}
