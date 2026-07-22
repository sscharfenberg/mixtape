<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesLibraryAreas;
use App\Services\Library\LibraryCleanupService;
use Illuminate\Console\Command;

/**
 * `app:clean` — delete the OS/Samba junk (`._*`, `.DS_Store`, `Thumbs.db`,
 * `.@__*`, …) that clients scatter through the library shares.
 *
 * `app:update` already runs this step first (unless `--skip-cleanup`); this is
 * the same cleanup as a standalone command, for sweeping the shares without a
 * full scan. Thin, like the other library commands: all logic is in
 * LibraryCleanupService.
 */
class CleanLibrary extends Command
{
    use ResolvesLibraryAreas;

    protected $signature = 'app:clean
                            {--area=* : Limit to one or more areas (music, audiobooks, podcast_shows). Default: all}';

    protected $description = 'Delete OS/Samba junk files from the media library shares';

    /**
     * Resolve the target area(s), sweep their shares of OS/Samba junk, and narrate
     * the count removed. Returns INVALID on an unknown `--area`; otherwise SUCCESS —
     * cleanup is best-effort and has no failure path of its own.
     */
    public function handle(LibraryCleanupService $cleanup): int
    {
        $areas = $this->resolveAreas();
        if ($areas === null) {
            return self::INVALID;
        }

        $this->narrate('Library cleanup started ('.$this->describeScope($areas).').');
        $removed = $cleanup->clean($areas);
        $this->narrate("Cleanup removed {$removed} junk file(s).");

        return self::SUCCESS;
    }
}
