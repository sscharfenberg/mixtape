<?php

namespace App\Http\Controllers\Playlists;

use App\Http\Controllers\Controller;
use App\Http\Requests\Playlists\ReorderPlaylistsRequest;
use App\Models\Playlist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * The reader's own ordering of their playlists (`PUT /playlists/order`, route
 * `playlists.order`, behind auth) — what the listing's drag handles write.
 *
 * A PUT, and a collection-level resource rather than a field on one playlist, because an
 * ordering is a property of the SET: moving one entry renumbers its neighbours, so there is
 * no single row the request is about. Sending the whole order also makes it idempotent — the
 * same request twice leaves the same listing, which matters for a gesture a browser may
 * retry.
 *
 * RENUMBERED CONTIGUOUSLY FROM 0, in one transaction. `position` starts life at 0 for every
 * playlist (the column's default), so a listing that has never been reordered is entirely
 * ties broken by name; the first drag is therefore also the moment the whole set gets real
 * numbers. Doing it in a transaction is what stops a failure halfway leaving half the
 * listing renumbered and half not — the one state the reader could not reason about.
 */
class PlaylistOrderController extends Controller
{
    public function __invoke(ReorderPlaylistsRequest $request): RedirectResponse
    {
        /** @var array<int, string> $ids */
        $ids = array_values($request->validated()['ids']);

        DB::transaction(function () use ($ids, $request): void {
            foreach ($ids as $position => $id) {
                // Scoped to the owner a second time, after the rule already proved it. Belt and
                // braces on the one query that writes: a rule guards the request, a WHERE guards
                // the row, and it costs an indexed column in a statement already running.
                Playlist::query()
                    ->where('id', $id)
                    ->where('user_id', $request->user()->id)
                    ->update(['position' => $position]);
            }
        });

        // No flash: a reorder is its own confirmation — the listing comes back in the new order,
        // and a toast for every drag would be noise. `back()` so the reader stays where they are.
        return back();
    }
}
