<?php

namespace App\Services\Library;

use App\Enums\TrackType;

/**
 * Mutable per-area tally the scanner fills in as it reconciles one area against
 * the database. The four outcomes are mutually exclusive per file:
 *
 *  - inserted  — genuinely new audio (new id)
 *  - updated   — same path, changed bytes (a re-tag) → id kept
 *  - renamed   — new path, matched an existing row by content hash → id kept
 *  - deleted   — a row whose file vanished (relink-then-cascade, then removed)
 *
 * `errors` counts files skipped because they could not be read — non-fatal, so
 * one bad file never aborts the run (unlike the legacy scanner).
 */
final class ScanResult
{
    public int $discovered = 0;

    public int $inserted = 0;

    public int $updated = 0;

    public int $renamed = 0;

    public int $deleted = 0;

    public int $errors = 0;

    /**
     * Per-file skip detail (path + getID3's reason), so the command can log and
     * e-mail exactly what was skipped and why — never a silent drop.
     *
     * @var array<int, array{path: string, reason: string}>
     */
    public array $skipped = [];

    /**
     * Set when the empty-directory guard tripped: the area's configured
     * directory yielded zero files while the DB still had `protectedRows` rows,
     * so the destructive diff was skipped (likely a dropped mount). The command
     * escalates this to an alert + non-zero exit.
     */
    public bool $skippedEmpty = false;

    public int $protectedRows = 0;

    /** One result per area; the scanner mutates the counters above in place as it reconciles (so they aren't readonly). */
    public function __construct(public readonly TrackType $type) {}
}
