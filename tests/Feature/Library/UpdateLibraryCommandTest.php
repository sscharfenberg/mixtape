<?php

namespace Tests\Feature\Library;

use App\Mail\LibraryAreasEmpty;
use App\Mail\LibraryScanFailed;
use App\Mail\LibraryScanSkipped;
use App\Models\Track;
use App\Services\Library\Contracts\TagReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The thin `app:update` command: it narrates + orchestrates, and on a fatal
 * error logs, e-mails the configured alert address, and exits non-zero.
 */
class UpdateLibraryCommandTest extends TestCase
{
    use InteractsWithLibraryFiles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeLibraryRoot();
        $this->app->instance(TagReader::class, new FakeTagReader);
    }

    protected function tearDown(): void
    {
        $this->removeLibraryRoot();
        parent::tearDown();
    }

    public function test_happy_path_scans_and_sends_no_alert(): void
    {
        Mail::fake();
        $this->media('a/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Alb']);
        $this->media('a/02.mp3', ['hash' => 'h2', 'title' => 'Two', 'artist' => 'A', 'album' => 'Alb']);

        $this->artisan('app:update', ['--area' => ['music'], '--skip-cleanup' => true])
            ->assertExitCode(0);

        $this->assertSame(2, Track::count());
        Mail::assertNothingSent();
    }

    public function test_a_missing_area_path_aborts_and_emails_the_alert(): void
    {
        Mail::fake();
        config([
            'mixtape.library.paths.music' => '/no/such/mixtape/path',
            'mixtape.scan.alert_email' => 'ops@example.com',
        ]);

        $this->artisan('app:update', ['--area' => ['music'], '--skip-cleanup' => true])
            ->assertExitCode(1);

        Mail::assertSent(LibraryScanFailed::class);
    }

    public function test_failure_without_a_configured_address_sends_no_email(): void
    {
        Mail::fake();
        config([
            'mixtape.library.paths.music' => '/no/such/mixtape/path',
            'mixtape.scan.alert_email' => null,
        ]);

        $this->artisan('app:update', ['--area' => ['music'], '--skip-cleanup' => true])
            ->assertExitCode(1);

        Mail::assertNothingSent();
    }

    public function test_unknown_area_is_rejected(): void
    {
        $this->artisan('app:update', ['--area' => ['bogus']])
            ->assertExitCode(2); // Command::INVALID
    }

    public function test_skipped_files_trigger_a_summary_email_but_succeed(): void
    {
        Mail::fake();
        config(['mixtape.scan.alert_email' => 'ops@example.com']);
        $this->media('a/good.mp3', ['hash' => 'h1', 'title' => 'Good', 'artist' => 'A', 'album' => 'Alb']);
        $this->media('a/bad.mp3', ['__fail' => true]); // FakeTagReader throws for this

        // Skipped files are non-fatal — the run still succeeds (exit 0)…
        $this->artisan('app:update', ['--area' => ['music'], '--skip-cleanup' => true])
            ->assertExitCode(0);

        $this->assertSame(1, Track::count()); // the good file imported
        Mail::assertSent(LibraryScanSkipped::class, fn (LibraryScanSkipped $m) => $m->total === 1);
    }

    public function test_a_suspiciously_empty_area_alerts_and_exits_nonzero(): void
    {
        Mail::fake();
        config(['mixtape.scan.alert_email' => 'ops@example.com']);
        $this->media('a/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Alb']);
        $this->media('a/02.mp3', ['hash' => 'h2', 'title' => 'Two', 'artist' => 'A', 'album' => 'Alb']);

        // First run populates the DB.
        $this->artisan('app:update', ['--area' => ['music'], '--skip-cleanup' => true])->assertExitCode(0);
        $this->assertSame(2, Track::count());

        // The directory is now empty (a dropped mount) — the second run must
        // protect the rows AND alert.
        unlink($this->root.'/a/01.mp3');
        unlink($this->root.'/a/02.mp3');

        $this->artisan('app:update', ['--area' => ['music'], '--skip-cleanup' => true])
            ->assertExitCode(1);

        $this->assertSame(2, Track::count()); // rows left intact
        Mail::assertSent(LibraryAreasEmpty::class);
    }

    public function test_empty_area_still_exits_nonzero_without_a_configured_address(): void
    {
        Mail::fake();
        config(['mixtape.scan.alert_email' => null]);
        $this->media('a/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Alb']);

        $this->artisan('app:update', ['--area' => ['music'], '--skip-cleanup' => true])->assertExitCode(0);
        unlink($this->root.'/a/01.mp3');

        $this->artisan('app:update', ['--area' => ['music'], '--skip-cleanup' => true])
            ->assertExitCode(1);

        Mail::assertNothingSent();
    }

    public function test_default_run_skips_unconfigured_areas_without_failing(): void
    {
        // The reported scenario: a default (all-areas) run with no podcasts (and
        // no audiobooks) configured must succeed and send no failure e-mail.
        Mail::fake();
        $this->media('a/01.mp3', ['hash' => 'h1', 'title' => 'One', 'artist' => 'A', 'album' => 'Alb']);
        config([
            'mixtape.library.paths.audiobooks' => '',
            'mixtape.library.paths.podcast_shows' => '',
            'mixtape.scan.alert_email' => 'ops@example.com',
        ]);

        $this->artisan('app:update', ['--skip-cleanup' => true]) // no --area → all areas
            ->assertExitCode(0);

        $this->assertSame(1, Track::count());
        Mail::assertNothingSent();
    }
}
