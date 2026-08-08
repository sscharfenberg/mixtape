<?php

namespace App\Http\Requests\Playlists\Concerns;

use App\Models\Playlist;
use Symfony\Component\HttpFoundation\Response;

/**
 * "This playlist is the reader's own", for every request that takes one from the URL.
 *
 * The model arrives already resolved: route-model binding is substituted in middleware,
 * before the controller — and therefore before this request — is constructed, so
 * `$this->route('playlist')` is the Playlist itself rather than an id.
 *
 * A trait, not a parent class, for the reason {@see ValidatesPlaylistMetadata} records:
 * the edit form needs this and no fields, the save needs this AND the fields, and one
 * chain cannot serve both.
 */
trait AuthorizesPlaylistOwnership
{
    public function authorize(): bool
    {
        $playlist = $this->route('playlist');

        return $playlist instanceof Playlist && $playlist->user_id === $this->user()?->id;
    }

    /**
     * 404, not the FormRequest default of 403.
     *
     * This box is deliberately reachable from the internet and shared with family and
     * friends, and "you may not edit that" confirms the playlist EXISTS — enough to walk
     * the id space and learn what other people keep. A 404 answers the same way whether
     * the playlist never existed or simply isn't theirs.
     */
    protected function failedAuthorization(): never
    {
        abort(Response::HTTP_NOT_FOUND);
    }

    /** The playlist this request is about, for the rules and the controller. */
    protected function editedPlaylist(): ?Playlist
    {
        $playlist = $this->route('playlist');

        return $playlist instanceof Playlist ? $playlist : null;
    }
}
