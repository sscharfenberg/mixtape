<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\CallableDispatcher;
use Illuminate\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use Illuminate\Routing\Contracts\ControllerDispatcher as ControllerDispatcherContract;
use Illuminate\Routing\ControllerDispatcher;

/**
 * Precognition middleware for controller-action routes (ported from cantrip.me).
 *
 * Laravel's base HandlePrecognitiveRequests drives Precognition for the register
 * form's live field validation. On a controller route it must rebind the
 * callable / controller dispatchers so resolving the action for a precognitive
 * request doesn't execute it — the validation runs, the real handler does not.
 */
class HandleControllerPrecognitiveRequest extends HandlePrecognitiveRequests
{
    /**
     * Prepare to handle a precognitive request.
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
