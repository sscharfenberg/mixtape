<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EntropyController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Middleware\HandleControllerPrecognitiveRequest;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

/******************************************************************************
 * Authentication routes
 *
 * FortifyServiceProvider calls Fortify::ignoreRoutes(), so the auth endpoints
 * are declared here explicitly rather than auto-registered. Login + logout and
 * invite-only registration exist today; password reset, email verification and
 * two-factor auth are deferred (see config/fortify.php) and their routes will be
 * added here alongside their UIs. Login/logout/registration use Fortify's own
 * controllers, which defer to the (optionally overridden) response classes.
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
