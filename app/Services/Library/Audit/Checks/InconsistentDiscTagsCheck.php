<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Enums\TrackType;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Builder;

/** Albums where some files carry a disc number and others carry none. */
final class InconsistentDiscTagsCheck extends CollectionCheck
{
    /** Structure: it distorts a per-disc question, so it is a shape fault rather than a tag gap. */
    public function group(): AuditGroup
    {
        return AuditGroup::Structure;
    }

    /** Music only — a book's chapters are one sequence and carry no disc. */
    public function areas(): array
    {
        return [TrackType::Music];
    }

    /** Inconsistent, not missing: all files or none is fine, and only the mixture is a fault. */
    public function title(): string
    {
        return 'Inconsistent disc tags';
    }

    /** Why a half-tagged album is worse than an untagged one. */
    public function blurb(): string
    {
        return 'Some files say disc 1 and the rest say nothing, which splits one album into two groups for every '
            .'question asked per disc — so a COMPLETE album reads as short in "Albums missing a track", because six '
            .'files numbered 1–6 with four of them carrying no disc look like two discs missing half their tracks. '
            .'Either tag them all or tag none of them; for a single-disc album, none is the tidier answer.';
    }

    /**
     * Some files numbered, some not.
     *
     * `count(disc)` counts non-null values while `count(*)` counts rows, so the two disagree
     * exactly when a disc tag is missing from some files — which is the whole predicate, and it is
     * spelled the same in Postgres and sqlite.
     *
     * @param  Builder<Collection>  $collections
     */
    protected function constrain(Builder $collections): Builder
    {
        return $collections->whereIn('collections.id', function ($query) {
            $query->select('tracks.collection_id')
                ->from('tracks')
                ->whereNotNull('tracks.collection_id')
                ->groupBy('tracks.collection_id')
                ->havingRaw('count(tracks.disc) > 0 and count(tracks.disc) < count(*)');
        });
    }
}
