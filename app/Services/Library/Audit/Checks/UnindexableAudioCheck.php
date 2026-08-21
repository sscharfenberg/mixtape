<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Enums\AuditSource;
use App\Enums\TrackType;
use App\Services\Library\Audit\AuditFinding;
use App\Services\Library\Audit\AuditScope;
use App\Services\Library\Audit\CheckFindings;
use App\Services\Library\Audit\Contracts\Check;
use App\Services\Library\Audit\LibraryFileIndex;

/** Audio files in the library that this instance is not configured to index. */
final class UnindexableAudioCheck implements Check
{
    /**
     * Extensions that are audio to a person but not necessarily to this instance.
     *
     * A LIST OF WHAT TO NOTICE, not of what is supported: the point is to name a file a reader
     * plainly put there as music, so `mixtape.scan.extensions` can be widened or the file
     * converted. `.m4b` is on it because that is the standard audiobook container, and a library
     * imported from anywhere else will be full of them.
     */
    private const AUDIO_LIKE = [
        'flac', 'm4a', 'm4b', 'aac', 'ogg', 'oga', 'opus', 'wma', 'wav', 'aiff', 'aif', 'alac', 'ape', 'wv', 'mpc',
    ];

    /** Hygiene: a config or conversion decision, not a fault in how the library is arranged. */
    public function group(): AuditGroup
    {
        return AuditGroup::Hygiene;
    }

    /** The DISK, necessarily — a file the scanner ignored has no row to find. */
    public function source(): AuditSource
    {
        return AuditSource::Disk;
    }

    /** Both, and the area is reported, since each has its own configured extensions. */
    public function areas(): array
    {
        return TrackType::cases();
    }

    /** "Cannot index" rather than "unsupported": the file may be perfectly playable elsewhere. */
    public function title(): string
    {
        return 'Audio files the library cannot index';
    }

    /** Why an invisible file is worse than a missing one, and the two ways out. */
    public function blurb(): string
    {
        return 'A file with an audio extension outside `mixtape.scan.extensions` (`mp3` by default) is invisible: '
            .'the scanner walks past it without a word, so it is in no album, no listing and no search — and the '
            .'album it belongs to reports as missing a track instead, which sends you looking for a file that is '
            .'already there. Either add the extension to the config, or convert the file.';
    }

    /** The area, because the same filename can sit in either root. */
    public function columns(): array
    {
        return ['Area'];
    }

    /**
     * Filter the disk walk's non-audio half by extension.
     *
     * It reads the index rather than walking on its own account — see {@see LibraryFileIndex},
     * which is what keeps three disk-side checks down to one traversal of the shares.
     */
    public function run(AuditScope $scope): CheckFindings
    {
        $findings = [];

        foreach ($scope->overlap($this->areas()) as $area) {
            foreach ($scope->files()->other($area) as $relative) {
                if (! in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), self::AUDIO_LIKE, true)) {
                    continue;
                }

                $findings[] = new AuditFinding(
                    $area->libraryPathKey().':'.$relative,
                    $relative,
                    [$area->libraryPathKey()],
                );
            }
        }

        return CheckFindings::of($findings);
    }
}
