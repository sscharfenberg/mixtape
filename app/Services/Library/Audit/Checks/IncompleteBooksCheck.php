<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AlbumFilter;
use App\Enums\AuditGroup;
use App\Enums\TrackType;
use App\Models\Collection;
use App\Services\Library\Audit\MissingNumbers;
use Illuminate\Database\Eloquent\Builder;

/** Audiobooks whose chapter numbering reaches past the number of files. */
final class IncompleteBooksCheck extends CollectionCheck
{
    /** The gap calculator, asked once per page — see {@see CollectionCheck::details}. */
    public function __construct(private readonly MissingNumbers $missing) {}

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

    /** The gap first, because it is what the reader acts on; the folder is where they go to do it. */
    public function columns(): array
    {
        return ['Missing', 'Folder'];
    }

    /**
     * Which chapter(s) each flagged collection is short of.
     *
     * The gaps are computed for the PAGE, never derived from a predicate of their own: membership
     * stays with {@see AlbumFilter::Incomplete} so the audit and the listing tile cannot drift,
     * and this only answers "short of what" for the rows already chosen.
     *
     * @param  string[]  $collectionIds
     * @return array<string, string[]>
     */
    protected function details(array $collectionIds): array
    {
        return array_map(
            fn (string $missing) => [$missing],
            $this->missing->for($collectionIds),
        );
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
