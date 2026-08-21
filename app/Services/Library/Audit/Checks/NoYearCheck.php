<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;

/** Files whose tags name no year at all. */
final class NoYearCheck extends TrackCheck
{
    /** Hygiene: the commonest gap in an imported library, and a one-tag fix. */
    public function group(): AuditGroup
    {
        return AuditGroup::Hygiene;
    }

    /** Files, not songs — a chapter carries a year too. */
    public function title(): string
    {
        return 'Files with no year';
    }

    /** Why an untagged year costs more than it looks like it should. */
    public function blurb(): string
    {
        return 'The scanner records the year each file claims and reconciles an album from its files — it never '
            .'guesses, so one untagged file leaves the album with a year it did not choose, and a decade of '
            .'listening sorted wrongly. Add a YEAR (TDRC) tag.';
    }

    /** @param Builder<Track> $tracks */
    protected function constrain(Builder $tracks): Builder
    {
        return $tracks->whereNull('tracks.year');
    }
}
