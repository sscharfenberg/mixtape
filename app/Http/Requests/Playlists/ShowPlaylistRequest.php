<?php

namespace App\Http\Requests\Playlists;

use App\Http\Requests\Playlists\Concerns\AuthorizesPlaylistOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One playlist's detail page (`GET /playlists/{playlist}`).
 *
 * Authorization only — a GET carries no fields to validate — and its own class rather than
 * an inline check for the reason {@see EditPlaylistRequest} gives: reading a playlist,
 * opening its metadata form and saving that form are all "this one is yours", and the rule
 * is stated once so the three cannot drift apart.
 *
 * The 404-instead-of-403 answer matters more here than on the form pages, and the trait
 * explains why: this page is the one an id would be guessed AT, and a 403 would confirm
 * that the guess named a real playlist.
 */
class ShowPlaylistRequest extends FormRequest
{
    use AuthorizesPlaylistOwnership;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
