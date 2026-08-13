<?php

declare(strict_types=1);

namespace App\Services\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * How the matches within one kind are ordered (docs/search.md → "Within a kind: four tiers,
 * then a total tie-break").
 *
 * Four tiers over the rows the search has already selected, then the name, then the id:
 *
 *   0  exact       — the folded query IS the folded name
 *   1  starts with — `black%`
 *   2  word start  — `% black%`, which is what puts "Back in Black" above "Blackberry Way"
 *   3  anywhere    — everything else the match let through
 *
 * A `CASE` in `ORDER BY` rather than anything precomputed: it runs over the filtered rows —
 * 77 of them for "black" against a 12k-track library — so there is no index to add and nothing
 * to keep in step. Tier 0 is checked before tier 1 even though `black%` also matches "black",
 * because SQL's `CASE` evaluates its branches in order.
 *
 * THE TIE-BREAK HAS TO BE TOTAL, and this is the file that owes it. With `LIMIT 5` over a
 * partial order, two identical queries may legitimately return different rows — the same trap
 * `DominantGenre` documents about ties, except here a reader would watch results FLICKER
 * while they type, and read it as the search being broken. So the name is not the last word:
 * the id is, and two rows cannot share one.
 *
 * IT SORTS ON THE FOLD COLUMN, not the raw name, for two reasons that happen to agree. The
 * fold carries the database default (deterministic) collation, so the A→Z order is identical
 * on Postgres and on the sqlite test database — where the raw taxonomy names wear a
 * nondeterministic ICU collation and sort by its rules on one driver only. And it is the same
 * column the tiers compare against, so "starts with" and "before, alphabetically" cannot
 * disagree about what the string is.
 *
 * NO `similarity()` ORDERING and no typo tolerance. `pg_trgm` earns its keep here as the
 * INDEX; as a sort key it produces an order nobody can explain when asked why row 3 beat row
 * 4, and it needs a threshold tuned per collection. If fuzziness is ever wanted it belongs
 * behind "no results", not in front of them.
 */
final class SearchRanking
{
    /**
     * Order `$query` by relevance to `$search`, then totally.
     *
     * `$column` and `$tieBreak` are interpolated into raw SQL and are therefore the CALLER'S
     * constants — the fixed column names each kind declares — never anything off a request.
     * The query string itself only ever travels as a binding.
     *
     * @param  string  $column  the raw column whose `_fold` sibling is ranked, e.g. `tracks.name`
     * @param  string  $tieBreak  the column that makes the order total, e.g. `tracks.id`
     */
    public static function apply(Builder $query, string $column, string $search, string $tieBreak): Builder
    {
        $fold = $column.'_fold';
        $folded = FoldedSearch::fold($search);

        return $query
            ->orderByRaw(
                "case when {$fold} = ? then 0 when {$fold} like ? then 1 when {$fold} like ? then 2 else 3 end",
                [$folded, $folded.'%', '% '.$folded.'%'],
            )
            ->orderBy($fold)
            ->orderBy($tieBreak);
    }
}
