<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\EnsureEmailIsVerified;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\FailedTwoFactorLoginResponse;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\LogoutResponse;
use App\Http\Responses\PasswordUpdateResponse;
use App\Http\Responses\ProfileInformationUpdatedResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorConfirmedResponse;
use App\Http\Responses\TwoFactorDisabledResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse as FailedTwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse as ProfileInformationUpdatedResponseContract;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorConfirmedResponse as TwoFactorConfirmedResponseContract;
use Laravel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
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
        // stays greppable end-to-end.
        Fortify::ignoreRoutes();

        // Custom login/logout responses flash a quick toast message, then
        // redirect (login → config('fortify.home') = /dashboard, logout → '/').
        // Bound here — the app provider registers after Fortify's, so these
        // override Fortify's default responses. See app/Http/Responses.
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);

        // With email verification on, registration must NOT drop the user on the
        // dashboard (the account can't be used yet). This response logs the
        // just-created user back out and sends them to the landing page with a
        // "check your email" toast. See App\Http\Responses\RegisterResponse.
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);

        // Fortify's default password-update response only flashes a plain
        // `status` session key (no message text) — override it so the
        // dashboard's password form gets the same toast as login/logout.
        $this->app->singleton(PasswordUpdateResponseContract::class, PasswordUpdateResponse::class);

        // Fortify's default just redirects back to the dashboard regardless of
        // outcome — but changing the email revokes verification, and the
        // dashboard route is `verified`-guarded with no `verification.notice`
        // route to redirect to (this app uses its own route names), so that
        // would throw. See App\Http\Responses\ProfileInformationUpdatedResponse.
        $this->app->singleton(ProfileInformationUpdatedResponseContract::class, ProfileInformationUpdatedResponse::class);

        // Two-factor auth responses (config/fortify.php → twoFactorAuthentication).
        // LoginResponse (above) already handles the JSON login handshake that the
        // challenge flow rides on. These cover the challenge outcome and the
        // enroll/disable toasts; enable itself uses Fortify's default response.
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->singleton(FailedTwoFactorLoginResponseContract::class, FailedTwoFactorLoginResponse::class);
        $this->app->singleton(TwoFactorConfirmedResponseContract::class, TwoFactorConfirmedResponse::class);
        $this->app->singleton(TwoFactorDisabledResponseContract::class, TwoFactorDisabledResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fortify's RegisteredUserController resolves this to build a new user on
        // POST /register; our action gates creation on a valid one-time invite
        // (App\Models\Invite). Registration is enabled in config/fortify.php.
        Fortify::createUsersUsing(CreateNewUser::class);

        // Backs the dashboard's profile/password forms (App\Http\Controllers\Dashboard\
        // DashboardController). Enabled via Features::updateProfileInformation()/
        // updatePasswords() in config/fortify.php.
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        $this->configureEmailVerification();
        $this->configureLoginPipeline();
        $this->configureRateLimiting();
    }

    /**
     * Point Laravel's email-verification link at our named `verify-email` route.
     *
     * Only wired when the feature is enabled. The URL is the standard signed,
     * time-limited link (default 60 min — config/auth.php → verification.expire),
     * carrying the user id and a sha1 of their email as {id}/{hash}, which
     * VerifyEmailController re-checks.
     */
    private function configureEmailVerification(): void
    {
        if (! Features::enabled(Features::emailVerification())) {
            return;
        }

        VerifyEmail::createUrlUsing(function ($notifiable) {
            return URL::temporarySignedRoute(
                'verify-email',
                Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
                ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())]
            );
        });
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
                Features::enabled(Features::emailVerification()) ? EnsureEmailIsVerified::class : null,
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
