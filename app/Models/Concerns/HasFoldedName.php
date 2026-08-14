<?php

namespace App\Models\Concerns;

use App\Services\Search\FoldedSearch;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Keeps a row's `name_fold` in lockstep with its `name` — the accent-folded form
 * the search matches against (FoldedSearch, data-model.md → "Indexes").
 *
 * A mutator rather than a `saving` listener or a caller-side call, because the fold
 * must be impossible to forget: the scanner writes names from three places (insert,
 * re-tag, rename-match) and a stale fold is a SILENT search miss — the row just
 * stops being findable, with nothing failing. Setting `name` is the only way to get
 * a name into any of these tables, so hanging the fold off it covers every path.
 *
 * A COLUMN AT A TIME, which is why {@see HasFoldedDescription} exists beside this rather
 * than this trait folding both: a mutator is found by method name, so a second folded column
 * means a second named mutator, and declaring it here would hang a `description()` mutator on
 * four models that have no `description_fold` to write to.
 *
 * `name_fold` is deliberately NOT fillable anywhere: it is derived, and the only
 * writer is this mutator. Mass updates through the query builder
 * (`Model::query()->update(['name' => …])`) bypass Eloquent mutators entirely and
 * would leave the fold stale — nothing does that today, and nothing should.
 */
trait HasFoldedName
{
    /**
     * Writing `name` writes `name_fold` too. Returning an array from a mutator is
     * Eloquent's "set several attributes from one" form, so both land in the same
     * INSERT/UPDATE — there is no window in which a row has a name but no fold.
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): array => [
                'name' => $value,
                'name_fold' => FoldedSearch::fold($value),
            ],
        );
    }
}
