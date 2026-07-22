<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesLibraryAreas;
use App\Mail\LibraryAreasEmpty;
use App\Mail\LibraryScanFailed;
use App\Mail\LibraryScanSkipped;
use App\Services\Library\LibraryCleanupService;
use App\Services\Library\LibraryScanService;
use App\Services\Library\ScanResult;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * `app:update` — scan the media library into the database.
 *
 * Deliberately thin: it only orchestrates + narrates (the "starting scan …
 * found X files …" lines to both console and the `library` log channel) and owns
 * the failure path (log critical + e-mail the configured address + exit
 * non-zero). All the domain logic lives in the services:
 *   1. LibraryCleanupService — delete OS/Samba junk from the shares.
 *   2. LibraryScanService     — the content-hash diff (insert/update/rename/
 *      delete + orphan prune), keeping stable ids across renames and re-tags.
 */
class UpdateLibrary extends Command
{
    use ResolvesLibraryAreas;

    protected $signature = 'app:update
                            {--area=* : Limit to one or more areas (music, audiobooks, podcast_shows). Default: all}
                            {--skip-cleanup : Skip the junk-file cleanup step}';

    protected $description = 'Scan the media library into the database (cleanup, then a content-hash diff)';

    /**
     * Run one scan: resolve areas, optionally clean, then run the content-hash diff
     * and narrate the totals. Skipped (unreadable) files and guard-tripped empty
     * areas are reported by e-mail + log but don't abort — an empty area still exits
     * non-zero so it's noticed. Any thrown error is the fatal path: log critical,
     * e-mail the alert, and return FAILURE so a broken nightly scan is never silent.
     */
    public function handle(LibraryCleanupService $cleanup, LibraryScanService $scanner): int
    {
        $areas = $this->resolveAreas();
        if ($areas === null) {
            return self::INVALID;
        }

        $startedAt = microtime(true);
        $this->narrate('Library scan started ('.$this->describeScope($areas).').');

        try {
            if (! $this->option('skip-cleanup')) {
                $removed = $cleanup->clean($areas);
                $this->narrate("Cleanup removed {$removed} junk file(s).");
            }

            $summary = $scanner->scan($areas, fn (string $line) => $this->narrate('  '.$line));

            $this->narrate(sprintf(
                'Library scan finished in %s — %d new, %d changed, %d moved, %d removed, %d skipped.',
                $this->elapsed($startedAt),
                $summary->inserted(),
                $summary->updated(),
                $summary->renamed(),
                $summary->deleted(),
                $summary->errors(),
            ));

            // Skipped (unreadable) files are non-fatal, but never silent: e-mail a
            // summary with each file + getID3's reason so they can be fixed.
            $skipped = array_merge(...array_map(fn (ScanResult $r) => $r->skipped, array_values($summary->results)));
            if ($skipped !== []) {
                $this->reportSkipped($skipped);
            }

            // A suspiciously-empty area (guard tripped) is not a crash — the data
            // was protected — but it needs attention, so alert + exit non-zero.
            $emptyAreas = array_filter($summary->results, fn (ScanResult $r) => $r->skippedEmpty);
            if ($emptyAreas !== []) {
                return $this->reportEmptyAreas($emptyAreas);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $message = 'Library scan aborted: '.$e->getMessage();
            Log::channel('library')->critical($message, ['exception' => $e]);
            $this->error($message);
            $this->notifyFailure($e);

            return self::FAILURE;
        }
    }

    /** Human-readable run time since $startedAt — `ms` under one second, else `s`. */
    private function elapsed(float $startedAt): string
    {
        $ms = (int) round((microtime(true) - $startedAt) * 1000);

        return $ms < 1000 ? "{$ms} ms" : round($ms / 1000, 1).' s';
    }

    /**
     * Build the fatal-scan alert from the exception — class, file:line, and a
     * bounded stack trace (the mail is plain-text; the untruncated detail stays in
     * the library log) — and hand it to sendAlert(), which owns the actual send and
     * the "no address configured" case.
     */
    private function notifyFailure(Throwable $e): void
    {
        $this->sendAlert(new LibraryScanFailed(
            summary: $e->getMessage(),
            exceptionClass: $e::class,
            location: $e->getFile().':'.$e->getLine(),
            host: gethostname() ?: 'unknown',
            trace: mb_substr($e->getTraceAsString(), 0, 4000),
        ), 'failure');
    }

    /**
     * E-mail an end-of-run summary of skipped (unreadable) files. Non-fatal, so
     * the exit code is unaffected — but the owner is told exactly what and why.
     *
     * @param  array<int, array{path: string, reason: string}>  $skipped
     */
    private function reportSkipped(array $skipped): void
    {
        $total = count($skipped);
        $this->warn("{$total} file(s) skipped as unreadable — logged to the library channel; sending summary.");

        // Cap the e-mailed list; the full set is always in storage/logs/library.log.
        $this->sendAlert(
            new LibraryScanSkipped(array_slice($skipped, 0, 200), $total, gethostname() ?: 'unknown'),
            'skipped-files',
        );
    }

    /**
     * Escalate guard-tripped areas (found empty while the DB has rows): log,
     * e-mail the alert, and return non-zero — without having touched the data.
     *
     * @param  array<string, ScanResult>  $results
     */
    private function reportEmptyAreas(array $results): int
    {
        $areas = array_values(array_map(
            fn (ScanResult $r) => ['area' => $r->type->value, 'rows' => $r->protectedRows],
            $results,
        ));
        $names = implode(', ', array_column($areas, 'area'));

        $message = "Library scan: area(s) [{$names}] found 0 files but still have DB entries — "
            .'pruning was skipped (likely a dropped mount). Data left intact.';
        Log::channel('library')->error($message);
        $this->error($message);

        $this->sendAlert(new LibraryAreasEmpty($areas, gethostname() ?: 'unknown'), 'empty-area');

        return self::FAILURE;
    }

    /**
     * Send an alert e-mail to the configured address, if any. The non-zero exit
     * and the log entry happen regardless; this only adds the e-mail on top.
     */
    private function sendAlert(Mailable $mailable, string $kind): void
    {
        $to = config('mixtape.scan.alert_email');
        if (empty($to)) {
            $this->warn("No mixtape.scan.alert_email configured — skipping {$kind} e-mail.");

            return;
        }

        try {
            Mail::to($to)->send($mailable);
            $this->line("Alert e-mail ({$kind}) sent to {$to}.");
        } catch (Throwable $mailError) {
            Log::channel('library')->error("Could not send {$kind} alert e-mail: ".$mailError->getMessage());
            $this->error("Could not send {$kind} alert e-mail: {$mailError->getMessage()}");
        }
    }
}
