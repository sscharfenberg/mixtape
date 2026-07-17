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
        // Render exceptions (notably validation errors) as JSON for api/* paths,
        // for Inertia Precognition requests, and for any request that explicitly
        // asks for JSON via Accept: application/json. Precognition needs the 422
        // JSON body to drive live field validation; the fetch()-based flows (the
        // 2FA login challenge and the JSON login handshake in useLogin.ts) need a
        // 422 so they can surface errors inline instead of following a redirect.
        // Ordinary Inertia form posts send Accept: text/html (wantsJson() is
        // false), so they still come back as a redirect-with-session-errors, which
        // is what Inertia expects.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->isAttemptingPrecognition()
                || $request->wantsJson(),
        );
    })->create();
