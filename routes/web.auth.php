<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

/******************************************************************************
 * Authentication routes
 *
 * FortifyServiceProvider calls Fortify::ignoreRoutes(), so the auth endpoints
 * are declared here explicitly rather than auto-registered. Only login + logout
 * exist today; password reset, email verification and two-factor auth are
 * deferred (see config/fortify.php) and their routes will be added here
 * alongside their UIs. Login/logout use Fortify's own session controller, which
 * defers to the default response classes (redirect to config('fortify.home')).
 *****************************************************************************/

// Guest-only: the login page and the credential POST handled by Fortify.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'loginView'])
        ->name('login');

    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

// Authenticated-only: end the session and return to the home page.
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
