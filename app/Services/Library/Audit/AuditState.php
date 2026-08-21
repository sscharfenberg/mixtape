<?php

declare(strict_types=1);

namespace App\Services\Library\Audit;

/**
 * What the last `--cron` run found, so the next one can say only what CHANGED.
 *
 * WHY A STATE FILE AT ALL. A weekly cron that reports the same 76 incomplete albums every Sunday
 * is a mail rule waiting to happen, and once it is filtered the one week that matters is filtered
 * with it. So the alert is about the delta, and the delta needs a baseline.
 *
 * IT STORES A COUNT AND A HASH PER CHECK, not the findings. The count alone would be blind to a
 * swap — one album fixed and another broken in the same week reads as no change — and the
 * findings themselves would make the file grow with the library for no gain, since nothing ever
 * reads them back except to compare. The hash is over the finding KEYS, so the same faults in a
 * different order compare equal.
 *
 * ONLY `--cron` WRITES IT. A person running the audit by hand must not silently consume the
 * baseline: the cron's next alert would go missing because somebody had already looked, which is
 * the opposite of what an alert is for.
 */
final class AuditState
{
    /**
     * Where the state for a given report lives: beside it, named after it.
     *
     * Derived from the report path rather than configured separately so the two cannot drift
     * apart — two reports written to different paths (a per-area run, say) keep their own
     * baselines without anybody having to say so.
     */
    public static function pathFor(string $reportPath): string
    {
        $directory = dirname($reportPath);
        $name = pathinfo($reportPath, PATHINFO_FILENAME);

        return ($directory === '.' ? '' : $directory.'/').$name.'.state.json';
    }

    /**
     * The previous run's fingerprint, or an empty baseline.
     *
     * A missing, unreadable or corrupt file all read as "no baseline", which makes the first run
     * report everything — the right answer, and the same one a reader gets after deleting the
     * file to force a full alert.
     *
     * @return array<string, array{count: int, hash: string}>
     */
    public static function read(string $path): array
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_filter($decoded, 'is_array') : [];
    }

    /**
     * Record this run as the new baseline. False when it could not be written.
     *
     * MERGED OVER WHAT WAS THERE, never replacing it, and that is what makes the promise in
     * {@see changes} true: a check this run had no opinion about — skipped because its share was
     * unreachable, or absent because `--check=` narrowed the run — keeps the entry it had. Writing
     * only this run's fingerprint would drop those, so the week the share came back would
     * re-announce every standing finding as new, which is precisely the alert this mode exists to
     * avoid. Measured before the fix: a full run wrote 25 entries and the next run with one mount
     * gone wrote 22.
     */
    public static function write(string $path, AuditResult $result): bool
    {
        $state = [...self::read($path), ...$result->fingerprint()];

        return @file_put_contents(
            $path,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        ) !== false;
    }

    /**
     * What changed since the baseline, as one line per check.
     *
     * A CHECK ABSENT FROM THE BASELINE IS NEW, and reported if it FOUND something — that is what
     * makes the first run say everything it found, without also listing every clean check. A check absent from THIS run (skipped, because
     * an area went offline) is not reported as fixed: {@see AuditResult::fingerprint} leaves it
     * out entirely, so its old entry simply stands until the area comes back. Reporting it would
     * announce a batch of repairs nobody made, and then a batch of new faults when it returned.
     *
     * @param  array<string, array{count: int, hash: string}>  $previous
     * @return string[] human-readable lines, empty when nothing moved
     */
    public static function changes(array $previous, AuditResult $result): array
    {
        $lines = [];

        foreach ($result->results as $check) {
            $now = $check->findings;

            if ($check->skipped !== null) {
                continue;
            }

            $before = $previous[$check->case->value] ?? null;

            if ($before !== null && ($before['hash'] ?? null) === $now->fingerprint()) {
                continue;
            }

            // A check with NO baseline and NOTHING to say is not news. Without this the first run
            // announces every clean check as a change — "Mono files: 0" twenty times over — and an
            // alert whose first outing is mostly noise is one nobody reads the second time. A check
            // that drops to zero against a KNOWN baseline is still reported: that is the reader
            // finding out their fixes landed.
            if ($before === null && $now->isClean()) {
                continue;
            }

            // A first run has no "was", and printing "was 0" for it would claim a history the
            // file does not have.
            $was = $before === null ? '' : ' (was '.number_format((int) ($before['count'] ?? 0)).')';
            $lines[] = $check->check->title().': '.number_format($now->total).$was;
        }

        return $lines;
    }
}
