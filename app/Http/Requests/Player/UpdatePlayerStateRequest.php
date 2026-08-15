<?php

declare(strict_types=1);

namespace App\Http\Requests\Player;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The play queue, synced up from the browser (`PUT /player/state`).
 *
 * THE CAP IS ABOVE THE WHOLE LIBRARY, deliberately, and must stay there: a listener can queue
 * a genre of several thousand in one press, and a sync that silently refused the queue they can
 * see would be worse than a large row. It exists to bound what a hand-written request can put in
 * the database, not to police ordinary use — so raise it if the collection ever approaches it.
 *
 * No `authorize()`: the queue is stored against `$request->user()`, so a caller can only
 * overwrite their own.
 */
class UpdatePlayerStateRequest extends FormRequest
{
    /** Above anything the library can produce — see the class note. */
    public const MAX_TRACKS = 20000;

    /** A day in milliseconds: far past any track, and a bound on nonsense. */
    public const MAX_POSITION_MS = 86_400_000;

    /**
     * `currentIndex` accepts -1, which is the client's "nothing loaded": a queue that exists
     * but has nothing selected in it, which happens between clearing the current track and
     * choosing the next.
     *
     * AN EMPTY QUEUE IS ACCEPTED HERE AND NOT HANDED BACK. `tracks` may be `[]` — refusing it
     * would make a reader who cleared their queue fail every sync until they queued something
     * — but `PlayerStatePayload::forUser` answers null for a stored queue with no ids, so the
     * other device sees "nothing on the server" rather than "the server says empty". A QUEUE
     * IS THEREFORE REPLACED ACROSS DEVICES, NEVER EMPTIED: clear it on the phone, open the
     * laptop, and the laptop's own copy is what comes back.
     *
     * That is the deliberate half of a trade. Distinguishing the two would let a clear
     * propagate, and would equally let one device with an empty queue wipe another's good one
     * on the strength of a stamp — and the stamps are wall clocks, so a device with a wrong
     * one can win an argument it should lose. Losing a queue is the worse failure of the two,
     * so the sync only ever carries content.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'tracks' => ['present', 'array', 'max:'.self::MAX_TRACKS],
            'tracks.*' => ['string', 'uuid'],
            'currentIndex' => ['required', 'integer', 'min:-1', 'max:'.self::MAX_TRACKS],
            'repeat' => ['required', 'boolean'],
            'shuffle' => ['required', 'boolean'],
            // The client's own clock, in milliseconds. Stored verbatim and handed back on
            // the next page load, where the browser compares it with its local copy to
            // decide which is newer — so it is data, not something to trust or correct.
            'updatedAt' => ['required', 'integer', 'min:0'],
            // How far into the loaded track, in milliseconds. Capped at a day, which no
            // track approaches — it bounds what a hand-written request can store, not
            // anything a listener can reach.
            'positionMs' => ['required', 'integer', 'min:0', 'max:'.self::MAX_POSITION_MS],
        ];
    }
}
