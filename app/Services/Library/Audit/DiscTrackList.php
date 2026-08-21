<?php

declare(strict_types=1);

namespace App\Services\Library\Audit;

/**
 * A set of disc-and-track numbers, written the way a person says them.
 *
 * IT EXISTS BECAUSE `2/7` READS AS "2 OF 7". Two columns of this report name numbers — which
 * track an album is short of, and which numbers two files both claim — and both were printed as
 * `disc/track`, which is the same notation everything from page counts to sports scores uses for
 * "part of a total". A reader seeing `2/7` beside a book concludes it has seven chapters. Spelling
 * it out is the whole fix: `CD 2 Track 7` cannot be read any other way.
 *
 * GROUPED BY DISC, because the alternative repeats itself: an album short of ten tracks would
 * otherwise print "CD 1 Track 1, CD 1 Track 2, …" ten times over, and the phrase that makes the
 * cell unambiguous is exactly the phrase that makes it unreadable. One `CD n` per disc, its
 * numbers after it.
 *
 * Files carrying NO disc tag say `Track 7` alone. A placeholder disc — `CD - Track 7`, or worse
 * `CD 0` — invents a disc the tags do not claim, and the missing-disc-tag fault has its own check.
 */
final class DiscTrackList
{
    /** @var array<string, array<int, true>> disc key ('' for none) => track numbers as keys */
    private array $byDisc = [];

    /** Note one number. Repeats collapse, since a set is what both callers mean. */
    public function add(?int $disc, int $track): void
    {
        $this->byDisc[$disc === null ? '' : (string) $disc][$track] = true;
    }

    /** Whether anything was noted at all. */
    public function isEmpty(): bool
    {
        return $this->byDisc === [];
    }

    /** How many numbers are in here, across every disc. */
    public function count(): int
    {
        return array_sum(array_map('count', $this->byDisc));
    }

    /**
     * The list as one cell: `CD 1 Track 3`, `CD 1 Track 1, 2, 3`, `CD 1 Track 3; CD 2 Track 7`.
     *
     * CAPPED AND COUNTED, never silently cut — an album numbered 1 and 300 with nothing between is
     * short of 297 numbers, and one row would be taller than the section holding it. The cap counts
     * NUMBERS rather than discs, so a cell's width is bounded whatever shape the fault has.
     */
    public function describe(int $limit = 10): string
    {
        $discs = $this->byDisc;
        // By disc, then by number: this document is meant to be re-run and compared against the
        // last one, and a set has no order of its own to rely on.
        ksort($discs, SORT_NATURAL);

        $groups = [];
        $named = 0;

        foreach ($discs as $disc => $tracks) {
            if ($named >= $limit) {
                break;
            }

            $numbers = array_keys($tracks);
            sort($numbers);
            $numbers = array_slice($numbers, 0, $limit - $named);
            $named += count($numbers);

            $groups[] = ($disc === '' ? '' : 'CD '.$disc.' ').'Track '.implode(', ', $numbers);
        }

        $omitted = $this->count() - $named;

        return implode('; ', $groups).($omitted > 0 ? ' …and '.number_format($omitted).' more' : '');
    }
}
