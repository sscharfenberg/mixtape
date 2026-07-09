<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Authentication (login / logout). Kept in a dedicated file as the auth surface
// grows (password reset, two-factor, …); see the note in web.auth.php.
require __DIR__.'/web.auth.php';
