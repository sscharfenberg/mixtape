<?php

namespace App\Http\Requests\Playlists;

use App\Http\Requests\Playlists\Concerns\AuthorizesPlaylistOwnership;
use App\Models\PlaylistTrack;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Take this entry out of this playlist" — the guard on
 * `DELETE /playlists/{playlist}/tracks/{entry}`, pressed straight from a row.
 *
 * TWO QUESTIONS, NOT ONE, which is why this does not simply `use` the ownership trait and
 * stop. The playlist must be the caller's — the trait's question — and the ENTRY must belong
 * to that playlist. Without the second, a caller who owns playlist A could name an entry of
 * somebody else's playlist B in the URL and have it deleted: the ownership check would pass
 * on A while the row that went was B's. Both models are already resolved by the time this
 * runs, so it costs a comparison rather than a query.
 *
 * THE ENTRY, NOT THE TRACK, is what the URL names, and it has to be. The same track may sit
 * in one playlist twice — nothing forbids it, and a running order is exactly where somebody
 * might want it — so a track id would not say WHICH of the two to remove. `playlist_tracks.id`
 * is the only thing that identifies one entry, and it is what the page already renders each
 * row by ({@see ReorderPlaylistTracksRequest} sends the same ids
 * for the same reason).
 *
 * NO CONFIRMATION IN FRONT OF THIS ONE (owner's call), and no `rules()` either — the URL says
 * everything. Removing an entry takes nothing away but a position in a list: the track is
 * untouched, the file is untouched, and putting it back is the "add to playlist" the reader
 * already has. That is a different weight of act from deleting the playlist itself, which
 * cascades into share links and does get a dialog.
 */
class DeletePlaylistTrackRequest extends FormRequest
{
    use AuthorizesPlaylistOwnership {
        authorize as private ownsThePlaylist;
    }

    /** Both halves — see the class note on why the second one is not optional. */
    public function authorize(): bool
    {
        $entry = $this->route('entry');

        return $this->ownsThePlaylist()
            && $entry instanceof PlaylistTrack
            && $entry->playlist_id === $this->editedPlaylist()?->id;
    }
}
