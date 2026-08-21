<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Enums\TrackType;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Builder;

/** Albums whose files claim different years — a queue, because a compilation legitimately does. */
final class AlbumYearDisagreementCheck extends CollectionCheck
{
    /** A queue: half of these are compilations carrying each track's original year, correctly. */
    public function group(): AuditGroup
    {
        return AuditGroup::Queue;
    }

    /** Music only — a book's chapters share one publication year by construction. */
    public function areas(): array
    {
        return [TrackType::Music];
    }

    /** Named for the disagreement rather than for the year, since neither year is wrong on its own. */
    public function title(): string
    {
        return 'Albums whose files disagree about the year';
    }

    /** Why the scanner will not settle this, and why the audit will not either. */
    public function blurb(): string
    {
        return 'A soundtrack or a best-of legitimately carries each track\'s ORIGINAL year, so disagreement is the '
            .'correct tagging for a large share of these — the scanner reconciles an album\'s year from its files '
            .'and refuses to guess for exactly that reason, and this section refuses in the same way. What is worth '
            .'looking for is the other shape: a studio album where one file was re-tagged from a different release, '
            .'or two albums merged into one row (which usually shows up in "Two albums in one row" as well).';
    }

    /**
     * More than one distinct year among the files.
     *
     * Files with NO year are excluded rather than treated as a value of their own: "some files
     * are untagged" is what the hygiene check says, and folding it in here would report the same
     * album twice for two different faults with two different fixes.
     *
     * @param  Builder<Collection>  $collections
     */
    protected function constrain(Builder $collections): Builder
    {
        return $collections->whereIn('collections.id', function ($query) {
            $query->select('tracks.collection_id')
                ->from('tracks')
                ->whereNotNull('tracks.collection_id')
                ->whereNotNull('tracks.year')
                ->groupBy('tracks.collection_id')
                ->havingRaw('count(distinct tracks.year) > 1');
        });
    }
}
