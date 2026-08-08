<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Playlists area (`GET /playlists`, route `playlists`, behind auth) — the
 * reader's OWN saved playlists and nothing else. Single action, so it's invokable.
 *
 * Scoped to the current user at the query, not filtered afterwards: playlists are
 * private per owner (the `(user_id, name)` unique in the migration exists precisely
 * because "your Rock ≠ my Rock"), so a listing that fetched everything and hid the
 * rest would be one forgotten filter away from leaking another account's library.
 *
 * A plain list rather than a DataTable — a person has a handful of playlists, not a
 * thousand, so there is nothing to sort, search or paginate yet. When that changes
 * this becomes a DataTableService call like the Music listings.
 */
class PlaylistsController extends Controller
{
    /**
     * Render the reader's playlists.
     *
     * The two numbers are AGGREGATES over the entries, computed in this one query: how
     * many tracks (counted through the pivot) and how long they play (summed off the
     * joined tracks). Loading the tracks themselves to count and total them in PHP would
     * read a large part of the library to print two figures per row.
     */
    public function __invoke(Request $request): Response
    {
        $playlists = Playlist::query()
            ->where('user_id', $request->user()->id)
            ->withCount('playlistTracks as tracks_count')
            // Raw seconds, exactly as a single track's duration goes over — the page
            // clocks it. Null for an empty playlist, which SQL's SUM of no rows gives.
            ->withSum('tracks as duration', 'duration')
            // The user's own ordering first; `name` breaks the tie, because `position`
            // defaults to 0 and every playlist made before any reordering shares it.
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return Inertia::render('Playlists/PlaylistsPage', [
            'playlists' => $playlists->map(fn (Playlist $playlist): array => [
                'id' => $playlist->id,
                'name' => $playlist->name,
                'description' => $playlist->description,
                'tracks' => (int) $playlist->tracks_count,
                'duration' => $playlist->duration === null ? null : (float) $playlist->duration,
                // Raw ISO-8601, formatted on the page against the viewer's locale and
                // timezone — the server cannot know either (Utils/formatting.ts).
                'createdAt' => $playlist->created_at?->toIso8601String(),
                // NULL for a playlist nothing has happened to since, so the page asks one
                // question ("is there an updatedAt?") instead of comparing two timestamps
                // — the same shape `description` uses. Whether a row changed is a fact
                // about the data, which is the server's half; how a date reads is the
                // page's. Both columns are written from one instant on insert, so they are
                // exactly equal until something moves one of them (PlaylistTrack::$touches
                // is what makes adding or removing a track count as a change).
                'updatedAt' => $playlist->updated_at === null || $playlist->updated_at->equalTo($playlist->created_at)
                    ? null
                    : $playlist->updated_at->toIso8601String(),
            ])->all(),
        ]);
    }
}
