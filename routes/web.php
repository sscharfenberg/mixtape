<?php

use App\Http\Controllers\AudiobooksController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Music\AlbumsController;
use App\Http\Controllers\Music\ArtistsController;
use App\Http\Controllers\Music\GenresController;
use App\Http\Controllers\Music\SongsController;
use App\Http\Controllers\MusicController;
use App\Http\Controllers\PlaylistsController;
use App\Http\Controllers\PodcastsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

// Guest landing page.
Route::get('/', HomeController::class)->name('home');

// Language switch — works for guests (session) and authenticated users (DB).
// The frontend posts here via fetch after flipping vue-i18n client-side.
Route::post('/lang/{locale}', [LocaleController::class, 'update'])
    ->middleware('throttle:30,1')
    ->name('locale');

// Authenticated pages. `verified` is folded in only once email verification is
// switched on (deferred — see config/fortify.php), mirroring cantrip.me's group.
Route::middleware(array_filter(['auth', Features::enabled(Features::emailVerification()) ? 'verified' : null]))
    ->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // Top-level browse areas — linked from the header site menu (useSiteAreas).
        // Scaffolds for now: each renders a placeholder page.
        Route::get('/music', MusicController::class)->name('music');
        Route::get('/audiobooks', AudiobooksController::class)->name('audiobooks');
        Route::get('/podcasts', PodcastsController::class)->name('podcasts');
        Route::get('/playlists', PlaylistsController::class)->name('playlists');

        // Music sub-sections — the "see all" targets from the browse widgets
        // (stub pages for now).
        Route::get('/music/albums', AlbumsController::class)->name('music.albums');
        Route::get('/music/artists', ArtistsController::class)->name('music.artists');
        Route::get('/music/genres', GenresController::class)->name('music.genres');
        Route::get('/music/songs', SongsController::class)->name('music.songs');
    });

// Authentication (login / logout). Kept in a dedicated file as the auth surface
// grows (password reset, two-factor, …); see the note in web.auth.php.
require __DIR__.'/web.auth.php';

// Dev pages — not linked from anywhere. Registered only outside production so
// the public instance never exposes them (this app is internet-facing).
if (! app()->isProduction()) {
    Route::get('/icons', fn () => Inertia::render('Dev/IconsPage'))->name('dev.icons');
}
