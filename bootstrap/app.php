<?php

use App\Http\Middleware\ConfigureLocale;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ThrottleRequests;
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
        // Every `throttle:` in the app — ours and Fortify's, numeric and named — resolves
        // through the app's own subclass, which keeps a form's validate-on-blur traffic in a
        // bucket of its own instead of spending the write's allowance. Aliased here rather
        // than named per route, because a route that forgets it is a route where a reader's
        // typing refuses their own save. See the class for the measurements.
        $middleware->alias(['throttle' => ThrottleRequests::class]);

        $middleware->web(append: [
            // ConfigureLocale must precede HandleInertiaRequests so the resolved
            // locale is active when Inertia shares it (and <html lang> renders).
            ConfigureLocale::class, // resolve + activate the request locale
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
