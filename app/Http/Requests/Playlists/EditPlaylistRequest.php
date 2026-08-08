<?php

namespace App\Http\Requests\Playlists;

use App\Http\Requests\Playlists\Concerns\AuthorizesPlaylistOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The metadata form for an existing playlist (`GET /playlists/{playlist}/edit`).
 *
 * Authorization only — a GET carries no fields to validate. It exists as its own class
 * rather than as an inline check in the controller so that the page and the save it posts
 * to are guarded by the same rule, stated once: whoever cannot open the form cannot reach
 * the update either ({@see UpdatePlaylistRequest}).
 */
class EditPlaylistRequest extends FormRequest
{
    use AuthorizesPlaylistOwnership;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
