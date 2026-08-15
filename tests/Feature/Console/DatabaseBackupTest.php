<?php

namespace Tests\Feature\Console;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * `app:db-backup` and `app:db-restore`.
 *
 * WHAT IS WORTH TESTING HERE IS THE REFUSALS. Neither command's happy path can be exercised
 * without a real PostgreSQL server and the libpq binaries, and neither exists in this suite —
 * it runs on sqlite. What CAN be pinned is every case where these commands decline to act, and
 * that is also where the value is: one writes to the drive that exists to survive a disk
 * failure, and the other can destroy a live instance's accounts, playlists and share links.
 *
 * `Process::fake()` STANDS IN FOR pg_dump AND pg_restore, so nothing here depends on Postgres
 * being installed on the machine running the tests. Where the real `pg_dump` would have written
 * a file, the test writes it — the fake intercepts the call and cannot.
 *
 * THE PATTERNS ARE WRAPPED IN `*` FOR A REASON. A fake is matched against Symfony's ESCAPED
 * command line, not against the array that was passed — so an array command arrives as
 * `'pg_dump' '--format=custom' …`, quotes and all, and a pattern anchored at the start
 * (`pg_dump*`) matches nothing. The failure is silent in the worst way: the fake simply does
 * not apply and the REAL binary runs, so the test passes or fails depending on whether the
 * machine happens to have PostgreSQL installed.
 */
class DatabaseBackupTest extends TestCase
{
    /** A scratch directory that looks like a mounted backup drive. */
    private function drive(): string
    {
        $drive = storage_path('framework/testing/db-backup-'.uniqid());
        File::ensureDirectoryExists($drive.'/db-backups');

        return $drive.'/db-backups';
    }

    public function test_it_refuses_to_back_up_when_the_drive_is_not_mounted(): void
    {
        /*
         * THE FAILURE MODE THAT LOOKS LIKE SUCCESS, and the reason the check exists. With
         * /mnt/usb unmounted, `mkdir -p /mnt/usb/db-backups` succeeds against the ROOT
         * filesystem — so a backup would run green for months, onto the disk it is supposed
         * to survive the loss of. Refusing needs the parent to be absent, not the directory.
         */
        Process::fake();

        $this->artisan('app:db-backup', ['--path' => '/nonexistent-mount/db-backups'])
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    public function test_it_discards_a_dump_pg_restore_cannot_read_back(): void
    {
        /*
         * "The file is 4 MB" and "Postgres can read this back" are different claims, and only
         * the second is a backup. An unreadable archive must not be left behind under a name
         * that says it worked.
         */
        $drive = $this->drive();
        $partial = $drive.'/mixtape-db-'.CarbonImmutable::now()->format('Y-m-d').'.dump.partial';

        Process::fake([
            '*pg_dump*' => Process::result(''),
            // The verification pass: empty output means the TOC did not parse.
            '*pg_restore*' => Process::result(''),
        ]);

        File::put($partial, 'not really an archive');

        $this->artisan('app:db-backup', ['--path' => $drive])->assertExitCode(1);

        $this->assertFileDoesNotExist($partial);
        $this->assertSame([], glob($drive.'/*.dump'));
    }

    public function test_a_verified_dump_is_renamed_into_place_and_old_ones_are_pruned(): void
    {
        $drive = $this->drive();
        $today = CarbonImmutable::now()->format('Y-m-d');

        // What pg_dump would have written — the fake cannot touch the filesystem.
        File::put($drive.'/mixtape-db-'.$today.'.dump.partial', 'archive bytes');

        // One inside the window and one outside it, by the date in the NAME rather than mtime.
        $keep = $drive.'/mixtape-db-'.CarbonImmutable::today()->subDays(5)->format('Y-m-d').'.dump';
        $drop = $drive.'/mixtape-db-'.CarbonImmutable::today()->subDays(40)->format('Y-m-d').'.dump';
        File::put($keep, 'recent');
        File::put($drop, 'ancient');

        Process::fake([
            '*pg_dump*' => Process::result(''),
            '*pg_restore*' => Process::result("; Archive created at 2026-08-15\n123; TABLE users"),
        ]);

        $this->artisan('app:db-backup', ['--path' => $drive, '--retention-days' => 30])
            ->assertExitCode(0);

        $this->assertFileExists($drive.'/mixtape-db-'.$today.'.dump');
        $this->assertFileDoesNotExist($drive.'/mixtape-db-'.$today.'.dump.partial');
        $this->assertFileExists($keep);
        $this->assertFileDoesNotExist($drop);
    }

    public function test_keep_all_writes_the_dump_and_prunes_nothing(): void
    {
        $drive = $this->drive();
        $today = CarbonImmutable::now()->format('Y-m-d');
        $ancient = $drive.'/mixtape-db-'.CarbonImmutable::today()->subDays(400)->format('Y-m-d').'.dump';

        File::put($drive.'/mixtape-db-'.$today.'.dump.partial', 'archive bytes');
        File::put($ancient, 'ancient');

        Process::fake([
            '*pg_dump*' => Process::result(''),
            '*pg_restore*' => Process::result('123; TABLE users'),
        ]);

        $this->artisan('app:db-backup', ['--path' => $drive, '--keep-all' => true])->assertExitCode(0);

        $this->assertFileExists($ancient);
    }

    public function test_restore_refuses_a_file_that_is_not_there(): void
    {
        $this->artisan('app:db-restore', ['--file' => '/nope/missing.dump'])->assertExitCode(1);
    }

    public function test_restore_refuses_when_the_directory_holds_no_dumps(): void
    {
        // Rather than dropping into an interactive picker with nothing in it.
        $this->artisan('app:db-restore', ['--path' => $this->drive()])->assertExitCode(1);
    }

    public function test_restore_refuses_an_archive_pg_restore_cannot_read(): void
    {
        /*
         * CHECKED BEFORE ANYTHING IS DROPPED. `pg_restore --clean` drops each object before
         * recreating it, so discovering a truncated dump halfway through would leave a
         * database holding neither the old data nor the new.
         */
        $drive = $this->drive();
        $file = $drive.'/mixtape-db-2026-08-01.dump';
        File::put($file, 'junk');

        Process::fake(['*pg_restore*' => Process::result('')]);

        $this->artisan('app:db-restore', ['--file' => $file, '--force' => true])->assertExitCode(1);

        // It refused at the list pass — the destructive call never happened.
        Process::assertRan(fn ($process) => in_array('--list', $process->command, true));
        Process::assertDidntRun(fn ($process) => in_array('--clean', $process->command, true));
    }

    public function test_restore_will_not_take_force_in_production(): void
    {
        /*
         * THE GUARD THAT MATTERS MOST. `--force` exists so a development box can be reset from
         * a script; on the live instance an unattended restore is precisely what must not be
         * possible, because what it destroys — share links already handed to people — cannot be
         * reissued to whoever is holding them.
         */
        $this->app->detectEnvironment(fn () => 'production');

        $drive = $this->drive();
        $file = $drive.'/mixtape-db-2026-08-01.dump';
        File::put($file, 'archive');

        Process::fake(['*pg_restore*' => Process::result('123; TABLE users')]);

        $this->artisan('app:db-restore', ['--file' => $file, '--force' => true])->assertExitCode(1);

        Process::assertDidntRun(fn ($process) => in_array('--clean', $process->command, true));
    }
}
