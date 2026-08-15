<?php

namespace App\Http\Controllers\Playlists;

use App\Http\Controllers\Controller;
use App\Http\Requests\Playlists\DeletePlaylistTrackRequest;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Take one entry out of a playlist, and close the gap it leaves behind.
 *
 * `position` IS CONTIGUOUS AND MUST STAY SO — PlaylistTrack's own docblock states it, the
 * reorder renumbers from zero to keep it, and the export sorts on it. Deleting row 4 of nine
 * would otherwise leave 0,1,2,3,5,6,7,8: everything still reads in the right ORDER, so
 * nothing looks wrong, and the next drag renumbers it silently. What breaks meanwhile is
 * anything that treats a position as an index — which is why this is fixed here rather than
 * left for the next reorder to tidy.
 *
 * ONE STATEMENT DOES IT, not a renumber of the whole list. Because the column is contiguous,
 * removing the row at P means every row above P moves down exactly one, so a single
 * conditional decrement is both the smallest write and the one that cannot half-finish. The
 * reorder's row-by-row loop exists because it applies an arbitrary new order; this only ever
 * closes one gap.
 *
 * WHAT THAT DELIBERATELY DOES NOT SURVIVE is two removals in flight at once: the decrement is
 * relative, so a second one working from a position read before the first landed can leave a
 * gap or a collision behind. Renumbering the survivors from zero would close it, at the cost
 * of a write per remaining entry on every single removal. A playlist has exactly one owner,
 * the button disables itself for its own request, and the next reorder renumbers the list
 * anyway — so the race needs two tabs and a deliberate effort, and the cure is more expensive
 * than the disease every other time. Weighed and left alone rather than overlooked.
 *
 * THE PLAYLIST'S `updated_at` MOVES ON THE DELETE ITSELF, with nothing to do here: Eloquent
 * touches owners on delete as well as on save, and PlaylistTrack names `playlist` in
 * `$touches`. The decrement afterwards is a query-builder write and fires no events — it does
 * not need to, since the touch has already happened in this same request. (The reorder has to
 * `touch()` by hand precisely because it has no model delete to ride on.)
 */
class PlaylistTrackDeleteController extends Controller
{
    /**
     * The name and position are read BEFORE the row goes: the flash names the track, and the
     * decrement needs the gap's index.
     */
    public function __invoke(DeletePlaylistTrackRequest $request, Playlist $playlist, PlaylistTrack $entry): RedirectResponse
    {
        $name = $entry->track?->name ?? '';
        $position = $entry->position;

        DB::transaction(function () use ($entry, $playlist, $position): void {
            $entry->delete();

            PlaylistTrack::query()
                ->where('playlist_id', $playlist->id)
                ->where('position', '>', $position)
                ->decrement('position');
        });

        /*
         * IT SAYS SO, even though the row visibly disappears — and that is the difference
         * between this and a reorder, which flashes nothing. A drag can be dragged back; this
         * is a destructive act with no dialog in front of it (owner's call), so the one
         * moment it can be noticed is the moment it happens. The message names the track,
         * because the row that would have answered "which one?" is the row that just went.
         */
        return back()->with([
            'message' => __('flash.playlist.track_removed', ['name' => $name, 'playlist' => $playlist->name]),
            'type' => 'success',
            'duration' => 3000,
        ]);
    }
}
