<?php

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

// Guest landing page.
Route::get('/', HomeController::class)->name('home');

// Authenticated pages. `verified` is folded in only once email verification is
// switched on (deferred — see config/fortify.php), mirroring cantrip.me's group.
Route::middleware(array_filter(['auth', Features::enabled(Features::emailVerification()) ? 'verified' : null]))
    ->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
    });

// Authentication (login / logout). Kept in a dedicated file as the auth surface
// grows (password reset, two-factor, …); see the note in web.auth.php.
require __DIR__.'/web.auth.php';
