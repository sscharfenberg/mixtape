<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AlbumFilter;
use App\Enums\AuditGroup;
use App\Enums\TrackType;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Builder;

/** Audiobooks whose chapter numbering reaches past the number of files. */
final class IncompleteBooksCheck extends CollectionCheck
{
    /** Structure, for the same reason the album check is: something is not there. */
    public function group(): AuditGroup
    {
        return AuditGroup::Structure;
    }

    /** Audiobooks only — this is the album check's twin, pointed at the other area. */
    public function areas(): array
    {
        return [TrackType::Audiobook];
    }

    /** Chapter rather than track, which is the word the audiobook area uses throughout. */
    public function title(): string
    {
        return 'Books missing a chapter';
    }

    /** Why a gap matters more in a book than in an album. */
    public function blurb(): string
    {
        return 'The same predicate as "Albums missing a track", over the other area — a book\'s numbering reaches '
            .'past its file count. It costs more here: a missing chapter in the middle of a book is a hole a '
            .'listener walks into hours in, and per-book resume will carry them straight past it.';
    }

    /**
     * The album filter's predicate over books, which is the point.
     *
     * {@see AlbumFilter::Incomplete} adds a `whereIn` over a subquery that groups EVERY track by
     * collection and disc, so it is not album-specific at all — only its `count()` helper scopes
     * to albums. Applying it to a query already narrowed to audiobooks asks the identical question
     * of the other area, which is why there is one predicate here and not two.
     *
     * @param  Builder<Collection>  $collections
     */
    protected function constrain(Builder $collections): Builder
    {
        return AlbumFilter::Incomplete->apply($collections, null);
    }
}
