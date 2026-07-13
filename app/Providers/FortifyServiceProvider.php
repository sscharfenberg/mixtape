<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Do not let Fortify auto-register its default route set. Every route
        // (including the GET /login view) is declared explicitly in routes/web.php
        // so the app only exposes the endpoints it actually uses, and the wiring
        // stays greppable end-to-end. Login/logout rely on Fortify's default
        // response classes, which redirect to config('fortify.home') ('/dashboard').
        Fortify::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureLoginPipeline();
        $this->configureRateLimiting();
    }

    /**
     * Configure the login authentication pipeline.
     *
     * Kept feature-aware even though two-factor auth is currently disabled (see
     * config/fortify.php): the 2FA redirect drops out via the null filter until
     * the feature is switched on, so enabling it later needs no change here.
     * Throttling is handled by the route-level `throttle:login` middleware, so
     * EnsureLoginIsNotThrottled is skipped while config('fortify.limiters.login')
     * is set.
     */
    private function configureLoginPipeline(): void
    {
        Fortify::authenticateThrough(function (Request $request) {
            return array_filter([
                config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
                Features::enabled(Features::twoFactorAuthentication()) ? RedirectsIfTwoFactorAuthenticatable::class : null,
                AttemptToAuthenticate::class,
                PrepareAuthenticatedSession::class,
            ]);
        });
    }

    /**
     * Configure the login / two-factor rate limiters.
     *
     * Both are registered up front so the `two-factor` limiter is already in
     * place for when two-factor auth is enabled; only `login` is exercised today.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
