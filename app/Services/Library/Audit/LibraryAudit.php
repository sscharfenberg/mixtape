<?php

declare(strict_types=1);

namespace App\Services\Library\Audit;

use App\Enums\AuditCheck;
use App\Enums\AuditSource;
use App\Enums\TrackType;

/**
 * Runs the checks a command asked for and collects what they found.
 *
 * THIN ON PURPOSE, like the other library services behind their commands: it decides what to ASK
 * and nothing about what the answers mean. The two decisions it does own are the two a check
 * cannot make for itself — whether it is in scope at all, and whether the disk it needs is
 * there — because both are properties of the run rather than of the question.
 */
final class LibraryAudit
{
    /**
     * Run each check in registry order, skipping the ones this run cannot answer.
     *
     * SKIPPING IS DECIDED HERE, in one place, rather than by each check reporting itself absent.
     * There are exactly two reasons — the check's area is outside `--area`, and its area's library
     * path is unconfigured or missing — and both are answerable from the scope, so the
     * alternative would be the same two `if`s copied into twenty-five classes with nothing to
     * stop the twenty-sixth forgetting them. It also keeps the disk walk honest: only a check
     * that declares a disk source can trigger it, which is what makes a database-only run never
     * touch the shares.
     *
     * @param  AuditCheck[]  $cases
     * @param  TrackType[]  $areas
     */
    public function run(array $cases, array $areas): AuditResult
    {
        $scope = new AuditScope($areas);
        $results = [];

        foreach ($cases as $case) {
            $check = $case->check();
            $overlap = $scope->overlap($check->areas());

            if ($overlap === []) {
                $results[] = CheckResult::skipped($case, $check, 'outside the areas this run was asked for');

                continue;
            }

            if ($check->source() !== AuditSource::Database && ! $this->anyAreaOnDisk($scope, $overlap)) {
                $results[] = CheckResult::skipped($case, $check, 'library path not configured, or missing');

                continue;
            }

            $results[] = new CheckResult($case, $check, $check->run($scope));
        }

        return new AuditResult($results, $areas, $scope->scanned());
    }

    /**
     * Whether any of a check's areas was actually walked.
     *
     * ANY rather than all: an instance with music but no audiobooks should still get its music
     * paths audited, and the areas it has nothing for are simply absent from the findings.
     *
     * @param  TrackType[]  $areas
     */
    private function anyAreaOnDisk(AuditScope $scope, array $areas): bool
    {
        foreach ($areas as $area) {
            if ($scope->files()->has($area)) {
                return true;
            }
        }

        return false;
    }
}
