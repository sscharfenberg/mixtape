<?php

use App\Http\Controllers\Auth\AuthController;
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
use Laravel\Fortify\Http\Controllers\PasswordController;
use Laravel\Fortify\Http\Controllers\ProfileInformationController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

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
