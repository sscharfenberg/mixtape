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
use App\Services\Library\Audit\Contracts\RendersOwnSection;
use App\Services\Library\PathEncodingAudit;
use App\Services\Library\PathEncodingAuditResult;
use App\Services\Library\PathEncodingReport;

/** Library paths a Windows-1252 playlist export cannot name. */
final class PathEncodingCheck implements Check, RendersOwnSection
{
    /** What the last {@see run} found, kept so {@see sectionBody} needs no second walk of the disk. */
    private ?PathEncodingAuditResult $result = null;

    /** The audit is injected rather than constructed so its own tests can keep driving it directly. */
    public function __construct(private readonly PathEncodingAudit $audit) {}

    /** Structure: the fix is a rename on disk, which is a filing decision. */
    public function group(): AuditGroup
    {
        return AuditGroup::Structure;
    }

    /** The DISK, and that is the point — see the class note. */
    public function source(): AuditSource
    {
        return AuditSource::Disk;
    }

    /** Both: a playlist can hold a chapter as readily as a song. */
    public function areas(): array
    {
        return TrackType::cases();
    }

    /** Names the consequence (a playlist that cannot name the file) rather than the character. */
    public function title(): string
    {
        return 'Paths a Windows-1252 playlist cannot name';
    }

    /** The whole argument for caring, condensed — the section body carries the detail. */
    public function blurb(): string
    {
        return 'MixTape can export a playlist as a Windows-1252 `.m3u`, which exists because some car head units '
            .'render UTF-8 as mojibake. That encoding covers about 250 characters and writes anything else as `?` — '
            .'which on a path line is not a cosmetic loss but a DEAD line, since `?` is not even a legal filename '
            .'character on FAT. No substitute and no transliteration can rescue it, so the only fix is to rename '
            .'the file, and you find out in the car. Read from DISK, so you can re-run it between renames to check '
            .'your work before scanning.';
    }

    /**
     * Findings are the things to RENAME, not the paths that are broken.
     *
     * Offenders cluster in directory names — one album folder called `F♯ A♯ ∞` makes every track
     * under it unnameable — so a flat list of paths shows ten problems where there is one rename.
     * The count in the summary table is therefore the number of renames, and the section body
     * carries the affected files underneath.
     */
    public function columns(): array
    {
        return ['Characters', 'Files'];
    }

    /** Ask the disk, keeping the full result for the section body. */
    public function run(AuditScope $scope): CheckFindings
    {
        $this->result = $this->audit->scan($scope->files(), $scope->overlap($this->areas()));

        $findings = [];

        foreach ($this->result->renameTargets() as $key => $target) {
            $findings[] = new AuditFinding(
                'rename:'.$key,
                $target['segment'],
                [implode(' ', $target['offenders']), (string) $target['files']],
            );
        }

        return CheckFindings::of($findings);
    }

    /** The character inventory and the work list — see {@see RendersOwnSection} for why it is not a table. */
    public function sectionBody(): string
    {
        return $this->result === null ? '' : PathEncodingReport::section($this->result);
    }
}
