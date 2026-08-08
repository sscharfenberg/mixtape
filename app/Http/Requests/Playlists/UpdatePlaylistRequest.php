<?php

namespace App\Http\Requests\Playlists;

use App\Http\Requests\Playlists\Concerns\AuthorizesPlaylistOwnership;
use App\Http\Requests\Playlists\Concerns\ValidatesPlaylistMetadata;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving an existing playlist's metadata (`PUT /playlists/{playlist}`).
 *
 * Where the two traits meet: the ownership rule that guards the form, and the field rules
 * that guard the create. `AuthorizesPlaylistOwnership::editedPlaylist()` is what satisfies
 * the abstract requirement `ValidatesPlaylistMetadata` declares, and so what tells the
 * shared `unique` rule to ignore the row being saved. Trait order is irrelevant — the
 * requirement being abstract is precisely what removes the collision that would otherwise
 * need resolving with `insteadof`.
 *
 * AUTHORIZATION RUNS BEFORE VALIDATION (ValidatesWhenResolvedTrait::validateResolved), so a
 * stranger gets the 404 without the rules ever executing — which is what stops the
 * validate-only path being used to tell an existing playlist from a missing one.
 */
class UpdatePlaylistRequest extends FormRequest
{
    use AuthorizesPlaylistOwnership;
    use ValidatesPlaylistMetadata;
}
