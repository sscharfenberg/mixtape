<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class, // handle inertia requests
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render exceptions (notably validation errors) as JSON for api/* paths
        // and for Inertia Precognition requests. Precognition needs the 422 JSON
        // body to drive the register form's live field validation; ordinary
        // Inertia form posts stay non-JSON so validation errors come back as a
        // redirect-with-session-errors, which is what Inertia expects.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->isAttemptingPrecognition(),
        );
    })->create();
