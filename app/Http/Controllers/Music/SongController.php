<?php

namespace App\Http\Controllers\Music;

use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Track;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One song's detail page (`GET /music/songs/{song}`, route `music.songs.show`,
 * behind auth) — the target of a row click in the Songs listing (SongsController
 * puts this URL on every row as `href`).
 *
 * Sibling to SongsController by design: same `Music` namespace, singular name for
 * the single-record view, so the pair reads like the routes do (`music.songs` /
 * `music.songs.show`).
 *
 * Scaffold for now: it passes the facts the page shows and nothing more. The
 * player, cover art, play history and the "also appears in N other places" clone
 * list all land here later (docs/app-rewrite.md).
 */
class SongController extends Controller
{
    /**
     * Render one song. `{song}` resolves through implicit binding on the Track
     * UUID, so an unknown id is a 404 before this runs.
     *
     * Tracks are one table for music, audiobook chapters and (future) podcast
     * episodes, so a bare binding would happily serve an audiobook chapter under
     * /music/songs/… — the type check is what keeps this route about music.
     */
    public function __invoke(Track $song): Response
    {
        abort_unless($song->type === TrackType::Music, 404);

        // Eager-loaded rather than lazily touched in the array below, so the page
        // costs a fixed four queries no matter how much the scaffold grows.
        $song->load(['artist:id,name', 'collection:id,name,year', 'genre:id,name']);

        return Inertia::render('Music/Songs/Song/SongPage', [
            'song' => [
                'id' => $song->id,
                'name' => $song->name,
                'artist' => $song->artist?->name,
                'album' => $song->collection?->name,
                'genre' => $song->genre?->name,
                'year' => $song->collection?->year,
                'duration' => $song->clockDuration(),
            ],
        ]);
    }
}
