<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ConfirmPasswordController;
use App\Http\Controllers\Auth\EntropyController;
use App\Http\Controllers\Auth\ForgotController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\ResendVerificationController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Dashboard\DeleteAccountController;
use App\Http\Middleware\HandleControllerPrecognitiveRequest;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\PasswordController;
use Laravel\Fortify\Http\Controllers\ProfileInformationController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorSecretKeyController;

/******************************************************************************
 * Authentication routes
 *
 * FortifyServiceProvider calls Fortify::ignoreRoutes(), so the auth endpoints
 * are declared here explicitly rather than auto-registered. Login/logout,
 * invite-only registration, email verification, password reset and optional
 * two-factor auth are all declared below. Login/logout/registration use Fortify's own
 * controllers, which defer to the (optionally overridden) response classes;
 * password reset uses app-owned controllers (ForgotController /
 * NewPasswordController) instead of Fortify's, so the single "forgot
 * password / username" page can dispatch either recovery.
 *
 * Every numeric `throttle:` below carries a third argument naming its bucket, for the
 * reason spelled out at the top of web.php: without one the limiter keys on the CALLER
 * alone, so all of these would share a single counter — per user where there is one, and
 * per IP for the guest routes, which is most of this file. `throttle:login`,
 * `throttle:auth-mail` and `throttle:two-factor` are named limiters
 * (FortifyServiceProvider) and already have keys of their own.
 *****************************************************************************/

// Guest-only: the login / register pages and their POST handlers.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'loginView'])
        ->name('login');

    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');

    // Registration is invite-only. The GET view rejects a missing / expired /
    // already-spent invite code up front (AuthController::registerView), and
    // CreateNewUser re-checks and consumes the invite on POST. Gated by the
    // Fortify registration feature so the whole flow toggles in one place.
    if (Features::enabled(Features::registration())) {
        Route::get('/register', [AuthController::class, 'registerView'])
            ->name('register');

        // HandleControllerPrecognitiveRequest — the APP's — drives the register form's
        // live field validation, and has to be this one rather than the framework's:
        // these rules live inside App\Actions\Fortify\CreateNewUser (Fortify hands its
        // actions an array, so there is no FormRequest to resolve), and the framework's
        // dispatchers abort 204 before the action runs. Measured: they answer "valid" to
        // a value the rule cannot accept. See that middleware's docblock for both halves.
        //
        // The throttle is generous because the invite requirement is the real abuse gate.
        //
        // It does NOT have to hold room for validate-on-blur traffic:
        // App\Http\Middleware\ThrottleRequests counts that apart (30 registrations,
        // 150 validations), so this number is what a registration is worth on its own.
        Route::post('/register', [RegisteredUserController::class, 'store'])
            ->middleware(['throttle:30,1,register', HandleControllerPrecognitiveRequest::class])
            ->name('register.store');
    }

    // "Forgot password / username": one page, one `type` field toggles which
    // recovery ForgotController::store dispatches. `password.reset` is the
    // name Laravel's default ResetPassword notification builds its URL
    // against (App\Models\User::sendPasswordResetNotification), so it must
    // keep that exact name even though the controller is app-owned.
    if (Features::enabled(Features::resetPasswords())) {
        Route::get('/forgot', [ForgotController::class, 'show'])
            ->name('forgot');

        // `auth-mail`: 6 sends a minute per IP, and that is the gate on somebody
        // else's inbox — not to be loosened. The form's validate-on-blur traffic
        // no longer touches it (App\Http\Middleware\ThrottleRequests keeps it in
        // its own counter, 30 a minute); the limiter in FortifyServiceProvider
        // records the hand-rolled attempt at that and why it did not work.
        // The FRAMEWORK's precognition middleware, because ForgotRequest is a FormRequest:
        // resolving it validates, and the action — which SENDS MAIL — never runs. With the app's
        // middleware instead, a request merely CLAIMING precognition sends a real password-reset
        // email.
        Route::post('/forgot', [ForgotController::class, 'store'])
            ->middleware(['throttle:auth-mail', HandlePrecognitiveRequests::class])
            ->name('forgot.store');

        Route::get('/reset-password', [NewPasswordController::class, 'show'])
            ->name('password.reset');

        // Generous throttle, and safe to be: unlike `forgot.store` above, this
        // route sends no mail — it consumes a single-use, expiring token, which
        // is the real gate.
        //
        // It was raised from `6,1` because three fields validating on blur meant
        // one honest reset cost 4+ requests against this budget and a single
        // correction (mismatched confirmation, rejected weak password) answered
        // 429. That traffic has a counter of its own
        // (App\Http\Middleware\ThrottleRequests), so the 30 does not hold room for it.
        // The FRAMEWORK's, as for `forgot.store` above — NewPasswordRequest is a FormRequest.
        // This is the route where getting that wrong costs the most: with the app's middleware,
        // a request claiming precognition RESETS THE PASSWORD, consumes the single-use token and
        // logs the session in.
        Route::post('/reset-password', [NewPasswordController::class, 'store'])
            ->middleware(['throttle:30,1,password-reset', HandlePrecognitiveRequests::class])
            ->name('password.reset.store');
    }

    // "Resend verification email": for a user stuck with an expired signed
    // link, who can't log in to trigger a fresh one (login is blocked until
    // verified). Matches name + email before resending, same anti-enumeration
    // shape as the "forgot" flow above.
    if (Features::enabled(Features::emailVerification())) {
        Route::get('/resend-verification', [ResendVerificationController::class, 'show'])
            ->name('verification.resend');

        // Same limiter and the same middleware choice as `forgot.store` above — a FormRequest,
        // and an action that sends mail, so the framework's is the one that keeps a precognitive
        // request from sending it.
        Route::post('/resend-verification', [ResendVerificationController::class, 'store'])
            ->middleware(['throttle:auth-mail', HandlePrecognitiveRequests::class])
            ->name('verification.resend.store');
    }
});

