<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Enums\PlaylistSubject;
use App\Enums\TrackType;
use App\Services\Music\QueuePayload;
use Illuminate\Database\Query\Builder;

/**
 * The tracks behind a TABLE SELECTION — what a DataTable's ticked checkboxes mean, for the two
 * verbs that need the whole queue entry rather than an id (play, and enqueue).
 *
 * WHY THIS IS NOT PlaylistAdditions. The two answer the same question for the same checkboxes
 * and still cannot share a query, because they disagree about audiobook chapters — deliberately,
 * and in opposite directions. Adding a chapter to a playlist through a SUBJECT is refused (an
 * artist's narration is not part of "this artist"), while playing the chapters a reader has
 * ticked on a book's page is the entire point of the control. So the type filter is the one
 * thing that differs, and it is stated once, in {@see query}.
 *
 * THE `song` SUBJECT IS EXACT IDS, AND THAT IS WHY IT IS UNFILTERED. Every other case names a
 * container — an album, an artist, a genre — where "which of its tracks do you mean" is a real
 * question this app has already answered with "the music ones". A caller sending `song` has
 * named each track individually; it already knows what it has, which is precisely the case
 * QueuePayload::fromQuery documents its nullable filter for. Filtering there would make a
 * ticked chapter vanish from the queue with nothing to explain it.
 */
final class QueueSelection
{
    /**
     * A query over `tracks` narrowed to the selection, type filter included.
     *
     * The filter is applied HERE rather than left to QueuePayload::fromQuery so that counting a
     * selection and building it cannot come to mean different sets — the ceiling check in
     * QueueTracksRequest counts through this same method, and a rule written in two places is
     * a rule that eventually holds in one of them.
     *
     * @param  list<string>  $ids  subjects of the kind `$subject` names
     */
    public static function query(PlaylistSubject $subject, array $ids): Builder
    {
        $tracks = QueuePayload::query()->whereIn($subject->column(), $ids);

        return $subject === PlaylistSubject::Song
            ? $tracks
            : $tracks->where('tracks.type', TrackType::Music->value);
    }

    /**
     * How many tracks the selection resolves to, without building any of them.
     *
     * Its own method because the ceiling has to be checked BEFORE the payload is assembled: a
     * selection of twenty genres is a tiny request that maps to a queue nothing can hold, and
     * discovering that by building six megabytes of JSON first is the wrong order.
     *
     * @param  list<string>  $ids
     */
    public static function count(PlaylistSubject $subject, array $ids): int
    {
        return self::query($subject, $ids)->count();
    }

    /**
     * The selection as queue entries, in playing order.
     *
     * `only: null` is not a relaxation — {@see query} has already applied whatever filter this
     * subject implies, and passing the default here would re-apply the music one over a `song`
     * selection that was meant to keep its chapters.
     *
     * @param  list<string>  $ids
     * @return list<array<string, mixed>> entries in the shape `QueueTrack` expects
     */
    public static function payload(PlaylistSubject $subject, array $ids): array
    {
        return QueuePayload::fromQuery(self::query($subject, $ids), only: null);
    }
}
