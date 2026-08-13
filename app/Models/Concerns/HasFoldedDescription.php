<?php

namespace App\Models\Concerns;

use App\Services\Search\FoldedSearch;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Keeps a row's `description_fold` in lockstep with its `description` — the companion to
 * {@see HasFoldedName}, for the one model that has a second searchable text column
 * (docs/search.md → "Playlists are the awkward one").
 *
 * A SECOND TRAIT RATHER THAN A GENERALISED FIRST ONE, and the reason is Eloquent's rather
 * than a preference: a mutator is discovered BY METHOD NAME, so "fold another column" can
 * only mean "declare another named mutator". Folding both from inside `HasFoldedName` would
 * therefore hang a `description()` mutator on Artist, Genre, Track and Collection as well —
 * none of which has a `description_fold` column, so the first caller to set a description on
 * any of them would write to a column that does not exist. Opting in per column keeps the
 * fold impossible to forget where it exists and impossible to trigger where it does not.
 *
 * `description_fold` is deliberately not fillable: it is derived, and this is its only
 * writer. The same caveat as the name fold applies — a mass update through the query builder
 * bypasses Eloquent mutators and would leave the fold stale, which presents as a playlist
 * quietly dropping out of search results with nothing failing.
 */
trait HasFoldedDescription
{
    /**
     * Writing `description` writes `description_fold` too.
     *
     * The array form is Eloquent's "set several attributes from one", so both land in the
     * same INSERT/UPDATE — there is no window in which a row has a blurb but no fold. Null
     * passes straight through (FoldedSearch::fold returns null for null), which it must: a
     * playlist with no description has no folded description either, and a `''` there would
     * make `like '%x%'` compare against an empty string rather than skip the row.
     */
    protected function description(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): array => [
                'description' => $value,
                'description_fold' => FoldedSearch::fold($value),
            ],
        );
    }
}
