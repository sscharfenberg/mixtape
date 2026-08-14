<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\CallableDispatcher;
use Illuminate\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use Illuminate\Routing\Contracts\ControllerDispatcher as ControllerDispatcherContract;
use Illuminate\Routing\ControllerDispatcher;

/**
 * Precognition for a route whose validation lives INSIDE its action.
 *
 * USE THIS ONLY FOR SUCH A ROUTE. Everywhere else — anything validating through a FormRequest —
 * use the framework's own HandlePrecognitiveRequests. Both choices fail SILENTLY when made the
 * wrong way round, which is why this docblock is long and why
 * tests/Feature/PrecognitionSideEffectsTest.php pins both directions on every route in the app
 * that speaks Precognition.
 *
 * WHAT IT DOES. The framework's middleware binds dispatchers (PrecognitionCallableDispatcher /
 * PrecognitionControllerDispatcher) whose `dispatch()` resolves the action's parameters and then
 * `abort(204)`s — so a FormRequest, being a parameter, validates, while the action never runs. That
 * is exactly right when the rules live in the request class. It is useless when they live in the
 * action: this app's Fortify actions take the Request and call
 * `precognitive(fn () => $this->request->validate([...]))` (App\Actions\Fortify\CreateNewUser and
 * the two Update* actions), because Fortify resolves those itself and hands them an array, so there
 * is no request to type-hint. Under the framework's dispatchers the abort comes FIRST and nothing
 * is ever validated.
 *
 * Measured on a route of that shape, sending a value the rule cannot accept:
 *
 *   framework middleware → 204 `Precognition-Success: true`   ← "valid", having checked nothing
 *   this middleware      → 422 {"name": ["…is invalid."]}
 *
 * So this restores the plain dispatchers, letting the action run far enough to validate. The
 * short-circuit then comes from the action itself: `precognitive()` aborts 204 on any precognitive
 * request, and `$request->validate()` (the precognition-aware macro) aborts 204 the moment a
 * validate-only field passes.
 *
 * AND THAT IS THE OBLIGATION IT PUTS ON THE ROUTE. Nothing else stops the action, so an action
 * reached through this middleware MUST short-circuit itself. Put this in front of one that does not
 * and a request merely CLAIMING precognition performs the real thing. On a route that validates
 * through a FormRequest and wears this anyway, `Precognition: true` with no
 * `Precognition-Validate-Only` header will create a playlist, write metadata, send password-reset
 * and verification mail, or reset a password (consuming its token and logging the session in).
 * A FormRequest route wants the FRAMEWORK's middleware, which short-circuits for it correctly.
 */
class HandleControllerPrecognitiveRequest extends HandlePrecognitiveRequests
{
    /**
     * Let the action RUN for a precognitive request, by putting the plain dispatchers back.
     *
     * The parent call is what marks the request precognitive (`$request->attributes`), and that
     * marking is the whole reason the action's own `precognitive()` and `$request->validate()` do
     * anything at all — so it has to happen, and only the dispatchers it also binds are undone.
     * The parent restores whatever was bound before, after the response, so nothing leaks.
     *
     * @param  Request  $request
     */
    protected function prepareForPrecognition($request): void
    {
        parent::prepareForPrecognition($request);

        $this->container->bind(CallableDispatcherContract::class, fn ($app) => new CallableDispatcher($app));
        $this->container->bind(ControllerDispatcherContract::class, fn ($app) => new ControllerDispatcher($app));
    }
}
