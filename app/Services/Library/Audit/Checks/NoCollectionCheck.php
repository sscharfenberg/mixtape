<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;

/** Files belonging to no album or book at all. */
final class NoCollectionCheck extends TrackCheck
{
    /** Hygiene, though it is the tag whose absence hides a file from every other check here. */
    public function group(): AuditGroup
    {
        return AuditGroup::Hygiene;
    }

    /** Names both containers, because one predicate answers for songs and chapters alike. */
    public function title(): string
    {
        return 'Files in no album or book';
    }

    /** Why an orphan is a scanner symptom rather than only a tagging one. */
    public function blurb(): string
    {
        return 'The scanner groups files into a collection by their ALBUM tag, so a file with none belongs to '
            .'nothing: it has no album page, no cover at album grain, and no siblings. Every other collection-level '
            .'check below is blind to it, which is why it is listed here on its own.';
    }

    /** @param Builder<Track> $tracks */
    protected function constrain(Builder $tracks): Builder
    {
        return $tracks->whereNull('tracks.collection_id');
    }
}
