<?php

declare(strict_types=1);

namespace App\Services\Music;

use Illuminate\Support\Collection;

/**
 * Picks the handful of covers a fanned stack of sleeves is drawn from (CoverSleeves.vue).
 *
 * WHY A SERVICE: three pages want the same flourish over three different subjects — a genre's
 * artist cards, a playlist's hero, an artist's hero — and the rule behind it is four small
 * decisions that all have to agree, or the same component renders differently depending on
 * which page reached it. The third copy was the moment to lift it out.
 *
 * THE FOUR DECISIONS, each of which has a reason and none of which is obvious:
 *
 * 1. AT MOST THREE, because that is what the component fans. More sent is more discarded.
 * 2. AT RANDOM, per request (owner's call). The fan differs on every visit and there is
 *    deliberately nothing to cache: it is a decorative flourish whose only job is to look like
 *    a stack of records, so re-shuffling IS the point.
 * 3. NO SLEEVE APPEARS TWICE, enforced on BOTH the key and the URL, because they catch
 *    different duplicates and neither alone is enough.
 *
 *    The KEY catches the same record reached more than once. A cover URL is not always per
 *    album — a playlist's entries carry per-TRACK cover routes, so ten songs off one record
 *    are ten different URLs pointing at the same picture. Callers key by whatever identifies
 *    the record (an album id, or the track's own id for a loose file belonging to no album)
 *    and the array collapses those before this is even called.
 *
 *    The URL catches two records that genuinely resolve to one picture. Nothing in today's
 *    routes can produce that — every cover URL carries the id of the row it belongs to — so
 *    this second pass is a guarantee rather than a fix, and that is the point: three identical
 *    sleeves read as a rendering fault rather than as a stack of records, and whether they can
 *    happen should not rest on a URL scheme a future route could change.
 *
 *    NEITHER PASS PADS. Three or more distinct covers fan three; two fan two; ONE FANS ONE. A
 *    stack topped up to three by repeating a sleeve is the exact fault this prevents, and
 *    CoverSleeves degrades to match (see its banner: in this collection half of all artists
 *    have a single album, so the one-sleeve stack is the COMMON case, not a degenerate one).
 * 4. THE ARTLESS ARE DROPPED, not fanned as placeholders. A card showing two sleeves and a
 *    grey square reads as broken, where two sleeves reads as two records. A subject whose
 *    records ALL lack artwork yields an empty list, which the component renders as a single
 *    placeholder — the honest "nothing here" — rather than as a fan of them.
 */
final class FannedCovers
{
    /** How many sleeves CoverSleeves fans at most. */
    private const SLEEVES = 3;

    /**
     * Up to three cover URLs, at random, one per record.
     *
     * TAKES PAIRS, NOT A MAP, and that shape is the fix for a bug rather than a preference. A
     * `[key => url]` array collapses duplicate keys before this is ever called, keeping the
     * LAST — so a playlist holding two tracks off one album, one with artwork and one without,
     * lost the cover whenever the artless track came second. The fan then drew two sleeves for
     * three illustrated records, and nothing but a count in a browser test noticed. Given pairs
     * this class drops the artless FIRST and only then collapses, so the order entries arrive
     * in cannot decide whether a record has a cover.
     *
     * @param  iterable<array{0: string, 1: string|null}>  $covers  [record key, cover URL] pairs,
     *                                                              one per row the caller has — repeats allowed
     * @return array<int, string>
     */
    public static function pick(iterable $covers): array
    {
        return Collection::make($covers)
            // Artless first — see the docblock: filtering after the collapse is the bug.
            ->filter(fn (array $pair): bool => $pair[1] !== null)
            // Then one entry per RECORD…
            ->keyBy(fn (array $pair): string => $pair[0])
            ->map(fn (array $pair): string => $pair[1])
            // …and one per PICTURE. See decision 3 for why both passes exist.
            ->unique()
            ->shuffle()
            ->take(self::SLEEVES)
            ->values()
            ->all();
    }
}
