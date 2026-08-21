<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesLibraryAreas;
use App\Enums\AuditCheck;
use App\Services\Library\Audit\AuditReport;
use App\Services\Library\Audit\AuditResult;
use App\Services\Library\Audit\AuditState;
use App\Services\Library\Audit\LibraryAudit;
use Illuminate\Console\Command;

/**
 * `app:audit` — check the library for everything that can be wrong with it, as a Markdown file
 * to work through.
 *
 * Thin, like the other library commands: the checks live in `App\Services\Library\Audit\Checks`,
 * the registry that orders them in {@see AuditCheck}, and the document in {@see AuditReport}.
 *
 * IT WRITES A FILE RATHER THAN PRINTING. The findings are a work list — fix something, come back,
 * cross it off — and terminal scrollback is a poor place to keep one, especially since some of
 * what it finds (a private-use character in a filename) is invisible on screen and has to be read
 * by code point.
 *
 * IT REPORTS AND NEVER REPAIRS. Every finding is a decision only the reader can make, in a tagger
 * or a file manager; a command that guessed would quietly invent facts about the collection, which
 * is the same reason the scanner refuses to reconcile a disputed year.
 */
class AuditLibrary extends Command
{
    use ResolvesLibraryAreas;

    /** Written next to wherever the command was run, which is where a throwaway working file belongs. */
    private const DEFAULT_FILENAME = 'library-audit.md';

    /**
     * `--cron` and not `--quiet`: Symfony defines `-q|--quiet` globally, and registering it a
     * second time throws before the command can run at all.
     */
    protected $signature = 'app:audit
                            {--area=* : Limit to one or more areas (music, audiobooks). Default: all}
                            {--check=* : Limit to one or more checks by slug. Default: all}
                            {--output= : Where to write the report. Default: '.self::DEFAULT_FILENAME.' in the current directory}
                            {--cron : Say nothing unless the findings changed since the last --cron run, and exit 1 when they did}';

    protected $description = 'Audit the library for tagging, structure and scan problems';

    /**
     * Resolve the scope, run the checks, write the report, and say what was found.
     *
     * EXIT CODES ARE THE INTERESTING PART. A plain run always exits 0 when it ran: findings are
     * this command's normal output, not a failure, and a collection may legitimately sit with
     * known offenders for as long as its owner likes. `--cron` opts INTO a non-zero exit for
     * changed findings, because a scheduler needs a signal it can act on — and only that mode
     * writes the baseline it compares against, so an ad-hoc run can never eat the alert.
     *
     * INVALID for an unusable `--area` or `--check`, FAILURE for a report that could not be
     * written: in both cases the command did not do its job, which is a different thing from
     * finding nothing.
     */
    public function handle(LibraryAudit $audit): int
    {
        $areas = $this->resolveAreas();
        if ($areas === null) {
            return self::INVALID;
        }

        $requested = (array) $this->option('check');
        ['checks' => $checks, 'unknown' => $unknown] = AuditCheck::parse($requested);

        if ($unknown !== []) {
            $this->error("Unknown check '".$unknown[0]."'.");
            // On its own line, and not only for width: the test harness matches one expectation
            // per write, so a message carrying two facts can only ever be asserted on once.
            $this->line('Valid checks: '
                .implode(', ', array_map(fn (AuditCheck $case) => $case->value, AuditCheck::cases())));

            return self::INVALID;
        }

        if ($checks === []) {
            $checks = AuditCheck::cases();
        }

        $cron = (bool) $this->option('cron');
        $target = $this->option('output') ?: self::DEFAULT_FILENAME;

        if (! $cron) {
            $this->narrate('Library audit started ('.$this->describeScope($areas).', '.count($checks).' check(s)).');
        }

        $result = $audit->run($checks, $areas);
        $document = AuditReport::render($result, now()->format('Y-m-d H:i'));

        if (@file_put_contents($target, $document) === false) {
            $this->error("Could not write the report to '{$target}'.");
            // Two lines, not one: what is wrong, then what to do about it. (The test harness also
            // matches one expectation per write, so a line carrying both can only be asserted once.)
            $this->line($this->whyNotWritable($target));
            $this->line('Pass a path you can write, e.g. --output='
                .rtrim((string) (getenv('HOME') ?: '~'), '/').'/'.self::DEFAULT_FILENAME);

            return self::FAILURE;
        }

        return $cron
            ? $this->reportChanges($result, $target)
            : $this->reportRun($result, $target);
    }

