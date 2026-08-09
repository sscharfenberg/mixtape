<?php

namespace App\Http\Requests\NowPlaying;

use App\Services\Player\NowPlayingFacts;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Now Playing page (`GET /now-playing`) and the one thing it may be asked for: the facts of
 * the tracks it is showing — the genre, the three links, the year and the play counts, none of
 * which the play queue carries.
 *
 * NOTHING IS REQUIRED. The page is reachable with no parameters at all — that is the ordinary
 * visit — and the ids only arrive on the partial reload the page fires once it knows which three
 * tracks it is drawing (the loaded one and its two neighbours, all of which live in the browser).
 *
 * IT VALIDATES BECAUSE POSTGRES THROWS, not merely to be tidy. The ids go into a `whereIn` against
 * a uuid column, and a malformed one is `invalid input syntax for type uuid` — a 500 from a query
 * param anybody can type. Sqlite, which the PHP suite runs on, compares it as a string and finds
 * nothing, so this is exactly the class of difference that passes locally and breaks on the real
 * server (the same trap CreatePlaylistTest records for `lockForUpdate`).
 *
 * The cap is what stops the page's own contract being used as a bulk endpoint: three tracks are
 * shown, so three ids are accepted.
 */
class ShowNowPlayingRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'tracks' => ['sometimes', 'array', 'max:'.NowPlayingFacts::MAX_TRACKS],
            'tracks.*' => ['uuid'],
        ];
    }

    /**
     * The ids to look up, as a plain list.
     *
     * Empty on an ordinary visit, which is what makes the genre prop cost nothing until the page
     * actually asks — see the controller.
     *
     * @return list<string>
     */
    public function trackIds(): array
    {
        /** @var array<int, string> $ids */
        $ids = $this->validated()['tracks'] ?? [];

        return array_values($ids);
    }
}
