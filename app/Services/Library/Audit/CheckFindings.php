<?php

declare(strict_types=1);

namespace App\Services\Library\Audit;

/**
 * What one check found: the TOTAL, and the findings the report will actually print.
 *
 * THE TWO NUMBERS ARE DIFFERENT ON PURPOSE. A badly tagged library can answer a single check
 * with thousands of rows, and a document nobody can scroll through is a document nobody reads —
 * so a section lists at most {@see LIST_LIMIT} and says how many it left out. Never silently:
 * a truncated list that does not admit it reads as "that is all of them", which is the one
 * wrong impression an audit must not leave.
 */
final readonly class CheckFindings
{
    /** How many findings one section prints before it starts counting instead. */
    public const LIST_LIMIT = 50;

    /**
     * @param  int  $total  every finding, including the ones not listed
     * @param  AuditFinding[]  $listed  at most {@see LIST_LIMIT} of them, in the check's own order
     */
    private function __construct(
        public int $total,
        public array $listed,
    ) {}

    /**
     * Cap a check's findings for printing, keeping the full count.
     *
     * A named constructor rather than a constructor with a cap inside it, so a check that wants
     * to hand over an already-capped list (a disk walk that stopped early, say) still has to
     * state its total explicitly instead of having one inferred from what survived.
     *
     * @param  AuditFinding[]  $all
     */
    public static function of(array $all): self
    {
        return new self(count($all), array_slice(array_values($all), 0, self::LIST_LIMIT));
    }

    /**
     * A total counted separately from the page that will be printed.
     *
     * The database checks work this way — one `count()`, then one `limit()`ed fetch — because
     * carrying a badly tagged library's every row into memory to print fifty of them is the one
     * cost an audit can trivially avoid. The caller states the total rather than having it
     * inferred, since by construction the list it holds is not all of them.
     *
     * @param  AuditFinding[]  $listed  already capped by the caller's query
     */
    public static function fromPage(int $total, array $listed): self
    {
        return new self($total, array_slice(array_values($listed), 0, self::LIST_LIMIT));
    }

    /** Findings this check could not print, which the section has to admit to. */
    public function omitted(): int
    {
        return max(0, $this->total - count($this->listed));
    }

    /** Nothing to report — the state a reader is working towards. */
    public function isClean(): bool
    {
        return $this->total === 0;
    }

    /** No findings at all, for a check that had nothing to look at. */
    public static function none(): self
    {
        return new self(0, []);
    }

    /**
     * A fingerprint of WHICH findings these are, for `--cron` to compare runs by.
     *
     * Over the keys of everything found, sorted — so the same faults in a different order are
     * the same fingerprint, and a swap (one fixed, one new, the count unchanged) is not. Only
     * the LISTED keys are available to hash, which is why a check past the cap also carries its
     * total into the comparison: past the limit the count is all that can change.
     */
    public function fingerprint(): string
    {
        $keys = array_map(fn (AuditFinding $finding) => $finding->key, $this->listed);
        sort($keys);

        return hash('sha256', $this->total.'|'.implode("\n", $keys));
    }
}
