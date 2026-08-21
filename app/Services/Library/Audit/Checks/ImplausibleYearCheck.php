<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;

/** Files claiming a year no recording could carry. */
final class ImplausibleYearCheck extends TrackCheck
{
    /** Hygiene: the tag is present and cannot be right, which is the other half of "no year". */
    public function group(): AuditGroup
    {
        return AuditGroup::Hygiene;
    }

    /** The earliest year a released recording can plausibly claim. */
    private const EARLIEST = 1900;

    /** "Impossible" rather than "odd", because the window only catches values no release can hold. */
    public function title(): string
    {
        return 'Files with an impossible year';
    }

    /** Why the window is this wide, and why the upper end is not simply "now". */
    public function blurb(): string
    {
        return 'A four-digit year that is not a year — 0000 from an empty tag, 1 from a truncated one, 9999 from a '
            .'typo — sorts an album to one end of every listing and makes a decade filter lie. The window is '
            .'deliberately generous: 1900 itself is allowed (a tagger default, but also a real release year), and '
            .'so is next year, since a January pressing legitimately ships tagged ahead. It only catches values no '
            .'release can hold, not ones that merely look odd.';
    }

    /**
     * Outside 1900..next year.
     *
     * NEXT year rather than this one, because a release genuinely arrives tagged ahead of its
     * date — a January pressing tagged with the year it ships in is not a fault, and a check
     * that called it one would fire every December.
     *
     * @param  Builder<Track>  $tracks
     */
    protected function constrain(Builder $tracks): Builder
    {
        return $tracks
            ->whereNotNull('tracks.year')
            ->where(fn (Builder $year) => $year
                ->where('tracks.year', '<', self::EARLIEST)
                ->orWhere('tracks.year', '>', (int) now()->format('Y') + 1));
    }
}
