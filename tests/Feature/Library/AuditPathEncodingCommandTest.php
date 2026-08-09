<?php

namespace Tests\Feature\Library;

use Tests\TestCase;

/**
 * `app:encoding` — the command wiring around the audit.
 *
 * What the audit FINDS is covered by PathEncodingAuditTest; this covers the things only the
 * command decides: where the file lands, what the console says, and the exit codes — including
 * the one that is a deliberate choice rather than an oversight (findings are not a failure).
 */
class AuditPathEncodingCommandTest extends TestCase
{
    use InteractsWithLibraryFiles;

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

    /** Somewhere inside the temp root to write a report to, so nothing lands in the repo. */
    private function reportPath(): string
    {
        return $this->root.'/report.md';
    }

    public function test_it_writes_a_report_naming_what_has_to_be_renamed(): void
    {
        $this->rawFile('Mgla/Mgła - Age of Excuse.mp3');
        $this->rawFile('Radiohead/01 - Airbag.mp3');

        $this->artisan('app:encoding', ['--output' => $this->reportPath()])
            ->assertExitCode(0);

        $report = file_get_contents($this->reportPath());

        $this->assertStringContainsString('# Windows-1252 path audit', $report);
        $this->assertStringContainsString('Mgła - Age of Excuse.mp3', $report);
        $this->assertStringContainsString('LATIN SMALL LETTER L WITH STROKE', $report);
        // The clean file is counted but never listed — a work list of things that are fine is noise.
        $this->assertStringNotContainsString('Airbag', $report);
    }

    public function test_it_still_writes_a_report_when_there_is_nothing_to_fix(): void
    {
        // The empty report is the point of re-running: it is how you confirm the work is done.
        $this->rawFile('Radiohead/01 - Airbag.mp3');

        $this->artisan('app:encoding', ['--output' => $this->reportPath()])
            ->expectsOutputToContain('Nothing to fix')
            ->assertExitCode(0);

        $this->assertStringContainsString('## Nothing to do', file_get_contents($this->reportPath()));
    }

    public function test_findings_are_reported_rather_than_treated_as_a_failure(): void
    {
        /*
         * A deliberate choice, not an oversight: this is a report, not a linter, and a
         * collection may legitimately sit with known offenders for as long as its owner likes.
         * Exiting non-zero would turn a standing list into a nightly cron mail.
         */
        $this->rawFile('Mgła/01.mp3');

        $this->artisan('app:encoding', ['--output' => $this->reportPath()])
            ->assertExitCode(0);
    }

    public function test_it_says_what_it_found_without_making_the_reader_open_the_file(): void
    {
        $this->rawFile('Godspeed/[1997] F♯ A♯ ∞/01.mp3');
        $this->rawFile('Godspeed/[1997] F♯ A♯ ∞/02.mp3');

        $this->artisan('app:encoding', ['--output' => $this->reportPath()])
            ->expectsOutputToContain('2 file(s) scanned, 2 path(s)')
            ->expectsOutputToContain('2 distinct character(s), 1 thing(s) to rename')
            ->expectsOutputToContain('Report written to')
            ->assertExitCode(0);
    }

    public function test_it_defaults_to_the_directory_the_command_was_run_from(): void
    {
        // A throwaway working file belongs next to whoever ran the command, not in storage.
        $this->rawFile('Mgła/01.mp3');
        $previous = getcwd();
        chdir($this->root);

        try {
            $this->artisan('app:encoding')->assertExitCode(0);
            $this->assertFileExists($this->root.'/windows-1252-paths.md');
        } finally {
            chdir($previous);
        }
    }

    public function test_an_unwritable_destination_fails_loudly_instead_of_reporting_success(): void
    {
        // Silently "succeeding" with no file is the one outcome that would waste real time.
        $this->rawFile('Mgła/01.mp3');

        $this->artisan('app:encoding', ['--output' => $this->root.'/no/such/dir/report.md'])
            ->expectsOutputToContain('Could not write the report')
            ->assertExitCode(1);
    }

    public function test_an_unknown_area_is_rejected_before_anything_is_scanned(): void
    {
        $this->artisan('app:encoding', ['--area' => ['vinyl'], '--output' => $this->reportPath()])
            ->expectsOutputToContain("Unknown area 'vinyl'")
            ->assertExitCode(2);

        $this->assertFileDoesNotExist($this->reportPath());
    }
}
