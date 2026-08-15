<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPostgresTools;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Dump the database to the backup drive, prove the dump is readable, and prune old ones.
 *
 * WHAT THIS PROTECTS is the half of the database that is not derived from disk: accounts,
 * invites, playlists, listening history, player state, audiobook bookmarks and share links.
 * The library itself — tracks, albums, artists, genres, authors, narrators — is rebuilt from
 * /var/media by `app:update` in under a minute, so it is not what a dump is for. That is why
 * these files are small enough to keep a month of.
 *
 * ONE FILE PER RUN, IN POSTGRES'S CUSTOM FORMAT (`--format=custom`), which is compressed
 * already and is the only format `pg_restore` can restore selectively or in parallel from.
 * A plain `.sql` dump would need a second pass to compress and could then only ever be
 * replayed whole, through `psql`.
 *
 * IT IS WRITTEN AS `.partial` AND RENAMED, and verified in between — the same shape
 * mixtape-media-backup.sh uses, for the same reason. A dump interrupted by a full disk, a
 * pulled USB cable or a reboot is a FILE THAT EXISTS AND DOES NOT RESTORE, and the rename is
 * what makes a name in that directory mean "this one worked". Verification is
 * `pg_restore --list`, which reads the archive's table of contents: cheap, and the difference
 * between "bytes were written" and "Postgres can read them back".
 *
 * THE PASSWORD NEVER APPEARS IN `ps`. It goes in a 0600 `PGPASSFILE` rather than `PGPASSWORD`,
 * which would be readable out of /proc/<pid>/environ for anyone running as the same user, and
 * never on the command line, which is world-readable on any Linux box.
 *
 * Designed to run unattended from mixtape-db-backup.service. Progress goes to stdout, which
 * for a systemd oneshot is the journal (`journalctl -u mixtape-db-backup`); a failure both
 * logs and exits non-zero, so the unit's OnFailure= reporter fires.
 */
class BackupDatabase extends Command
{
    use RunsPostgresTools;

    /** @var string */
    protected $signature = 'app:db-backup
        {--path= : Write dumps here instead of config("mixtape.backup.path")}
        {--retention-days= : Override the retention window for this run}
        {--keep-all : Write the dump and prune nothing}';

    /** @var string */
    protected $description = 'Dump the database to the backup drive in Postgres custom format, verify it, and prune dumps past the retention window';

    /** Dated, sortable, and the name the prune pass parses the date back out of. */
    private const PREFIX = 'mixtape-db-';

    /**
     * Dump, verify, rename, prune — in that order, because each step only makes sense if the
     * one before it succeeded.
     */
    public function handle(): int
    {
        $connection = config('database.connections.pgsql');

        if (! is_array($connection)) {
            $this->reportFailure('no pgsql connection configured');

            return self::FAILURE;
        }

        $directory = (string) ($this->option('path') ?? config('mixtape.backup.path'));
        $retention = (int) ($this->option('retention-days') ?? config('mixtape.backup.retention_days', 30));

        if (! $this->ensureDirectory($directory)) {
            return self::FAILURE;
        }

        $final = $directory.'/'.self::PREFIX.CarbonImmutable::now()->format('Y-m-d').'.dump';
        $partial = $final.'.partial';

        $this->info("Dumping {$connection['database']} to ".basename($final));

        if (! $this->dump($connection, $partial)) {
            @unlink($partial);

            return self::FAILURE;
        }

        if (! $this->verify($partial)) {
            // Kept out of the way rather than left looking like a good dump: the name without
            // `.partial` is the only thing that says "this one restores".
            @unlink($partial);

            return self::FAILURE;
        }

        if (! rename($partial, $final)) {
            $this->reportFailure("could not move {$partial} into place");

            return self::FAILURE;
        }

        chmod($final, 0640);
        $this->info('Wrote '.$this->human((int) filesize($final)).' to '.basename($final));

        if (! $this->option('keep-all')) {
            $this->prune($directory, $retention);
        }

        return self::SUCCESS;
    }

    /**
     * Make sure the target directory exists and can be written to.
     *
     * A MISSING DRIVE IS A FAILURE, NOT A REASON TO CREATE THE PATH. If /mnt/usb is not
     * mounted then `mkdir -p /mnt/usb/db-backups` cheerfully succeeds on the root filesystem,
     * and the backup then runs green for months onto the disk it exists to survive — the
     * failure mode being guarded against here is a green light, not a red one. The unit names
     * the mount in RequiresMountsFor= so it never gets this far; this is the second line.
     */
    private function ensureDirectory(string $directory): bool
    {
        $parent = dirname($directory);

        if (! is_dir($parent)) {
            return $this->reportFailure("the backup drive is not mounted: {$parent} does not exist");
        }

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            return $this->reportFailure("could not create {$directory}");
        }

        return is_writable($directory) ? true : $this->reportFailure("{$directory} is not writable");
    }

    /**
     * Run `pg_dump` into `$target`.
     *
     * `--no-owner --no-privileges` so the dump restores into whatever role does the restoring
     * — without them a dump taken as one database user cannot be replayed into a development
     * database owned by another, which is the most common reason to want one of these.
     *
     * @param  array<string, mixed>  $connection
     */
    private function dump(array $connection, string $target): bool
    {
        return $this->postgres($connection, [
            'pg_dump',
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--file='.$target,
            '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($connection['port'] ?? '5432'),
            '--username='.(string) ($connection['username'] ?? ''),
            (string) $connection['database'],
        ], 'pg_dump');
    }

    /**
     * Prove the archive parses, by reading its table of contents.
     *
     * THE WHOLE POINT OF THE STEP: "the file is 4 MB" and "Postgres can read this back" are
     * different claims, and only the second one is a backup. `--list` touches no database and
     * costs milliseconds on a dump this size.
     */
    private function verify(string $file): bool
    {
        $result = Process::timeout(0)->run(['pg_restore', '--list', $file]);

        if (! $result->successful() || trim($result->output()) === '') {
            return $this->reportFailure('the dump could not be read back by pg_restore — discarding it');
        }

        return true;
    }

    /**
     * Delete dumps whose dated filename is older than the window.
     *
     * BY THE NAME, NOT BY MTIME: a file copied or restored from elsewhere carries a new mtime
     * and would be deleted early or kept forever depending on which way the copy went, where
     * the date in the name is the date of the data.
     */
    private function prune(string $directory, int $retentionDays): void
    {
        $cutoff = CarbonImmutable::today()->subDays($retentionDays);

        foreach (glob($directory.'/'.self::PREFIX.'*.dump') ?: [] as $file) {
            if (! preg_match('/'.preg_quote(self::PREFIX, '/').'(\d{4}-\d{2}-\d{2})\.dump$/', basename($file), $matches)) {
                continue;
            }

            if (CarbonImmutable::createFromFormat('Y-m-d', $matches[1])->startOfDay()->lt($cutoff)) {
                unlink($file);
                $this->line('Pruned '.basename($file));
            }
        }

        // Left behind by a run that died between writing and renaming. Cleared on the next
        // run rather than at the point of failure, so the file is still there to look at if
        // somebody investigates in between.
        foreach (glob($directory.'/'.self::PREFIX.'*.dump.partial') ?: [] as $stale) {
            unlink($stale);
            $this->line('Removed stale '.basename($stale));
        }
    }

    /** Bytes as something a human reads in a journal line. */
    private function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1).' '.$unit;
            }
            $bytes = intdiv($bytes, 1024);
        }

        return $bytes.' B';
    }
}
