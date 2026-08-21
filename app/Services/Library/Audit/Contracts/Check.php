<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Contracts;

use App\Enums\AuditGroup;
use App\Enums\AuditSource;
use App\Enums\TrackType;
use App\Services\Library\Audit\AuditScope;
use App\Services\Library\Audit\CheckFindings;

/**
 * One question `app:audit` asks about the library.
 *
 * A CHECK OWNS EVERYTHING ABOUT ITSELF — its prose as much as its predicate — and that is the
 * point of the interface. The alternative, an enum carrying titles and explanations for
 * twenty-five checks with the queries elsewhere, splits one decision across two files: adding a
 * check would mean editing a registry, a match arm, and a class, and forgetting the middle one
 * yields a check that runs and never appears. Here `App\Enums\AuditCheck` holds only the ORDER
 * and the slug, so a new check is one enum case and one class.
 *
 * A check REPORTS. Nothing in this namespace writes to the library, and nothing guesses: every
 * finding is a decision for the reader to make in their tagger.
 */
interface Check
{
    /** Which section of the report this belongs under — and, for a queue, what its findings mean. */
    public function group(): AuditGroup;

    /** Where the facts come from, printed beside the count because it decides how fresh they are. */
    public function source(): AuditSource;

    /**
     * The areas this check can answer for. A check outside the run's `--area` is skipped.
     *
     * @return TrackType[]
     */
    public function areas(): array;

    /** The section heading — a noun phrase naming what is wrong, not the query. */
    public function title(): string;

    /**
     * Why this finding matters and what to do about it, for a reader who has never seen it.
     *
     * The load-bearing half of a check: a list of album names with no explanation is a puzzle,
     * and the report outlives the run that wrote it.
     */
    public function blurb(): string;

    /**
     * The table headers after the subject column, matching each finding's `cells`.
     *
     * @return string[]
     */
    public function columns(): array;

    /** Ask the question. */
    public function run(AuditScope $scope): CheckFindings;
}
