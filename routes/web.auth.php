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
 * invite-only registration, email verification and password reset exist today;
 * two-factor auth is deferred (see config/fortify.php) and its routes will be
 * added here alongside its UI. Login/logout/registration use Fortify's own
 * controllers, which defer to the (optionally overridden) response classes;
 * password reset uses app-owned controllers (ForgotController /
 * NewPasswordController) instead of Fortify's, so the single "forgot
 * password / username" page can dispatch either recovery.
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

        // HandleControllerPrecognitiveRequest drives the register form's live
        // field validation (Inertia Precognition). The throttle is generous
        // because each field's validate-on-blur is its own request; the invite
        // requirement is the real abuse gate.
        Route::post('/register', [RegisteredUserController::class, 'store'])
            ->middleware(['throttle:30,1', HandleControllerPrecognitiveRequest::class])
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

        Route::post('/forgot', [ForgotController::class, 'store'])
            ->middleware(['throttle:6,1', HandleControllerPrecognitiveRequest::class])
            ->name('forgot.store');

        Route::get('/reset-password', [NewPasswordController::class, 'show'])
            ->name('password.reset');

        Route::post('/reset-password', [NewPasswordController::class, 'store'])
            ->middleware(['throttle:6,1', HandleControllerPrecognitiveRequest::class])
            ->name('password.reset.store');
    }

    // "Resend verification email": for a user stuck with an expired signed
    // link, who can't log in to trigger a fresh one (login is blocked until
    // verified). Matches name + email before resending, same anti-enumeration
    // shape as the "forgot" flow above.
    if (Features::enabled(Features::emailVerification())) {
        Route::get('/resend-verification', [ResendVerificationController::class, 'show'])
            ->name('verification.resend');

        Route::post('/resend-verification', [ResendVerificationController::class, 'store'])
            ->middleware(['throttle:6,1', HandleControllerPrecognitiveRequest::class])
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
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verify-email');
}

// Password strength (zxcvbn) score for the live registration meter. A stateless
// utility (returns a 0–4 score; changes nothing), so it's a plain web route, not
// a data API. Throttled to blunt abuse of the zxcvbn call.
Route::post('/password/entropy', EntropyController::class)
    ->middleware('throttle:60,1')
    ->name('password.entropy');

/******************************************************************************
 * Dashboard account management (App\Http\Controllers\Dashboard\DashboardController)
 *
 * Profile/password updates go through Fortify's own controllers, which defer
 * to App\Actions\Fortify\UpdateUserProfileInformation / UpdateUserPassword
 * (wired in FortifyServiceProvider); account deletion is app-owned since
 * Fortify has no built-in action for it. The generous throttle (matching
 * /register's) is because each form validates itself one field at a time
 * (Precognition-Validate-Only) as the user tabs through it, not just once on
 * submit.
 *****************************************************************************/
Route::middleware(['auth', HandleControllerPrecognitiveRequest::class])->group(function () {
    if (Features::enabled(Features::updateProfileInformation())) {
        Route::put('/user/profile-information', [ProfileInformationController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('user-profile-information.update');
    }

    if (Features::enabled(Features::updatePasswords())) {
        Route::put('/user/password', [PasswordController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('user-password.update');
    }
});

Route::delete('/user/delete', [DeleteAccountController::class, 'destroy'])
    ->middleware(['auth', 'throttle:6,1'])
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
        ->middleware(['auth', 'throttle:6,1'])
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
