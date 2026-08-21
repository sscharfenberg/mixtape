<?php

declare(strict_types=1);

namespace App\Services\Library\Audit;

use App\Enums\AuditCheck;
use App\Enums\AuditGroup;
use App\Enums\AuditSource;
use App\Services\Library\Audit\Contracts\Check;

/**
 * One check, run: what it is, what it found, or why it did not run.
 *
 * SKIPPED IS NOT CLEAN, and keeping the difference is the whole reason this class exists rather
 * than a bare {@see CheckFindings}. A check that could not look — no audiobooks configured, the
 * area outside `--area` — reports zero findings, which on its own is indistinguishable from a
 * healthy library. A reader acting on "0" that meant "never asked" is the worst outcome an audit
 * can produce, so the report prints the reason instead of the number.
 */
final readonly class CheckResult
{
    /**
     * @param  string|null  $skipped  why this check did not run, or null if it did
     */
    public function __construct(
        public AuditCheck $case,
        public Check $check,
        public CheckFindings $findings,
        public ?string $skipped = null,
    ) {}

    /** A check that never ran, with the reason a reader needs instead of a count. */
    public static function skipped(AuditCheck $case, Check $check, string $reason): self
    {
        return new self($case, $check, CheckFindings::none(), $reason);
    }

    /** Whether it ran and found nothing — the only reading that means "this is fine". */
    public function isClean(): bool
    {
        return $this->skipped === null && $this->findings->isClean();
    }

    /** Whether it has something for the reader to work through, which is what earns a section. */
    public function hasFindings(): bool
    {
        return $this->skipped === null && ! $this->findings->isClean();
    }

    /** Shorthand for the report, which groups by this. */
    public function group(): AuditGroup
    {
        return $this->check->group();
    }

    /** Shorthand for the summary table's source column. */
    public function source(): AuditSource
    {
        return $this->check->source();
    }
}
