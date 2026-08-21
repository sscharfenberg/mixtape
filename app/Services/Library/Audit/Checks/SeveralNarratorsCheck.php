<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Enums\TrackType;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Builder;

/** Books whose chapters name more than one narrator — a queue, since a dual reading is real. */
final class SeveralNarratorsCheck extends CollectionCheck
{
    /** A queue: a dual reading is a real thing, and only a person can tell it from a merge. */
    public function group(): AuditGroup
    {
        return AuditGroup::Queue;
    }

    /** Audiobooks only — narrator is not a music tag. */
    public function areas(): array
    {
        return [TrackType::Audiobook];
    }

    /** "Read by" is the audiobook idiom, and it hints at the legitimate case. */
    public function title(): string
    {
        return 'Books read by more than one narrator';
    }

    /** Why this can only ever be a queue. */
    public function blurb(): string
    {
        return 'A book with two narrators is either a genuine dual reading — a full-cast recording, a novel whose '
            .'chapters alternate voices — or two different recordings of one book that have been merged under one '
            .'title. Only you can tell which, and the second is worth catching because the two halves will not '
            .'sound like the same book.';
    }

    /**
     * More than one distinct narrator among the chapters.
     *
     * `havingRaw` over a joined subquery rather than a relation count, because the question is
     * about DISTINCT values rather than about how many rows there are — `has('tracks', '>', 1)`
     * would flag every book with two chapters.
     *
     * @param  Builder<Collection>  $collections
     */
    protected function constrain(Builder $collections): Builder
    {
        return $collections->whereIn('collections.id', function ($query) {
            $query->select('tracks.collection_id')
                ->from('tracks')
                ->whereNotNull('tracks.collection_id')
                ->whereNotNull('tracks.narrator_id')
                ->groupBy('tracks.collection_id')
                ->havingRaw('count(distinct tracks.narrator_id) > 1');
        });
    }
}
