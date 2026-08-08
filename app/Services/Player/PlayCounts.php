<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * How often a TRACK has been listened to — yours, and everybody else's.
 *
 * A track, not a song: `tracks` is one table for music and audiobook chapters, and a listen
 * is a listen whatever kind of thing was listened to. Nothing
 * here knows or asks about the type, so the audiobook pages can read it the day they exist
 * without this file changing. Only the SENTENCES are per-subject, because "you played this
 * song 3 times" is the wrong noun for a chapter — those live with the page that says them.
 *
 * COUNTED BY `content_hash`, NOT BY `track_id`, which is the decision worth knowing about.
 * The same recording legitimately sits in the library several times over: on the album, on a
 * compilation, on a best-of. Counting rows by id would split one song's listening across
 * those copies and report three small numbers where the truth is one larger one — and it
 * would disagree with most-played, which data-model.md settled on the hash for exactly this
 * reason (open decision #5).
 *
 * TWO QUERIES RATHER THAN ONE conditional aggregate: `count(*) FILTER (WHERE …)` is
 * Postgres's spelling and the test suite runs sqlite, and a `SUM(CASE …)` that works in both
 * reads worse than two counts that each say what they mean. Both hit the same indexes
 * (`plays.track_id`, `plays.user_id`), on a page that already runs a handful.
 */
final class PlayCounts
{
    /**
     * Play counts for one track, split into the reader's own and everybody else's.
     *
     * "Others" is derived by subtraction rather than asked for separately — one fewer query,
     * and the two numbers cannot disagree about the total the way two independent counts
     * could if a play landed between them.
     *
     * A guest gets `own: 0` and every play as somebody else's, which is the honest reading:
     * nobody who is not signed in has a listening history here.
     *
     * @return array{own: int, others: int}
     */
    public static function forTrack(Track $track, ?User $user): array
    {
        $plays = fn () => DB::table('plays')
            ->join('tracks', 'plays.track_id', '=', 'tracks.id')
            // A row whose hash is somehow missing counts only its own id: `= NULL` matches
            // nothing, so hashing blindly would report zero listens for a played song.
            ->when(
                $track->content_hash === null,
                fn ($query) => $query->where('tracks.id', $track->id),
                fn ($query) => $query->where('tracks.content_hash', $track->content_hash)
            );

        $total = $plays()->count();
        $own = $user ? $plays()->where('plays.user_id', $user->id)->count() : 0;

        return ['own' => $own, 'others' => $total - $own];
    }
}
