<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Enums\AuditSource;
use App\Enums\TrackType;
use App\Models\Track;
use App\Services\Library\Audit\AuditFinding;
use App\Services\Library\Audit\AuditScope;
use App\Services\Library\Audit\CheckFindings;
use App\Services\Library\Audit\Contracts\Check;
use App\Services\Library\Audit\LibraryFileIndex;

/** How far the database has drifted from the disk — the check that qualifies every other one. */
final class ScanDriftCheck implements Check
{
    /** Its own group, and first: it says how much to trust everything below it. */
    public function group(): AuditGroup
    {
        return AuditGroup::Drift;
    }

    /** Both sides, which is the only check here that compares them. */
    public function source(): AuditSource
    {
        return AuditSource::Both;
    }

    /** Both, and per area — the two sides are only comparable within one root. */
    public function areas(): array
    {
        return TrackType::cases();
    }

    /** "Disagree about" rather than "missing", because the gap has two directions. */
    public function title(): string
    {
        return 'Files the database and the disk disagree about';
    }

    /** Why it is first, and what each of its two directions means. */
    public function blurb(): string
    {
        return 'Every database check in this report is true as of the last scan, and nothing records when that was '
            .'— so this measures it directly. A file ON DISK ONLY has not been scanned yet: it is in no listing, and '
            .'the album holding it reports as missing a track. A file in the DATABASE ONLY has been deleted or '
            .'renamed since the scan: its row still shows in listings and playing it fails. Both are cleared by '
            .'`php artisan app:update`. If this section is clean, the rest of the document describes the library as '
            .'it is right now.';
    }

    /** Which side the file is on, and which area — a path alone cannot say either. */
    public function columns(): array
    {
        return ['State', 'Area'];
    }

    /**
     * Compare the two sides of each area, area-relative paths against area-relative paths.
     *
     * `array_diff` twice rather than a walk with lookups, because both sides are already flat
     * lists of the same shape — `tracks.path` stores exactly what {@see LibraryFileIndex}
     * collects, which is the reason neither side needs resolving to compare.
     *
     * ON-DISK-ONLY IS LISTED FIRST within each area: it is the direction a reader can act on
     * immediately, and the one that silently distorts the checks below. An area whose configured
     * root is not there is reported ahead of both, since nothing about it can be compared at all.
     */
    public function run(AuditScope $scope): CheckFindings
    {
        $findings = [];

        foreach ($scope->overlap($this->areas()) as $area) {
            $key = $area->libraryPathKey();

            if (! $scope->files()->has($area)) {
                /*
                 * A CONFIGURED SHARE THAT IS NOT THERE IS A FINDING, not a silence. Skipping it
                 * would leave this check reading `clean` under a blurb promising that two zeroes
                 * mean the document describes the disk as it is now — while in fact every database
                 * section for this area is unverifiable, and the rows describe files nothing can
                 * currently see. An area nobody configured says nothing, because there is nothing
                 * to say about it.
                 */
                if ($scope->files()->isMissing($area)) {
                    $findings[] = new AuditFinding(
                        $key.':unreachable',
                        config('mixtape.library.paths.'.$key) ?: $key,
                        ['library path not reachable', $key],
                    );
                }

                continue;
            }

            $onDisk = $scope->files()->audio($area);
            $inDatabase = Track::query()->where('tracks.type', $area)->pluck('path')->all();

            foreach (array_diff($onDisk, $inDatabase) as $path) {
                $findings[] = new AuditFinding($key.':disk:'.$path, $path, ['on disk only', $key]);
            }

            foreach (array_diff($inDatabase, $onDisk) as $path) {
                $findings[] = new AuditFinding($key.':db:'.$path, $path, ['database only', $key]);
            }
        }

        return CheckFindings::of($findings);
    }
}
