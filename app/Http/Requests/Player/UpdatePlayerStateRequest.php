<?php

declare(strict_types=1);

namespace App\Http\Requests\Player;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The play queue, synced up from the browser (`PUT /player/state`).
 *
 * THE CAP IS ABOVE THE WHOLE LIBRARY (12,058 tracks at the time of writing), deliberately:
 * a listener can queue a genre of several thousand in one press, and a sync that silently
 * refused the queue they can see would be worse than a large row. It exists to bound what a
 * hand-written request can put in the database, not to police ordinary use.
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
     * `currentIndex` accepts -1, which is the client's "nothing loaded" — an empty queue is
     * a state worth syncing, since clearing the queue on one device should not leave the
     * other restoring it forever.
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
