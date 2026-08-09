<?php

namespace App\Http\Requests\Playlists;

use App\Http\Requests\Playlists\Concerns\AuthorizesPlaylistOwnership;
use App\Models\PlaylistTrack;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One playlist's entries in a new order (`PUT /playlists/{playlist}/tracks/order`).
 *
 * OWNERSHIP IS SPLIT ACROSS BOTH HALVES, which is the difference from
 * {@see ReorderPlaylistsRequest} and worth reading before copying either. There, the ids ARE
 * the playlists, so ownership could be a per-id `exists` rule and nothing needed authorizing.
 * Here the playlist comes from the URL and the ids are its entries, so the two questions are
 * different: "is this playlist yours" is authorization (and answers 404, so a guessed uuid
 * cannot confirm the playlist exists), while "does this entry belong to that playlist" is a
 * rule — a foreign entry id then fails on the field that carries it rather than 404-ing a
 * request that was otherwise fine.
 *
 * THE LIST MUST BE COMPLETE, for the reason the playlists version records: renumbering only
 * the ids sent would leave every unlisted entry on its old number, interleaved with the new
 * ones — an order the reader never asked for and cannot see the logic of. The UI always
 * sends the whole list, so requiring it costs nothing and turns a confusing half-write into
 * a 422.
 */
class ReorderPlaylistTracksRequest extends FormRequest
{
    use AuthorizesPlaylistOwnership;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            // The PIVOT rows' ids, not the tracks': one track may sit in a playlist twice, so
            // a track id does not identify a position. Scoped to this playlist, which is what
            // stops an ordering reaching entries of another one.
            'ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('playlist_tracks', 'id')->where('playlist_id', $this->editedPlaylist()?->id),
            ],
        ];
    }

    /**
     * Reject a partial ordering — see the class note on why a half-write is worse than a 422.
     *
     * Counted rather than compared id-by-id: `ids.*` has already established that every id is
     * one of this playlist's entries and that there are no duplicates, so matching counts is
     * enough to prove the list is the whole set.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $entries = PlaylistTrack::query()->where('playlist_id', $this->editedPlaylist()?->id)->count();

            if (count((array) $this->input('ids')) !== $entries) {
                $validator->errors()->add('ids', __('playlist.validation')['tracks.incomplete']);
            }
        });
    }
}