    /**
     * Why the write failed, in the terms the reader can act on.
     *
     * THE DEFAULT IS WRONG IN EXACTLY ONE PLACE, and it is a place this command will be run: a
     * production checkout deployed under the `mixtape-deploy` model has an app root owned by the
     * deploy user and group-readable only (2750), so the admin running artisan there cannot create
     * a file beside it — by design, since a web root nobody can write to is the point. "Check the
     * path exists and is writable" is true and useless there; naming the directory, the user and
     * the flag turns a dead end into one more keystroke.
     */
    private function whyNotWritable(string $target): string
    {
        $directory = dirname($target);
        $user = $this->processUser();

        if (! is_dir($directory)) {
            return "There is no directory '{$directory}'.";
        }

        if (! is_writable($directory)) {
            return "'{$directory}' is not writable by {$user} — a deploy-owned app root usually is not.";
        }

        return "The directory is writable by {$user}, so the file itself is probably read-only.";
    }

    /**
     * Who this process is running as, for the message above.
     *
     * Through posix where it exists, because that answers for the EFFECTIVE user — `$USER` is
     * whatever the login shell exported and survives a `sudo -u` that changed everything else,
     * which is precisely the situation this message is explaining. Same approach as
     * `CoverService::processUser`, for the same reason.
     */
    private function processUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());

            if (is_array($user) && isset($user['name'])) {
                return (string) $user['name'];
            }
        }

        return (string) (getenv('USER') ?: 'unknown');
    }

    /**
     * What a person sees: the totals, then where to read the detail.
     *
     * The per-check lines are limited to the checks that FOUND something — a console listing all
     * twenty-five, most of them clean, buries the four that matter under twenty-one that do not.
     * The clean ones are in the document, one row each.
     */
    private function reportRun(AuditResult $result, string $target): int
    {
        $this->narrate(sprintf(
            'Library audit finished — %s finding(s) across %d check(s).',
            number_format($result->total()),
            count($result->results),
        ));

        if ($result->ran() === []) {
            // Said outright, because "0 findings" and "nobody looked" print the same headline: a
            // run this narrow is a mis-typed flag or an unmounted share, not a healthy library.
            $this->warn('No check ran — every one was skipped. The report says why for each.');
        } elseif ($result->isClean()) {
            $this->info('Nothing to fix: every check came back clean.');
        } else {
            foreach ($result->withFindings() as $check) {
                $this->line(sprintf('  %s: %s', $check->check->title(), number_format($check->findings->total)));
            }
        }

        $this->line('Report written to '.(realpath($target) ?: $target));

        return self::SUCCESS;
    }

    /**
     * What a scheduler sees: nothing at all, or exactly what moved.
     *
     * The report file is written either way — the point of the mode is the ALERT, and a cron whose
     * quiet weeks left no document would have nothing to read on the week it spoke.
     */
    private function reportChanges(AuditResult $result, string $target): int
    {
        $statePath = AuditState::pathFor($target);
        $changes = AuditState::changes(AuditState::read($statePath), $result);

        /*
         * A RUN THAT ASKED NOTHING IS AN ALERT, not a quiet week. `changes()` has no opinion about
         * a skipped check by design, so a scheduler whose `--area` and `--check` do not overlap —
         * or whose shares are all unmounted — would otherwise exit 0 in silence for ever, which is
         * the failure this whole mode is supposed to make impossible.
         */
        if ($result->ran() === []) {
            $this->error('Library audit ran no checks at all — every one was skipped. Check --area and --check, '
                .'and that the library paths are mounted.');

            return self::FAILURE;
        }

        if (! AuditState::write($statePath, $result)) {
            // Loud, because the alternative is silence that looks like health: an unwritable state
            // file means every future run re-reports the same findings as new.
            $this->error("Could not write the audit state to '{$statePath}' — every run will look like a change.");

            return self::FAILURE;
        }

        if ($changes === []) {
            return self::SUCCESS;
        }

        $this->warn('Library audit findings changed:');

        foreach ($changes as $line) {
            $this->line('  '.$line);
        }

        $this->line('Report written to '.(realpath($target) ?: $target));

        return self::FAILURE;
    }
}
