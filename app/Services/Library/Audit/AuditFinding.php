<?php

declare(strict_types=1);

namespace App\Services\Library\Audit;

/**
 * One thing a check found: what it is, and the cells the report prints beside it.
 *
 * All-readonly because a finding is an observation about the library at one moment, not a thing
 * that changes — the check builds it, the report renders it, the state file hashes it.
 */
final readonly class AuditFinding
{
    /**
     * @param  string  $key  STABLE IDENTITY, and the reason this class is not just an array.
     *                       `--cron` decides whether to alert by hashing the sorted keys of a
     *                       check's findings, so the key must name the subject (a row id, a
     *                       path) and never carry a count, a timestamp, or anything that moves
     *                       on its own — a key that changes every run makes every run look new.
     * @param  string  $subject  the first column: the album, book, artist or path at fault
     * @param  string[]  $cells  the remaining columns, in the order the check's `columns()` names
     *                           them, already formatted for a person to read
     */
    public function __construct(
        public string $key,
        public string $subject,
        public array $cells = [],
    ) {}
}
