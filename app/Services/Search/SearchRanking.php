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
 * IT SORTS ON THE FOLD COLUMN, not the raw name, for two reasons. The fold carries a
 * DETERMINISTIC collation, where the raw taxonomy names wear a nondeterministic ICU one that
 * Postgres will not run `LIKE` against at all. And it is the same column the tiers compare
 * against, so "starts with" and "before, alphabetically" cannot disagree about what the string
 * is.
 *
 * IT IS NOT THE SAME ORDER AS THE TEST SUITE'S, and no arrangement here can make it so.
 * Deterministic is not the same property as "orders identically everywhere": on Postgres the
 * fold columns are pinned to `en_GB.utf8`, which ignores a space at the primary level, while
 * sqlite offers only byte order, where a space sorts below every letter. So `black dog` and
 * `blackberry way` come back the other way round under test than in production — measured, and
 * the reason the ranking fixtures deliberately diverge at a LETTER instead (see SearchTest).
 * Assert an order here only where the strings differ somewhere every collation agrees about.
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
