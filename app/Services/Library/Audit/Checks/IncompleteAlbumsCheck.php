<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AlbumFilter;
use App\Enums\AuditGroup;
use App\Enums\TrackType;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Builder;

/** Albums whose own numbering reaches past the number of files they hold. */
final class IncompleteAlbumsCheck extends CollectionCheck
{
    /** Structure: a file is missing from the collection, which no tag edit can fix. */
    public function group(): AuditGroup
    {
        return AuditGroup::Structure;
    }

    /** Music only; the same predicate over books is its own entry in the registry. */
    public function areas(): array
    {
        return [TrackType::Music];
    }

    /** "Missing a track" is the reader's phrasing; the numbering is how it is detected. */
    public function title(): string
    {
        return 'Albums missing a track';
    }

    /** What the predicate means, and what it deliberately does not mean. */
    public function blurb(): string
    {
        return 'Per DISC, the highest track number is greater than the number of files: the album says it has ten '
            .'tracks and nine are here. STRICTLY greater, which is a diagnosis rather than a tidy-up — the other '
            .'direction, more files than the numbering reaches, is repeated numbering and has its own section, '
            .'because calling it incomplete sends you hunting a file that was never missing. Check "Scan drift" '
            .'first: a file that is on disk but unscanned makes a complete album read as short.';
    }

    /**
     * The listing's own predicate, borrowed rather than restated.
     *
     * A TILE'S COUNT AND AN AUDIT'S COUNT ARE THE SAME QUESTION ASKED TWICE. Written out again
     * here the two would drift, and the drift reads as a wrong number rather than as a wrong
     * filter — so this calls {@see AlbumFilter::Incomplete} and the `?filter=incomplete` listing
     * in the app is the same set of albums, always.
     *
     * @param  Builder<Collection>  $collections
     */
    protected function constrain(Builder $collections): Builder
    {
        return AlbumFilter::Incomplete->apply($collections, null);
    }
}
