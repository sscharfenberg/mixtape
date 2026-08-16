<?php

namespace App\Http\Requests\Playlists;

use App\Enums\PlaylistSubject;
use App\Http\Requests\Playlists\Concerns\AuthorizesPlaylistOwnership;
use App\Services\Playlists\PlaylistAdditions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "Put these tracks in this playlist" — the body of `POST /playlists/{playlist}/tracks`.
 *
 * TWO SHAPES, EXACTLY ONE PER REQUEST, and the pair is not an inconsistency but the two ways
 * this app can name a set of tracks:
 *
 *   - `{ subject: "artist", ids: ["<uuid>", …] }` — from a detail page's hero (one id) or a
 *     listing's ticked rows (several). The tracks are worked out server-side, because the
 *     browser does not have them: those pages paginate, so what is on screen is never the whole
 *     artist, and a screenful of ticked artists is smaller as eight ids than as nine hundred.
 *   - `{ tracks: ["<uuid>", …] }` — from the play queue, which is client state in an order the
 *     reader arranged, and from a TRACK table's ticked rows, where the browser is looking
 *     straight at the ids and has no subject to name instead. This is also the only shape that
 *     can carry an audiobook chapter, since the subject query is music-only by design.
 *
 * THE SUBJECT SHAPE IS PLURAL EVEN FOR ONE, so a hero sends `ids: [id]`. A scalar alternative
 * would be a third shape that is a strict special case of the second, and the two would need
 * the same expansion written twice.
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
     * EACH SHAPE CARRIES ITS OWN CEILING because they bound different things: `tracks` is capped
     * at what may be written, `ids` at how many subjects may be ASKED about. Neither number can
     * do the other's job, and `MAX_SUBJECTS` in particular does not bound the write — see
     * {@see withValidator}, which does.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required_without:tracks', 'nullable', Rule::enum(PlaylistSubject::class)],
            'ids' => ['required_with:subject', 'nullable', 'array', 'min:1', 'max:'.PlaylistAdditions::MAX_SUBJECTS],
            'ids.*' => ['uuid'],
            'tracks' => ['required_without:subject', 'nullable', 'array', 'max:'.PlaylistAdditions::MAX_TRACKS],
            'tracks.*' => ['uuid'],
        ];
    }

    /**
     * Hold the subject shape to the SAME ceiling the ids shape has, counted after expansion.
     *
     * WITHOUT THIS THE TWO SHAPES DISAGREE BY ORDERS OF MAGNITUDE, and the generous one is the
     * one a checkbox can reach: `tracks` refuses more than MAX_TRACKS, while a hundred ticked
     * genres is a small, valid body that resolves to the entire library. Select-all on
     * /music/genres at the largest page size is a two-click route to writing more rows in one
     * request than this class refuses outright on its other half.
     *
     * `MAX_SUBJECTS` bounds what a caller may ASK about, which is a different quantity and
     * cannot stand in: five hundred songs and five hundred genres are the same-sized request
     * and not remotely the same write. The play queue's own endpoint makes exactly this
     * distinction for exactly this reason (App\Http\Requests\Player\QueueTracksRequest).
     *
     * Counted rather than truncated, because silently adding the first ten thousand of a
     * selection is the one outcome nobody could explain afterwards.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('subject')) {
                return;
            }

            $subject = PlaylistSubject::tryFrom((string) $this->input('subject'));

            if ($subject === null) {
                return;
            }

            $total = PlaylistAdditions::subjectTracks($subject, array_values((array) $this->input('ids')))->count();

            if ($total > PlaylistAdditions::MAX_TRACKS) {
                $validator->errors()->add('ids', __('playlist.validation')['ids.too_many_tracks']);
            }
        });
    }
}
