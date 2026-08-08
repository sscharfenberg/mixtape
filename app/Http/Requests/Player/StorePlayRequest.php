<?php

declare(strict_types=1);

namespace App\Http\Requests\Player;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One listen, beaconed from the browser (`POST /player/plays`).
 *
 * The track is validated as EXISTING rather than as music: `plays` is keyed on the unified
 * `tracks` table, and the day an audiobook chapter can be queued its listens belong here
 * too — a type check would silently drop them, which is the worst of the three possible
 * behaviours.
 *
 * No `authorize()`: the row is written for `$request->user()`, so a caller can only ever
 * record a listen as themselves. There is nothing here to own.
 */
class StorePlayRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'trackId' => ['required', 'uuid', 'exists:tracks,id'],
        ];
    }
}
