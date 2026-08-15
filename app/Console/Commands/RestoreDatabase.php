<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPostgresTools;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Replace the contents of the database with one of `app:db-backup`'s dumps.
 *
 * THE MOST DESTRUCTIVE COMMAND IN THIS PROJECT, and the only one that can undo work nobody can
 * redo. The production instance is live and has real accounts on it: every playlist, listen,
 * bookmark and SHARE LINK minted since the chosen dump is gone when this finishes, and a share
 * id is a capability somebody else is holding — a link that stops working cannot be reissued to
 * whoever has it. Hence the guards below, which are deliberately more than a y/n.
 *
 * THE ARCHIVE IS VERIFIED BEFORE ANYTHING IS DROPPED, and that ordering is the whole design.
 * `pg_restore --clean` drops each object before recreating it, so a truncated or unreadable
 * dump discovered halfway through leaves a database with neither the old data nor the new. A
 * `--list` pass first costs milliseconds and turns that into a refusal that changes nothing.
 *
 * TYPING THE DATABASE NAME, not confirming a prompt. "Are you sure? [y/N]" is answered yes by
 * reflex, and the mistake this is guarding against is not doubt — it is restoring the right
 * dump into the wrong database, which a yes/no question cannot catch because it never asks
 * WHICH.
 *
 * `--force` IS REFUSED IN PRODUCTION. It exists so a development box can be reset from a
 * script; on the live instance the only way through is a person reading the name and typing
 * it. That asymmetry is the point — an unattended restore is exactly the thing that should not
 * be possible there.
 *
 * PUT THE SITE IN MAINTENANCE FIRST (`mt artisan down --prod`). Not enforced here, because a
 * restore is also how you recover an instance that is already down and this should not depend
 * on the app being reachable — but a restore under live traffic will fight open connections
 * over the objects it is dropping.
 */
class RestoreDatabase extends Command
{
    use RunsPostgresTools;

    /** @var string */
    protected $signature = 'app:db-restore
        {--file= : Restore this dump instead of choosing one from the backup directory}
        {--path= : Look for dumps here instead of config("mixtape.backup.path")}
        {--force : Skip the typed confirmation. Refused when APP_ENV=production}';

    /** @var string */
    protected $description = 'Restore the database from an app:db-backup dump. Interactive, and destructive';

    /**
     * Choose a dump, prove it is readable, make the operator say which database, then replace
     * it — refusing at the first of those that does not hold.
     */
    public function handle(): int
    {
        $connection = config('database.connections.pgsql');

        if (! is_array($connection)) {
            $this->reportFailure('no pgsql connection configured');

            return self::FAILURE;
        }

        $file = $this->chooseFile();

        if ($file === null) {
            return self::FAILURE;
        }

        // BEFORE the confirmation as well as before the restore: there is no sense asking
        // somebody to authorise replacing their database from a file that cannot be read.
        if (! $this->readable($file)) {
            return self::FAILURE;
        }

        $database = (string) $connection['database'];

        if (! $this->confirmed($database, $file)) {
            return self::FAILURE;
        }

        $this->info("Restoring {$database} from ".basename($file));

        /*
         * `--clean --if-exists` drops each object before recreating it, which is what makes
         * this a replacement rather than a merge — without it a restore over a populated
         * database fails on every existing table and leaves a mess of half-applied rows.
         * `--no-owner --no-privileges` for the reason the dump was written with them: the
         * roles on the restoring box are not necessarily the roles on the dumping one.
         * `--single-transaction` so a failure rolls the whole thing back rather than leaving
         * the database part-restored.
         */
        $restored = $this->postgres($connection, [
            'pg_restore',
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            '--single-transaction',
            '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($connection['port'] ?? '5432'),
            '--username='.(string) ($connection['username'] ?? ''),
            '--dbname='.$database,
            $file,
        ], 'pg_restore');

        if (! $restored) {
            return self::FAILURE;
        }

        $this->info("Restored {$database}.");
        // The library half is derived, so a restore of an older dump can leave it describing
        // files that have since changed on disk. Saying so is cheaper than a puzzled hour.
        $this->line('Run `php artisan app:update` if the media library has changed since this dump.');

        return self::SUCCESS;
    }

    /**
     * The dump to restore: the one named by `--file`, or one picked from the directory.
     *
     * NEWEST FIRST, because that is what somebody recovering from a failure wants and a list
     * sorted the other way invites picking the oldest by reflex.
     */
    private function chooseFile(): ?string
    {
        $explicit = $this->option('file');

        if ($explicit !== null) {
            if (is_file((string) $explicit)) {
                return (string) $explicit;
            }

            $this->reportFailure("no such file: {$explicit}");

            return null;
        }

        $directory = (string) ($this->option('path') ?? config('mixtape.backup.path'));
        $files = glob($directory.'/mixtape-db-*.dump') ?: [];

        if ($files === []) {
            $this->reportFailure("no dumps found in {$directory}");

            return null;
        }

        rsort($files);

        $labels = [];

        foreach ($files as $path) {
            $labels[$path] = basename($path).'  ('.$this->human((int) filesize($path)).')';
        }

        return select(label: 'Which dump?', options: $labels, scroll: 15);
    }

    /**
     * Prove the archive parses before anything is dropped — see the class note on ordering.
     */
    private function readable(string $file): bool
    {
        $result = Process::timeout(0)->run(['pg_restore', '--list', $file]);

        if (! $result->successful() || trim($result->output()) === '') {
            return $this->reportFailure("{$file} is not a readable pg_dump archive — refusing to restore from it");
        }

        return true;
    }

    /**
     * Make the operator name the database they are about to replace.
     *
     * See the class note: `--force` is for a development box being reset by a script, and is
     * refused outright in production so that an unattended restore of the live instance is not
     * something this command can do at all.
     */
    private function confirmed(string $database, string $file): bool
    {
        if ($this->option('force')) {
            if (app()->environment('production')) {
                return $this->reportFailure('--force is not honoured in production — run it interactively and type the database name');
            }

            return true;
        }

        $this->warn("This REPLACES the contents of '{$database}' with ".basename($file).'.');
        $this->warn('Everything written since that dump is lost, including share links already sent.');

        if (text(label: 'Type the database name to continue') !== $database) {
            $this->line('Names did not match — nothing was changed.');

            return false;
        }

        return true;
    }

    /** Bytes as something a human reads in a picker. */
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
