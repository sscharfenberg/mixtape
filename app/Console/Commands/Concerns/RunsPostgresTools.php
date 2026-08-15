<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Run `pg_dump` / `pg_restore` without ever putting the database password somewhere another
 * process can read it.
 *
 * A TRAIT BECAUSE BOTH COMMANDS NEED THE SAME CARE, and password handling is the last thing
 * that should exist in two copies: the copy that gets a fix and the copy that does not are
 * indistinguishable until somebody reads `ps` on a shared box. `app:db-backup` and
 * `app:db-restore` are the only users, which is exactly when a trait beats a base class —
 * they have nothing else in common.
 *
 * THE THREE WAYS TO PASS A PASSWORD TO libpq, and why this picks the third:
 *
 *   - on the command line: readable by every user on the box, in `ps`;
 *   - in `PGPASSWORD`: readable out of /proc/<pid>/environ by anyone running as the same
 *     user, which on this host includes anything else www-data is running;
 *   - in a `PGPASSFILE`: a 0600 file, unlinked in a `finally` whatever happens.
 *
 * The tools also PROMPT when they cannot authenticate, and a prompt under systemd is a unit
 * that hangs until its timeout rather than one that fails — which is why the pass file's
 * escaping matters more than it looks.
 */
trait RunsPostgresTools
{
    /**
     * Run one libpq tool against `$connection`, answering whether it succeeded.
     *
     * `timeout(0)` because a dump or a restore takes as long as it takes and the systemd unit
     * already bounds it; `PGCONNECT_TIMEOUT` so an unreachable database fails in seconds
     * rather than sitting in a connect retry.
     *
     * @param  array<string, mixed>  $connection  a `config('database.connections.*')` array
     * @param  list<string>  $command  argv, the tool's own name first
     * @param  string  $label  what to call the tool if it fails
     */
    protected function postgres(array $connection, array $command, string $label): bool
    {
        $passFile = tempnam(sys_get_temp_dir(), 'mixtape-pgpass-');

        if ($passFile === false) {
            return $this->reportFailure('could not create a temporary password file');
        }

        chmod($passFile, 0600);
        file_put_contents($passFile, $this->passLine($connection));

        try {
            $result = Process::timeout(0)
                ->env(['PGPASSFILE' => $passFile, 'PGCONNECT_TIMEOUT' => '10'])
                ->run($command);

            if (! $result->successful()) {
                return $this->reportFailure(sprintf(
                    '%s failed (exit %d): %s',
                    $label,
                    $result->exitCode() ?? -1,
                    trim($result->errorOutput() ?: $result->output())
                ));
            }

            return true;
        } finally {
            unlink($passFile);
        }
    }

    /**
     * One `.pgpass` line: `host:port:database:user:password`.
     *
     * `\` AND `:` ARE ESCAPED because those are the two characters the format gives meaning
     * to. An unescaped colon in a password splits the line into the wrong fields, libpq finds
     * no match, and the tool falls back to prompting — which under systemd is a hang, not an
     * error, and reads as "the backup takes forever now" rather than as a bad password.
     *
     * @param  array<string, mixed>  $connection
     */
    private function passLine(array $connection): string
    {
        $escape = static fn (string $value): string => str_replace(['\\', ':'], ['\\\\', '\\:'], $value);

        return implode(':', [
            $escape((string) ($connection['host'] ?? '127.0.0.1')),
            $escape((string) ($connection['port'] ?? '5432')),
            $escape((string) ($connection['database'] ?? '')),
            $escape((string) ($connection['username'] ?? '')),
            $escape((string) ($connection['password'] ?? '')),
        ])."\n";
    }

    /**
     * Report a failure to the console AND the log, and answer false so a caller can `return`
     * it straight out of a boolean helper.
     *
     * BOTH DESTINATIONS ON PURPOSE: the console is the systemd journal for an unattended run,
     * and the log is what a person reads after the fact when the journal has rotated.
     *
     * NOT CALLED `fail()`, which is taken: `Illuminate\Console\Command::fail()` is public and
     * THROWS a ManuallyFailedException. Shadowing it would be a fatal error at class-load time
     * (PHP will not let a trait narrow a parent's visibility) — and had the signature happened
     * to line up, the worse outcome: a method that looks like the framework's and returns
     * where the framework's throws.
     */
    protected function reportFailure(string $message): bool
    {
        $name = $this->getName() ?? 'db';

        $this->error("{$name}: {$message}");
        Log::error("{$name}: {$message}");

        return false;
    }
}
