<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Enums\TrackType;

/**
 * One file whose path a Windows-1252 .m3u cannot name.
 *
 * All-readonly because a finding is an observation about the filesystem at one moment, not a
 * thing that changes: the audit builds it, the report renders it, nobody edits it in between.
 */
final readonly class PathEncodingFinding
{
    /**
     * @param  string  $path  area-relative, exactly as the scanner would store it
     * @param  string[]  $offenders  the offending characters, de-duplicated, in first-appearance order
     * @param  bool  $precomposeFixes  whether NFC normalisation ALONE would make the path encodable —
     *                                 true for a macOS-decomposed accent, where the fix changes bytes
     *                                 but not one visible glyph, and false for a genuinely foreign
     *                                 character that has to be replaced by hand
     */
    public function __construct(
        public TrackType $area,
        public string $path,
        public array $offenders,
        public bool $precomposeFixes,
    ) {}

    /**
     * The path split into its segments, so the report can point at the ONE that is broken.
     *
     * A bad directory name is inherited by every file beneath it — ten tracks under
     * `F♯ A♯ ∞` are ten findings and one rename — so the report groups by segment rather than
     * listing paths, and this is what it groups on.
     *
     * @return string[]
     */
    public function segments(): array
    {
        return explode('/', $this->path);
    }
}
