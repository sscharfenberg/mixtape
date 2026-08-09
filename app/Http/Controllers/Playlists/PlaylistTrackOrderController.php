<?php

namespace App\Http\Controllers\Playlists;

use App\Http\Controllers\Controller;
use App\Http\Requests\Playlists\ReorderPlaylistTracksRequest;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * The running order of ONE playlist (`PUT /playlists/{playlist}/tracks/order`, route
 * `playlists.tracks.order`, behind auth) — what the detail page's drag handles write.
 *
 * Sibling to PlaylistOrderController, which does the same job one level up (the order of the
 * playlists themselves). Both are PUTs over the whole list rather than a field on one row,
 * because an ordering is a property of the SET: moving one entry renumbers its neighbours, so
 * there is no single row the request is about. Sending the whole order also makes it
 * idempotent — the same request twice leaves the same playlist, which matters for a gesture a
 * browser may retry.
 *
 * Nested under the playlist because these entries belong to it and to nothing else; the
 * request authorizes that playlist (404, never 403) and scopes every id to it.
 *
 * RENUMBERED CONTIGUOUSLY FROM 0, in one transaction — the migration calls `position`
 * contiguous and this is what keeps it so. The transaction is what stops a failure halfway
 * leaving half the playlist renumbered and half not, the one state a reader could not reason
 * about.
 */
class PlaylistTrackOrderController extends Controller
{
    public function __invoke(ReorderPlaylistTracksRequest $request, Playlist $playlist): RedirectResponse
    {
        /** @var array<int, string> $ids */
        $ids = array_values($request->validated()['ids']);

        DB::transaction(function () use ($ids, $playlist): void {
            foreach ($ids as $position => $id) {
                // Scoped to the playlist a second time, after the rule already proved it. Belt
                // and braces on the one query that writes: a rule guards the request, a WHERE
                // guards the row, and it costs an indexed column in a statement already running.
                PlaylistTrack::query()
                    ->where('id', $id)
                    ->where('playlist_id', $playlist->id)
                    ->update(['position' => $position]);
            }

            // BY HAND, because the query builder above fires no model events — so
            // PlaylistTrack::$touches, which bumps the playlist on a save or a delete, never
            // runs for a renumber. Without this the one edit that changes what a playlist IS
            // would be the only one its "changed" date did not notice, and both playlist pages
            // read that date.
            $playlist->touch();
        });

        // No flash: a reorder is its own confirmation — the list comes back in the new order,
        // and a toast for every drag would be noise. `back()` so the reader stays where they are.
        return back();
    }
}
