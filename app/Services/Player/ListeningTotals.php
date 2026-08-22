<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Enums\TrackType;
use App\Models\Play;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * HOW LONG ONE READER HAS SPENT IN EACH AREA — the welcome page's ordering, and nothing else so
 * far.
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
 * right approximation for the question, which is about the shape of somebody's listening rather
 * than about minutes owed to anybody.
 *
 * ONE GROUPED QUERY over `plays → tracks`, keyed by the type it lands on. It reads
 * `plays (user_id, played_at)` for the scope and joins on the tracks primary key, so it stays an
 * index walk over one reader's rows rather than a scan of everybody's.
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
}
