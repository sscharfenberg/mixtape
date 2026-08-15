<?php

namespace App\Http\Controllers\Playlists;

use App\Http\Controllers\Controller;
use App\Http\Requests\Playlists\DeletePlaylistRequest;
use App\Models\Playlist;
use Illuminate\Http\RedirectResponse;

/**
 * Delete one playlist, and send the reader back to the listing.
 *
 * INVOKABLE AND ITS OWN CLASS rather than a `destroy` on PlaylistMetadataController, which
 * owns create/edit/store/update. Those four are all one form saving one pair of fields;
 * deleting is not an edit of the metadata, it is the end of the row that carries it.
 *
 * `to_route` RATHER THAN `back()`, unlike every other write on this page. A reorder, an add
 * and a sort all leave the reader looking at the same playlist, so `back()` is right for
 * them — here the page they came from is the page that no longer exists, and re-rendering it
 * would 404 on its own show request. The listing is where the thing they just deleted used
 * to be.
 *
 * THE CASCADE IS THE DATABASE'S, not this method's: `playlist_tracks` and `shares` both
 * declare `cascadeOnDelete` against `playlists`, so the entries and any links minted from it
 * go with the row rather than being swept up here. Doing it by hand would be a second,
 * weaker copy of a rule the schema already enforces — and one that a `php artisan tinker`
 * delete would not get.
 */
class PlaylistDeleteController extends Controller
{
    /**
     * The name is read BEFORE the delete, because the flash names it and the model is gone
     * by the time the message is built.
     */
    public function __invoke(DeletePlaylistRequest $request, Playlist $playlist): RedirectResponse
    {
        $name = $playlist->name;

        $playlist->delete();

        return to_route('playlists')->with([
            'message' => __('flash.playlist.deleted', ['name' => $name]),
            'type' => 'success',
            'duration' => 3000,
        ]);
    }
}
