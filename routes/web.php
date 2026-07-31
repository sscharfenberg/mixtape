<?php

use App\Http\Controllers\AudiobooksController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Music\AlbumController;
use App\Http\Controllers\Music\AlbumCoverController;
use App\Http\Controllers\Music\AlbumsController;
use App\Http\Controllers\Music\ArtistController;
use App\Http\Controllers\Music\ArtistsController;
use App\Http\Controllers\Music\GenreController;
use App\Http\Controllers\Music\GenresController;
use App\Http\Controllers\Music\SongController;
use App\Http\Controllers\Music\SongCoverController;
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

        // Music sub-sections — the "see all" targets from the browse widgets. All four
        // are real server-driven listings.
        Route::get('/music/albums', AlbumsController::class)->name('music.albums');
        Route::get('/music/artists', ArtistsController::class)->name('music.artists');
        Route::get('/music/genres', GenresController::class)->name('music.genres');
        Route::get('/music/songs', SongsController::class)->name('music.songs');

        // One album's detail page — the row-click target of the Albums listing —
        // and its cover art. Same shape and the same two reasons as the song pair
        // below: registered after `/music/albums` so the listing keeps matching,
        // and UUID-constrained so a stray segment 404s at the router.
        Route::get('/music/albums/{album}', AlbumController::class)
            ->whereUuid('album')
            ->name('music.albums.show');

        Route::get('/music/albums/{album}/cover', AlbumCoverController::class)
            ->whereUuid('album')
            ->name('music.albums.cover');

        // One artist's detail page — the row-click target of the Artists listing, and
        // where the artist tile on a song or album page leads. Same two reasons for the
        // shape as the pairs above: registered after `/music/artists` so the listing
        // keeps matching, UUID-constrained so a stray segment 404s at the router. No
        // cover route beside it — MixTape stores no artist images.
        Route::get('/music/artists/{artist}', ArtistController::class)
            ->whereUuid('artist')
            ->name('music.artists.show');

        // One genre's detail page — the row-click target of the Genres listing, and where
        // the genre tile on an artist's page leads. Same shape and the same two reasons as
        // its siblings: after the listing so that keeps matching, UUID-constrained so a
        // stray segment 404s at the router.
        Route::get('/music/genres/{genre}', GenreController::class)
            ->whereUuid('genre')
            ->name('music.genres.show');

        // One song's detail page — the row-click target of the Songs listing.
        // Registered after the listing so `/music/songs` keeps matching it, and
        // constrained to a UUID so a stray path segment 404s here instead of
        // reaching the controller's model binding.
        Route::get('/music/songs/{song}', SongController::class)
            ->whereUuid('song')
            ->name('music.songs.show');

        // The song's cover art, extracted from the file on first request. A route
        // rather than a URL under /storage: covers live behind the same auth as
        // the page that shows them (and `public/storage` points at the *server's*
        // storage dir, which doesn't exist on a dev machine).
        Route::get('/music/songs/{song}/cover', SongCoverController::class)
            ->whereUuid('song')
            ->name('music.songs.cover');
    });

// Authentication (login / logout). Kept in a dedicated file as the auth surface
// grows (password reset, two-factor, …); see the note in web.auth.php.
require __DIR__.'/web.auth.php';

// Dev pages — not linked from anywhere. Registered only outside production so
// the public instance never exposes them (this app is internet-facing).
if (! app()->isProduction()) {
    Route::get('/icons', fn () => Inertia::render('Dev/IconsPage'))->name('dev.icons');
}
