<?php

namespace Tests\Feature\Library\Audit;

use App\Services\Library\Audit\AuditState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Library\InteractsWithLibraryFiles;
use Tests\TestCase;

/**
 * `app:audit` — the command wiring around the checks.
 *
 * WHAT each check finds is covered per check; this covers the things only the command decides:
 * where the file lands, what the console says, and the exit codes — including the two that are
 * deliberate choices rather than oversights. Findings are not a failure on a plain run, and they
 * ARE one under `--cron`, which is the whole difference between a report and an alert.
 */
class AuditLibraryCommandTest extends TestCase
{
    use InteractsWithLibraryFiles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeLibraryRoot();
        config(['mixtape.library.paths.audiobooks' => '']);
    }

    protected function tearDown(): void
    {
        $this->removeLibraryRoot();
        parent::tearDown();
    }

    /** Somewhere inside the temp root to write to, so nothing lands in the repo. */
    private function reportPath(): string
    {
        return $this->root.'/library-audit.md';
    }

    public function test_it_writes_a_report_of_every_check_it_ran(): void
    {
        $this->rawFile('Mgla/Mgła - Age of Excuse.mp3');

        $this->artisan('app:audit', ['--output' => $this->reportPath()])
            ->assertExitCode(0);

        $report = file_get_contents($this->reportPath());

        $this->assertStringContainsString('# Library audit', $report);
        // The finding, and the section it earned.
        $this->assertStringContainsString('Paths a Windows-1252 playlist cannot name', $report);
        $this->assertStringContainsString('LATIN SMALL LETTER L WITH STROKE', $report);
        // …and a clean check still gets its row, which is what says it ran.
        $this->assertStringContainsString('| Mono files | database | clean |', $report);
    }

    public function test_findings_are_reported_rather_than_treated_as_a_failure(): void
    {
        /*
         * A deliberate choice: this is a report, not a linter, and a collection may legitimately
         * sit with known offenders for as long as its owner likes. Exiting non-zero would turn a
         * standing list into a nightly cron mail — which is what `--cron` is for, opted into.
         */
        $this->rawFile('Mgła/01.mp3');

        $this->artisan('app:audit', ['--output' => $this->reportPath()])
            ->assertExitCode(0);
    }

    public function test_it_says_what_it_found_without_making_the_reader_open_the_file(): void
    {
        $this->rawFile('Godspeed/[1997] F♯ A♯ ∞/01.mp3');
        $this->rawFile('Godspeed/[1997] F♯ A♯ ∞/02.mp3');

        $this->artisan('app:audit', ['--output' => $this->reportPath()])
            ->expectsOutputToContain('Paths a Windows-1252 playlist cannot name: 1')
            ->expectsOutputToContain('Report written to')
            ->assertExitCode(0);
    }

    public function test_a_clean_library_says_so_on_the_console(): void
    {
        /*
         * The empty report is the point of re-running: it is how you confirm the work is done.
         *
         * An EMPTY library rather than one holding a well-named file, and that is not laziness —
         * a file on disk with no row is itself a finding (scan drift), so the only library where
         * every check is clean is one where the disk and the database agree, which here means
         * both being empty.
         */
        $this->artisan('app:audit', ['--output' => $this->reportPath()])
            ->expectsOutputToContain('Nothing to fix')
            ->assertExitCode(0);
    }

    public function test_it_defaults_to_the_directory_the_command_was_run_from(): void
    {
        // A throwaway working file belongs next to whoever ran the command, not in storage.
        $previous = getcwd();
        chdir($this->root);

        try {
            $this->artisan('app:audit')->assertExitCode(0);
            $this->assertFileExists($this->root.'/library-audit.md');
        } finally {
            chdir($previous);
        }
    }

    public function test_an_unwritable_destination_fails_loudly_instead_of_reporting_success(): void
    {
        // Silently "succeeding" with no file is the one outcome that would waste real time.
        $this->artisan('app:audit', ['--output' => $this->root.'/no/such/dir/report.md'])
            ->expectsOutputToContain('Could not write the report')
            ->expectsOutputToContain('There is no directory')
            ->assertExitCode(1);
    }

    public function test_an_unwritable_directory_names_itself_and_offers_a_way_out(): void
    {
        /*
         * THE CASE THE DEFAULT GETS WRONG, and it is a place this command will be run: a production
         * checkout under the `mixtape-deploy` model has an app root owned by the deploy user and
         * group-readable only, so the admin running artisan there cannot create a file beside it —
         * by design. "Check the path exists and is writable" is true and useless; the directory,
         * the user and the flag are what turn it into one more keystroke.
         */
        $locked = $this->root.'/locked';
        mkdir($locked, 0o555, true);

        try {
            $this->artisan('app:audit', ['--output' => $locked.'/report.md'])
                ->expectsOutputToContain('is not writable by')
                ->expectsOutputToContain('--output=')
                ->assertExitCode(1);
        } finally {
            chmod($locked, 0o755);
        }
    }

    public function test_an_unknown_area_is_rejected_before_anything_is_scanned(): void
    {
        $this->artisan('app:audit', ['--area' => ['vinyl'], '--output' => $this->reportPath()])
            ->expectsOutputToContain("Unknown area 'vinyl'")
            ->assertExitCode(2);

        $this->assertFileDoesNotExist($this->reportPath());
    }

    public function test_an_unknown_check_is_rejected_and_names_the_valid_ones(): void
    {
        // Falling back to "run everything" would look like the option being ignored — and on a
        // cron, that is a report nobody asked for.
        $this->artisan('app:audit', ['--check' => ['spelling'], '--output' => $this->reportPath()])
            ->expectsOutputToContain("Unknown check 'spelling'")
            ->expectsOutputToContain('incomplete-albums')
            ->assertExitCode(2);

        $this->assertFileDoesNotExist($this->reportPath());
    }

    public function test_check_narrows_the_run_to_what_was_asked_for(): void
    {
        $this->rawFile('Mgła/01.mp3');

        $this->artisan('app:audit', ['--check' => ['mono'], '--output' => $this->reportPath()])
            ->assertExitCode(0);

        $report = file_get_contents($this->reportPath());

        $this->assertStringContainsString('Mono files', $report);
        // The encoding check was not asked for, so its finding is absent rather than clean.
        $this->assertStringNotContainsString('Windows-1252', $report);
    }

    public function test_cron_says_nothing_at_all_when_the_findings_have_not_moved(): void
    {
        /*
         * THE POINT OF THE MODE. A weekly job that reports the same standing findings every
         * Sunday becomes a mail rule, and once it is filtered the week that matters is filtered
         * with it. The first run establishes the baseline and speaks; the second is silent.
         */
        $this->rawFile('Mgła/01.mp3');

        $this->artisan('app:audit', ['--cron' => true, '--output' => $this->reportPath()])
            ->expectsOutputToContain('Library audit findings changed')
            ->assertExitCode(1);

        $this->artisan('app:audit', ['--cron' => true, '--output' => $this->reportPath()])
            ->doesntExpectOutputToContain('changed')
            ->assertExitCode(0);
    }

    public function test_cron_speaks_again_when_something_new_turns_up(): void
    {
        $this->rawFile('Mgła/01.mp3');
        $this->artisan('app:audit', ['--cron' => true, '--output' => $this->reportPath()])->assertExitCode(1);

        $this->rawFile('Godspeed/F♯/01.mp3');

        $this->artisan('app:audit', ['--cron' => true, '--output' => $this->reportPath()])
            ->expectsOutputToContain('(was 1)')
            ->assertExitCode(1);
    }

    public function test_cron_keeps_its_baseline_beside_the_report(): void
    {
        /*
         * Derived from the report path so two reports cannot share one baseline — and so a reader
         * can delete it to force a full alert.
         *
         * Exit 0 because this library is clean: a first run with no baseline reports what it FOUND
         * and stays quiet about the checks that are fine, so silence here is the correct alert and
         * the baseline is written all the same.
         */
        $this->artisan('app:audit', ['--cron' => true, '--output' => $this->reportPath()])->assertExitCode(0);

        $this->assertFileExists(AuditState::pathFor($this->reportPath()));
        $this->assertSame($this->root.'/library-audit.state.json', AuditState::pathFor($this->reportPath()));
    }

    public function test_a_plain_run_does_not_consume_the_crons_baseline(): void
    {
        /*
         * Otherwise looking at the report by hand would silence the next alert about the same
         * findings — the opposite of what an alert is for, and invisible when it happens.
         */
        $this->rawFile('Mgła/01.mp3');

        $this->artisan('app:audit', ['--output' => $this->reportPath()])->assertExitCode(0);
        $this->assertFileDoesNotExist(AuditState::pathFor($this->reportPath()));

        $this->artisan('app:audit', ['--cron' => true, '--output' => $this->reportPath()])
            ->expectsOutputToContain('Library audit findings changed')
            ->assertExitCode(1);
    }

    public function test_a_run_where_nothing_ran_says_so_instead_of_reporting_health(): void
    {
        // "0 findings" and "nobody looked" print the same headline, and this combination — an area
        // and a check that do not overlap — is a mis-typed flag rather than a healthy library.
        $this->artisan('app:audit', [
            '--area' => ['audiobooks'],
            '--check' => ['incomplete-albums'],
            '--output' => $this->reportPath(),
        ])
            ->expectsOutputToContain('No check ran')
            ->assertExitCode(0);
    }

    public function test_cron_treats_a_run_that_asked_nothing_as_an_alert(): void
    {
        // A scheduler whose flags do not overlap would otherwise exit 0 in silence for ever, which
        // is the exact failure this mode exists to make impossible.
        $this->artisan('app:audit', [
            '--cron' => true,
            '--area' => ['audiobooks'],
            '--check' => ['incomplete-albums'],
            '--output' => $this->reportPath(),
        ])
            ->expectsOutputToContain('ran no checks at all')
            ->assertExitCode(1);
    }

    public function test_cron_still_writes_the_report_on_a_quiet_week(): void
    {
        // The mode changes what is SAID, not what is written: a cron whose quiet weeks left no
        // document would have nothing to read on the week it finally spoke.
        $this->rawFile('Mgła/01.mp3');
        $this->artisan('app:audit', ['--cron' => true, '--output' => $this->reportPath()])->assertExitCode(1);
        unlink($this->reportPath());

        $this->artisan('app:audit', ['--cron' => true, '--output' => $this->reportPath()])->assertExitCode(0);

        $this->assertFileExists($this->reportPath());
    }
}
