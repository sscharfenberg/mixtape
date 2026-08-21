<?php

declare(strict_types=1);

namespace App\Services\Library\Audit;

use App\Enums\AlbumFilter;
use Illuminate\Support\Facades\DB;

/**
 * WHICH numbers are absent from a collection that is short of its own numbering.
 *
 * The check that finds those collections says only that one is short; the reader's next question
 * is always "short of what", and answering it by hand means a query per album. A book 457 chapters
 * in is the case that makes the difference plain — the gap is unfindable by eye in a list of 673.
 *
 * IT TAKES IDS AND NEVER ASKS WHICH COLLECTIONS ARE SHORT. That separation is the point: membership
 * belongs to {@see AlbumFilter::Incomplete}, the same predicate the listing's tile
 * filters on, and a second copy of it here would be free to disagree — which reads as a wrong
 * number rather than a wrong filter. This is handed the page that is about to be printed, so it
 * also only ever computes what a reader will actually see.
 */
final class MissingNumbers
{
    /**
     * The absent numbers per collection, as the report prints them.
     *
     * PER DISC, matching the predicate that flagged the row: a two-disc set whose second disc is
     * short is not missing "track 7 of 26" — a reader given the wrong spelling goes looking on the
     * wrong disc. {@see DiscTrackList} does the writing, so this column and the repeated-numbers
     * column cannot describe the same kind of fact two different ways.
     *
     * @param  string[]  $collectionIds  the page about to be printed, never the whole library
     * @return array<string, string> collection id => "CD 2 Track 7", "CD 1 Track 1, 2, 3"
     */
    public function for(array $collectionIds): array
    {
        if ($collectionIds === []) {
            return [];
        }

        $rows = DB::table('tracks')
            ->select(['tracks.collection_id', 'tracks.disc', 'tracks.track'])
            ->whereIn('tracks.collection_id', $collectionIds)
            ->whereNotNull('tracks.track')
            ->get();

        /** @var array<string, array<string, list<int>>> $present id => disc key => numbers */
        $present = [];

        foreach ($rows as $row) {
            $present[(string) $row->collection_id][$row->disc === null ? '' : (string) $row->disc][] = (int) $row->track;
        }

        $missing = [];

        foreach ($present as $id => $discs) {
            $gaps = new DiscTrackList;

            foreach ($discs as $disc => $numbers) {
                foreach (self::gapsIn($numbers) as $number) {
                    $gaps->add($disc === '' ? null : (int) $disc, $number);
                }
            }

            if (! $gaps->isEmpty()) {
                $missing[$id] = $gaps->describe();
            }
        }

        return $missing;
    }

    /**
     * The numbers between 1 and the highest that no file claims.
     *
     * From 1 rather than from the lowest present, because a rip that starts at track 3 IS missing
     * 1 and 2 — starting from what is there would quietly redefine the album as complete.
     *
     * @param  list<int>  $numbers
     * @return list<int>
     */
    private static function gapsIn(array $numbers): array
    {
        $highest = max($numbers);
        // Flipped rather than searched: a 673-chapter book turns an in_array per candidate into
        // 673 scans of a 673-element list.
        $have = array_flip($numbers);

        return array_values(array_filter(
            range(1, $highest),
            fn (int $candidate) => ! isset($have[$candidate]),
        ));
    }
}
