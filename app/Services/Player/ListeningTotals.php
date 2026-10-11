<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Enums\TrackType;
use App\Models\Play;
use App\Models\User;
use App\Services\Library\LibraryStats;
use Illuminate\Support\Facades\DB;

/**
 * HOW LONG HAS BEEN SPENT LISTENING, per area — for one reader, which is what orders the welcome
 * page's two buttons, or for everybody together, which is the stats cards' listening-time tile.
 *
 * TIME RATHER THAN A COUNT, which is the whole reason this is not a method on {@see PlayCounts}:
 * that class counts listening EVENTS against a track, and events are the wrong unit for
 * comparing an audiobook collection with a music one. A chapter runs half an hour and a song
 * three minutes, so a reader who finished one novel and heard a dozen songs has more plays of
 * music and far more hours of audiobook. Asked "which do you actually use", hours is the honest
 * answer.
 *
 * SUMMED OVER THE TRACK'S DURATION rather than over anything the player recorded, because a
 * `plays` row is a listening event and carries no length of its own. That makes this an
 * approximation in one direction — a track skipped near its end counts whole — and it is the
 * right approximation for both questions, which are about the shape of the listening rather
 * than about minutes owed to anybody. The tiles' hints say so, so a reader is not left to
 * assume a stopwatch.
 *
 * Both read `plays → tracks` joined on the tracks primary key. One reader's total is an index
 * walk over `plays (user_id, played_at)`; everybody's has no narrower scope than every row,
 * because every row is the question.
 */
final class ListeningTotals
{
    /**
     * Seconds this reader has listened to, per area, keyed by {@see TrackType} value.
     *
     * EVERY AREA IS PRESENT, at 0.0 where there is nothing, so a consumer never has to tell "no
     * listening" from "key absent" — the same shape and the same reason as the `library` shared
     * prop's map of booleans.
     *
     * A GUEST IS ANSWERED WITHOUT ASKING THE DATABASE. `/` is public, so this runs for every
     * stranger who reaches the domain, and there is nothing to ask on their behalf.
     *
     * @return array<string, float>
     */
    public static function forUser(?User $user): array
    {
        $empty = collect(TrackType::cases())
            ->mapWithKeys(fn (TrackType $type): array => [$type->value => 0.0])
            ->all();

        if ($user === null) {
            return $empty;
        }

        $totals = Play::query()
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->where('plays.user_id', $user->id)
            ->groupBy('tracks.type')
            ->select('tracks.type', DB::raw('sum(tracks.duration) as seconds'))
            ->pluck('seconds', 'type');

        return collect($empty)
            ->map(fn (float $zero, string $type): float => (float) ($totals[$type] ?? 0))
            ->all();
    }

    /**
     * Seconds everybody has listened to in one area, all readers together.
     *
     * A TOTAL AND NOTHING ELSE, which is what lets the public welcome page show it to a guest:
     * no reader is named and no track either, so a stranger learns how much has been heard,
     * never what or by whom. One area per call, the way {@see LibraryStats} asks for each card's
     * numbers, so a page with one card runs one query.
     *
     * Cast so a SUM over no rows — a fresh instance, or an area nobody has played — is 0.0
     * rather than null.
     */
    public static function everyone(TrackType $type): float
    {
        return (float) Play::query()
            ->join('tracks', 'tracks.id', '=', 'plays.track_id')
            ->where('tracks.type', $type)
            ->sum('tracks.duration');
    }
}
