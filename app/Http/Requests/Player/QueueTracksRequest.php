<?php

declare(strict_types=1);

namespace App\Http\Requests\Player;

use App\Enums\PlaylistSubject;
use App\Services\Player\QueueSelection;
use App\Services\Playlists\PlaylistAdditions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "Give me the queue entries for these ticked rows" — the body of `POST /queue/tracks`.
 *
 * ONE SHAPE, matching the subject half of AddTracksToPlaylistRequest: a kind, and the ids of
 * that kind. A table lists one kind of thing, so its ticked rows are always several albums or
 * several artists and never a mixture — which is what lets one `subject` govern the whole list.
 *
 * No `authorize()`. Every track in this library is readable by every signed-in reader, so there
 * is no subject here to own; the route's `auth` is the entire access rule, exactly as it is for
 * the four detail pages whose `queueTracks` prop this stands in for.
 */
class QueueTracksRequest extends FormRequest
{
    /**
     * A kind, and between one and MAX_SUBJECTS ids of it.
     *
     * IDS ARE CHECKED FOR SHAPE, NOT EXISTENCE, the same call AddTracksToPlaylistRequest makes
     * and for the same reason: an `exists` rule per element would be one query per ticked row to
     * establish something the answer already shows. A selection naming a row the scanner has
     * since removed simply comes back without it.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', Rule::enum(PlaylistSubject::class)],
            'ids' => ['required', 'array', 'min:1', 'max:'.PlaylistAdditions::MAX_SUBJECTS],
            'ids.*' => ['uuid'],
        ];
    }

    /**
     * Refuse a selection that resolves to more tracks than the queue can hold.
     *
     * THE ONE CHECK THAT CANNOT BE A RULE, because the size being bounded is not the size of
     * anything in the request: twenty genre ids is a 700-byte body naming most of the library.
     * `ids` being capped bounds what a caller may ASK; this bounds what the answer may be.
     *
     * The ceiling is the play queue's own (UpdatePlayerStateRequest::MAX_TRACKS) rather than a
     * number of this route's choosing, because a payload past it would be handed to a queue
     * that then fails to sync — the reader would see it play and silently lose it on the next
     * load, which is a far worse way to learn the limit than a message.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $subject = PlaylistSubject::from((string) $this->input('subject'));
            $total = QueueSelection::count($subject, array_values((array) $this->input('ids')));

            if ($total > UpdatePlayerStateRequest::MAX_TRACKS) {
                $validator->errors()->add('ids', __('player.validation')['selection.too_large']);
            }
        });
    }
}
