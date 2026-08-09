<?php

namespace App\Http\Requests\Playlists;

use App\Enums\PlaylistSubject;
use App\Http\Requests\Playlists\Concerns\AuthorizesPlaylistOwnership;
use App\Services\Playlists\PlaylistAdditions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Put these tracks in this playlist" — the body of `POST /playlists/{playlist}/tracks`.
 *
 * TWO SHAPES, EXACTLY ONE PER REQUEST, and the pair is not an inconsistency but the two ways
 * this app can name a set of tracks:
 *
 *   - `{ subject: "artist", id: "<uuid>" }` — from a detail page's hero. The tracks are worked
 *     out server-side, because the browser does not have them: those pages paginate, so what is
 *     on screen is never the whole artist.
 *   - `{ tracks: ["<uuid>", …] }` — from the play queue, which is client state in an order the
 *     reader arranged. There is nothing else the queue could send.
 *
 * `required_without` in both directions is what makes them alternatives rather than options: a
 * body with neither is rejected, and a body with both is accepted with `subject` winning at the
 * controller — a distinction not worth a rule, since nothing in this app sends both and the
 * result is a well-defined addition either way.
 *
 * IDS ARE CHECKED FOR SHAPE, NOT FOR EXISTENCE. An `exists` rule per element would be one query
 * per queued track, and it would be checking something the write already handles: a queue
 * restored from localStorage can legitimately name a file the scanner has removed, and
 * PlaylistAdditions drops those in the same pass that drops the duplicates. So a stale queue
 * adds what it still can instead of failing whole.
 *
 * Ownership is the trait's, and answers 404 rather than 403 for the reason it records.
 */
class AddTracksToPlaylistRequest extends FormRequest
{
    use AuthorizesPlaylistOwnership;

    /**
     * The two shapes, and nothing about what is in the library.
     *
     * `nullable` beside each `required_without` so that the ABSENT half is not also asked to be
     * a uuid or an array — without it a subject-shaped body fails on `tracks` being missing
     * rather than passing as the alternative it is.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required_without:tracks', 'nullable', Rule::enum(PlaylistSubject::class)],
            'id' => ['required_with:subject', 'nullable', 'uuid'],
            // The cap belongs to the ids shape alone — a subject resolves server-side and cannot
            // be inflated by a caller. PlaylistAdditions::MAX_TRACKS says where the number
            // comes from.
            'tracks' => ['required_without:subject', 'nullable', 'array', 'max:'.PlaylistAdditions::MAX_TRACKS],
            'tracks.*' => ['uuid'],
        ];
    }
}
