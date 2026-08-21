<?php

declare(strict_types=1);

namespace App\Services\Library\Audit;

use App\Enums\AuditGroup;
use App\Enums\TrackType;

/**
 * One whole run of the audit: every check that was asked for, in registry order.
 *
 * The roll-ups live here rather than in the renderer because they are the interesting half and
 * worth testing without going near Markdown — "is any of this worth telling somebody about" is a
 * question about the findings, not about layout, and it is the question `--cron` turns on.
 */
final readonly class AuditResult
{
    /**
     * @param  CheckResult[]  $results  in registry order
     * @param  TrackType[]  $areas  the areas the run was asked for
     * @param  int  $scanned  audio files walked, or 0 when no check needed the disk
     */
    public function __construct(
        public array $results,
        public array $areas,
        public int $scanned = 0,
    ) {}

    /**
     * The results in one group, for the report's section-per-group shape.
     *
     * @return CheckResult[]
     */
    public function inGroup(AuditGroup $group): array
    {
        return array_values(array_filter($this->results, fn (CheckResult $result) => $result->group() === $group));
    }

    /**
     * The groups that have any check in this run at all, in enum order.
     *
     * Asked rather than assumed, because `--check=` can narrow a run down to one group and a
     * heading over nothing reads as a section that failed to render.
     *
     * @return AuditGroup[]
     */
    public function groups(): array
    {
        return array_values(array_filter(
            AuditGroup::cases(),
            fn (AuditGroup $group) => $this->inGroup($group) !== [],
        ));
    }

    /**
     * The checks with something to say, which are the only ones that get a section.
     *
     * @return CheckResult[]
     */
    public function withFindings(): array
    {
        return array_values(array_filter($this->results, fn (CheckResult $result) => $result->hasFindings()));
    }

    /** Findings across every check — the one number a reader looks at first. */
    public function total(): int
    {
        return array_sum(array_map(fn (CheckResult $result) => $result->findings->total, $this->results));
    }

    /**
     * Whether every check that ran came back empty — and at least one DID run.
     *
     * THE SECOND HALF IS THE LOAD-BEARING ONE. Without it a run where every check was skipped
     * reads as a clean library: `app:audit --area=audiobooks --check=incomplete-albums` would
     * print "nothing to fix" having asked nothing at all, and under `--cron` a scheduler with a
     * mis-scoped flag would sit green for ever. Skipped is not clean, and this is where the two
     * stop being the same number.
     */
    public function isClean(): bool
    {
        return $this->ran() !== [] && $this->withFindings() === [];
    }

    /**
     * The checks that actually ran, whatever they found.
     *
     * @return CheckResult[]
     */
    public function ran(): array
    {
        return array_values(array_filter($this->results, fn (CheckResult $result) => $result->skipped === null));
    }

    /**
     * The per-check fingerprint `--cron` compares runs by.
     *
     * A SKIPPED CHECK IS ABSENT rather than recorded as zero, which is what stops an area going
     * offline from reading as a batch of fixes — and, on the run after it comes back, as a batch
     * of new faults. Absent means "no opinion", so the previous fingerprint is simply kept.
     *
     * @return array<string, array{count: int, hash: string}>
     */
    public function fingerprint(): array
    {
        $state = [];

        foreach ($this->results as $result) {
            if ($result->skipped !== null) {
                continue;
            }

            $state[$result->case->value] = [
                'count' => $result->findings->total,
                'hash' => $result->findings->fingerprint(),
            ];
        }

        return $state;
    }
}
