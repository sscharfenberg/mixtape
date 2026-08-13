<?php

declare(strict_types=1);

namespace App\Http\Requests\Audiobooks;

use App\Enums\TrackType;
use App\Http\Requests\Audiobooks\Concerns\AuthorizesAudiobook;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Where the reader has got to in one book (`PUT /audiobooks/{audiobook}/bookmark`).
 *
 * `authorize()` is the routed book's type check and nothing else — the row is written for
 * `$request->user()`, so a caller can only ever bookmark as themselves and there is no
 * ownership to guard. What IS guarded is the pair: the chapter must belong to the book in the
 * URL, or a bookmark could name any chapter in the library and the resume would land in
 * another book entirely.
 *
 * That containment lives in the RULE rather than in the controller, so it is stated where
 * every other constraint on this request is, and answers 422 like the rest of them — the
 * caller is a fetch, not a page, and a validation error is the shape it can read.
 */
class UpdateBookmarkRequest extends FormRequest
{
    use AuthorizesAudiobook;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $audiobook = $this->route('audiobook');

        return [
            'trackId' => [
                'required',
                'uuid',
                // The chapter must exist, be an audiobook chapter, and be one of THIS book's.
                Rule::exists('tracks', 'id')
                    ->where('type', TrackType::Audiobook->value)
                    ->where('collection_id', $audiobook?->id),
            ],
            // Milliseconds into that chapter. Bounded below only: the upper bound is the
            // chapter's own duration, which is nullable in this library and would refuse a
            // legitimate write on a file whose length was never read.
            'positionMs' => ['required', 'integer', 'min:0'],
        ];
    }
}
