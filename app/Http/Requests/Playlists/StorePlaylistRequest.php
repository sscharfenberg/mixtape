<?php

namespace App\Http\Requests\Playlists;

use App\Http\Requests\Playlists\Concerns\ValidatesPlaylistMetadata;
use App\Models\Playlist;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a playlist (`POST /playlists`).
 *
 * No `authorize()`, and that is a decision rather than an omission: any signed-in reader
 * may make a playlist of their own, so the route's `auth` middleware is the whole of the
 * authorization. There is no subject to own yet — ownership only becomes a question once a
 * request names an existing playlist ({@see UpdatePlaylistRequest}).
 *
 * Fields, messages and input cleaning are shared with the edit — see the trait.
 */
class StorePlaylistRequest extends FormRequest
{
    use ValidatesPlaylistMetadata;

    /** Nothing to ignore in the unique rule: there is no existing row yet. */
    protected function editedPlaylist(): ?Playlist
    {
        return null;
    }
}