// Authenticated-only: end the session and return to the home page.
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// Email verification: the signed link from the verification email. Not behind
// guest/auth — the signed {id}/{hash} identify the user (registration logs them
// out), and `signed` enforces integrity + expiry. Gated by the feature flag.
if (Features::enabled(Features::emailVerification())) {
    Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1,verify-email'])
        ->name('verify-email');
}

// Password strength (zxcvbn) score for the live registration meter. A stateless
// utility (returns a 0–4 score; changes nothing), so it's a plain web route, not
// a data API. Throttled to blunt abuse of the zxcvbn call — and bounded in length by
// ScorePasswordRequest, without which the throttle is the only thing between an
// unauthenticated caller and super-linear work on a megabyte of text.
Route::post('/password/entropy', EntropyController::class)
    ->middleware('throttle:60,1,password-entropy')
    ->name('password.entropy');

/******************************************************************************
 * Dashboard account management (App\Http\Controllers\Dashboard\DashboardController)
 *
 * Profile/password updates go through Fortify's own controllers, which defer
 * to App\Actions\Fortify\UpdateUserProfileInformation / UpdateUserPassword
 * (wired in FortifyServiceProvider); account deletion is app-owned since
 * Fortify has no built-in action for it.
 *
 * BOTH FORMS VALIDATE INSIDE THEIR ACTION (App\Actions\Fortify\UpdateUserPassword and
 * UpdateUserProfileInformation, each wrapping `$this->request->validate()` in
 * `precognitive()`), which is why the group carries the APP's precognition
 * middleware and not the framework's: the framework's aborts before the action and
 * would answer "valid" without checking anything.
 *
 * The generous throttle (matching /register's) is NOT for the field-at-a-time
 * validation these forms do as the reader tabs through them
 * (Precognition-Validate-Only). That traffic is counted in a bucket of its own —
 * App\Http\Middleware\ThrottleRequests, which reads the precognition middleware
 * off the GROUP below as well as off a route — so what these two numbers cover is
 * saves alone.
 *****************************************************************************/
Route::middleware(['auth', HandleControllerPrecognitiveRequest::class])->group(function () {
    if (Features::enabled(Features::updateProfileInformation())) {
        Route::put('/user/profile-information', [ProfileInformationController::class, 'update'])
            ->middleware('throttle:30,1,profile-update')
            ->name('user-profile-information.update');
    }

    if (Features::enabled(Features::updatePasswords())) {
        Route::put('/user/password', [PasswordController::class, 'update'])
            ->middleware('throttle:30,1,password-update')
            ->name('user-password.update');
    }
});

Route::delete('/user/delete', [DeleteAccountController::class, 'destroy'])
    ->middleware(['auth', 'throttle:6,1,account-delete'])
    ->name('user.delete');

/******************************************************************************
 * Two-factor authentication (opt-in per user — config/fortify.php)
 *
 * Fortify::ignoreRoutes() is on, so its 2FA endpoints are declared here
 * explicitly, all pointing at Fortify's own controllers. The login-time
 * challenge is guest-only (the user isn't authenticated yet — Fortify holds the
 * pending login id in the session). Every management endpoint is `auth` and —
 * because the feature's 'confirmPassword' option is on — additionally behind
 * Fortify's `password.confirm` middleware, fed by POST /confirm-password (an
 * app-owned ConfirmPasswordController that marks the session password-confirmed
 * for JSON requests). The frontend (useTwoFactorAuth) confirms the password
 * first, then fires the real management request.
 *****************************************************************************/
if (Features::enabled(Features::twoFactorAuthentication())) {
    // Complete a login that paused for a 2FA challenge. Throttled per pending
    // login id via the `two-factor` limiter (FortifyServiceProvider).
    Route::post('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])
        ->middleware(['guest', 'throttle:two-factor'])
        ->name('two-factor.login.store');

    // Fresh password confirmation for the management routes below. JSON only
    // (the 2FA composable posts here via fetch); marks the session confirmed so
    // the `password.confirm` middleware passes on the request that follows.
    Route::post('/confirm-password', [ConfirmPasswordController::class, 'store'])
        ->middleware(['auth', 'throttle:6,1,password-confirm'])
        ->name('password.confirm');

    // Management: enable / disable / confirm enrollment, the QR + secret-key
    // fetched during setup, and viewing / regenerating recovery codes. Gated by
    // `password.confirm` whenever the feature's confirmPassword option is on.
    $twoFactorMiddleware = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword')
        ? ['auth', 'password.confirm']
        : ['auth'];

    Route::middleware($twoFactorMiddleware)->group(function () {
        Route::post('/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'store'])
            ->name('two-factor.enable');
        Route::delete('/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'destroy'])
            ->name('two-factor.disable');
        Route::post('/user/confirmed-two-factor-authentication', [ConfirmedTwoFactorAuthenticationController::class, 'store'])
            ->name('two-factor.confirm');
        Route::get('/user/two-factor-qr-code', [TwoFactorQrCodeController::class, 'show'])
            ->name('two-factor.qr-code');
        Route::get('/user/two-factor-secret-key', [TwoFactorSecretKeyController::class, 'show'])
            ->name('two-factor.secret-key');
        Route::get('/user/two-factor-recovery-codes', [RecoveryCodeController::class, 'index'])
            ->name('two-factor.recovery-codes');
        Route::post('/user/two-factor-recovery-codes', [RecoveryCodeController::class, 'store'])
            ->name('two-factor.regenerate-recovery-codes');
    });
}
