<?php

namespace App\Http\Requests\Playlists;

use App\Models\Playlist;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The reader's playlists in a new order (`PUT /playlists/order`).
 *
 * No `authorize()`, and ownership is not skipped — it is a RULE here rather than a
 * permission, because the answer is per-id: `exists` is scoped to the reader, so a foreign
 * or invented id fails validation on the field that carries it instead of 404-ing the whole
 * request. That also means an ordering can never be applied to somebody else's row, which
 * is the only thing that would actually be dangerous about this endpoint.
 *
 * THE LIST MUST BE COMPLETE, checked in `withValidator`. Renumbering only the ids sent would
 * leave every unlisted playlist on its old number, interleaved with the new ones — an order
 * the reader never asked for and cannot see the logic of. The UI always sends the whole
 * listing, so requiring it costs nothing and turns a confusing half-write into a 422.
 */
class ReorderPlaylistsRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            // Scoped to the reader: this is what stops an ordering reaching another account.
            'ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('playlists', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }

    /**
     * Reject a partial ordering — see the class note on why a half-write is worse than a 422.
     *
     * Counted rather than compared id-by-id: `ids.*` has already established that every id is
     * one of the reader's and that there are no duplicates, so matching counts is enough to
     * prove the list is the whole set.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $owned = Playlist::query()->where('user_id', $this->user()->id)->count();

            if (count((array) $this->input('ids')) !== $owned) {
                $validator->errors()->add('ids', __('playlist.validation')['ids.incomplete']);
            }
        });
    }
}
