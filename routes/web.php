<?php

use App\Http\Controllers\AudiobooksController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dev\IconsController;
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
use App\Http\Controllers\Music\SongStreamController;
use App\Http\Controllers\MusicController;
use App\Http\Controllers\NowPlayingController;
use App\Http\Controllers\Player\PlayController;
use App\Http\Controllers\Player\PlayerStateController;
use App\Http\Controllers\Playlists\PlaylistMetadataController;
use App\Http\Controllers\PlaylistsController;
use App\Http\Middleware\HandleControllerPrecognitiveRequest;
use Illuminate\Support\Facades\Route;
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
        Route::get('/playlists', PlaylistsController::class)->name('playlists');

        // A playlist's METADATA — its name and blurb — created and edited through one
        // controller and one page (PlaylistMetadataController says why).
        //
        // `/playlists/create` is registered ahead of the `{playlist}` routes below so
        // "create" keeps matching it rather than being read as a playlist id, and those
        // are UUID-constrained so a stray segment 404s at the router instead of reaching
        // model binding. Ownership is checked in the controller, which answers 404 rather
        // than 403 for someone else's playlist — see its `mine()`.
        //
        // HandleControllerPrecognitiveRequest drives the form's live validation on both
        // submits (the base middleware only handles closure routes — see its docblock).
        Route::get('/playlists/create', [PlaylistMetadataController::class, 'create'])
            ->name('playlists.create');

        Route::post('/playlists', [PlaylistMetadataController::class, 'store'])
            ->middleware(['throttle:30,1', HandleControllerPrecognitiveRequest::class])
            ->name('playlists.store');

        Route::get('/playlists/{playlist}/edit', [PlaylistMetadataController::class, 'edit'])
            ->whereUuid('playlist')
            ->name('playlists.edit');

        Route::put('/playlists/{playlist}', [PlaylistMetadataController::class, 'update'])
            ->whereUuid('playlist')
            ->middleware(['throttle:30,1', HandleControllerPrecognitiveRequest::class])
            ->name('playlists.update');

        // What is playing right now. Offered by the header only while the queue holds
        // something (useSiteAreas), but reachable regardless: the queue is client state,
        // so the server cannot know whether this page has anything to show — and a URL
        // that 404s depending on a browser's localStorage would be a worse answer than a
        // page that says the queue is empty.
        Route::get('/now-playing', NowPlayingController::class)->name('now-playing');

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

        // The audio itself — the <audio src> the player loads. Same shape and the
        // same reasons as the cover route beside it: behind auth, UUID-constrained,
        // and a separate controller because it answers with bytes rather than a page.
        Route::get('/music/songs/{song}/stream', SongStreamController::class)
            ->whereUuid('song')
            ->name('music.songs.stream');

        // The play queue, synced up from the browser. A PUT rather than a POST because it
        // REPLACES one row that is read and written wholesale — the same request twice
        // leaves the same state, which matters when the last one is fired by a tab that is
        // closing. It answers 204 and is deliberately not an Inertia visit; the read half
        // travels the other way, as a shared prop on a full page load
        // (HandleInertiaRequests). Throttled because it fires on every track change: a
        // stuck client cannot hammer the database, and 60/minute is far above one write
        // per song.
        Route::put('/player/state', PlayerStateController::class)
            ->middleware('throttle:60,1')
            ->name('player.state.update');

        // One listen, written when the browser has heard enough of a track to call it
        // played. A POST because every one of them is a new row — this is an event log, not
        // a counter — and throttled on the same generous bound as the queue above: a
        // listener at 3× on a short track can legitimately produce a play a few seconds
        // apart, and 60 a minute is far beyond that while still bounding a stuck client.
        Route::post('/player/plays', PlayController::class)
            ->middleware('throttle:60,1')
            ->name('player.plays.store');
    });

// Authentication (login / logout). Kept in a dedicated file as the auth surface
// grows (password reset, two-factor, …); see the note in web.auth.php.
require __DIR__.'/web.auth.php';

// Dev pages — not linked from anywhere. Registered only outside production so
// the public instance never exposes them (this app is internet-facing).
if (! app()->isProduction()) {
    Route::get('/icons', IconsController::class)->name('dev.icons');
}
