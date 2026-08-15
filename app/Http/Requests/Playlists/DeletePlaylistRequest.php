<?php

namespace App\Http\Requests\Playlists;

use App\Http\Requests\Playlists\Concerns\AuthorizesPlaylistOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Delete this playlist" — the guard on `DELETE /playlists/{playlist}`, pressed from the
 * playlist's own detail page behind a confirmation dialog.
 *
 * NO `rules()`, because there is no input: the URL names the row and the act carries no body.
 * What the reader is asked to confirm is a modal on the page, not a field — a checkbox that
 * had to be ticked would be the same click twice. Revoking a share (DestroyShareRequest) is
 * shaped the same way and for the same reason.
 *
 * OWNERSHIP IS THE WHOLE GUARD, and the trait carries it along with the 404. That answer
 * matters more on a delete than on a read: this box is deliberately reachable from the
 * internet and shared with family and friends, so "you may not delete that" would confirm
 * that the id names a real playlist belonging to somebody else.
 *
 * WHAT ELSE GOES WITH IT — the reason the dialog in front of this is worth its click. Both
 * `playlist_tracks` and `shares` name the playlist with `cascadeOnDelete`, so this removes
 * every entry AND revokes every share link minted from it. A link already sent to somebody
 * stops working the moment this succeeds, and nothing can put it back: a share's id IS the
 * capability, so a re-mint is a different link and whoever holds the old one is not reachable
 * to be told.
 */
class DeletePlaylistRequest extends FormRequest
{
    use AuthorizesPlaylistOwnership;
}
