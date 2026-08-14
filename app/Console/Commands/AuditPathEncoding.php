<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesLibraryAreas;
use App\Services\Library\PathEncodingAudit;
use App\Services\Library\PathEncodingReport;
use Illuminate\Console\Command;

/**
 * `app:encoding` — list the library paths a Windows-1252 playlist export cannot name, as a
 * Markdown file to work through.
 *
 * The export modal warns a reader per playlist; this is the view over the WHOLE collection, so
 * the handful of offenders can be renamed once rather than warned about forever.
 * Thin, like the other library commands: the walk lives in PathEncodingAudit and the document in
 * PathEncodingReport.
 *
 * IT WRITES A FILE RATHER THAN PRINTING. The findings are a work list — rename something, come
 * back, cross it off — and terminal scrollback is a poor place to keep one, especially since
 * about half of what it finds is invisible on screen and has to be read by code point.
 */
class AuditPathEncoding extends Command
{
    use ResolvesLibraryAreas;

    /** Written next to wherever the command was run, which is where a throwaway working file belongs. */
    private const DEFAULT_FILENAME = 'windows-1252-paths.md';

    protected $signature = 'app:encoding
                            {--area=* : Limit to one or more areas (music, audiobooks). Default: all}
                            {--output= : Where to write the report. Default: '.self::DEFAULT_FILENAME.' in the current directory}';

    protected $description = 'Report library paths a Windows-1252 playlist export cannot name';

    /**
     * Resolve the areas, walk them, write the report, and say what was found.
     *
     * ALWAYS EXITS 0 WHEN IT RAN. Findings are this command's normal output, not a failure — it
     * is a report, not a linter, and a collection can legitimately sit with known offenders for
     * as long as its owner likes. Only an unusable `--area` (INVALID) or a report that could not
     * be written (FAILURE) are errors, because in both cases the command did not do its job.
     */
    public function handle(PathEncodingAudit $audit): int
    {
        $areas = $this->resolveAreas();
        if ($areas === null) {
            return self::INVALID;
        }

        $this->narrate('Encoding audit started ('.$this->describeScope($areas).').');

        $result = $audit->scan($areas);
        $document = PathEncodingReport::render($result, now()->format('Y-m-d H:i'));

        $target = $this->option('output') ?: self::DEFAULT_FILENAME;

        if (@file_put_contents($target, $document) === false) {
            $this->error("Could not write the report to '{$target}' — check the path exists and is writable.");

            return self::FAILURE;
        }

        $this->narrate(sprintf(
            'Encoding audit finished — %s file(s) scanned, %d path(s) a Windows-1252 export cannot name.',
            number_format($result->scanned),
            count($result->findings),
        ));

        if ($result->isClean()) {
            $this->info('Nothing to fix: every path in the library survives Windows-1252.');
        } else {
            $this->line(sprintf(
                '  %d distinct character(s), %d thing(s) to rename.',
                count($result->offenderCounts()),
                count($result->renameTargets()),
            ));
        }

        $this->line('Report written to '.(realpath($target) ?: $target));

        return self::SUCCESS;
    }
}
