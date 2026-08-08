<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesLibraryAreas;
use App\Services\Library\LibraryCleanupService;
use App\Services\Media\CoverService;
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
                            {--area=* : Limit to one or more areas (music, audiobooks). Default: all}';

    protected $description = 'Delete OS/Samba junk files from the media library shares';

    /**
     * Resolve the target area(s), sweep their shares of OS/Samba junk, and narrate
     * the count removed. Returns INVALID on an unknown `--area`; otherwise SUCCESS —
     * cleanup is best-effort and has no failure path of its own.
     */
    public function handle(LibraryCleanupService $cleanup, CoverService $covers): int
    {
        $areas = $this->resolveAreas();
        if ($areas === null) {
            return self::INVALID;
        }

        $this->narrate('Library cleanup started ('.$this->describeScope($areas).').');
        $removed = $cleanup->clean($areas);
        $this->narrate("Cleanup removed {$removed} junk file(s).");

        // The cover cache is app storage, not share content, so it is swept whole
        // regardless of `--area`: an entry is kept or dropped on whether its id is
        // still in the database, which no area scoping would change. Owned by
        // CoverService, since only that class knows the cache's layout.
        $cache = $covers->pruneCache();
        $this->narrate("Cleanup dropped {$cache['removed']} stale cover cache entr(y|ies).");

        if ($cache['refused'] > 0) {
            $this->narrate(
                "Could not delete {$cache['refused']} entr(y|ies) — check the library log; artisan is "
                .'probably not running as the cache owner.'
            );
        }

        return self::SUCCESS;
    }
}
