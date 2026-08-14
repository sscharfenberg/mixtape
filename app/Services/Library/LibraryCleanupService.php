<?php

namespace App\Services\Library;

use App\Enums\TrackType;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Finder;

/**
 * Deletes the OS/Samba junk that clients scatter through the library shares —
 * `.DS_Store`, AppleDouble `._*`, `Thumbs.db`, Samba `.@__*` / `.smbdelete*`,
 * etc. (masks in `config('mixtape.scan.cleanup_masks')`). Run FIRST by
 * `app:update`, before anything is analysed, so those files can't be mistaken
 * for media or dirty a directory listing.
 *
 * PURE PHP (Finder), NOT A SHELL OUT, and that is a hardening rather than a preference:
 * `find … -iname <mask>` with the mask interpolated *unquoted* into a `/bin/sh -c` string
 * leaves a mask like `._*` subject to shell glob expansion in the process CWD before find
 * ever sees it. Through Finder the masks match only inside the library roots and never touch
 * the CWD. Only the share junk is removed here; the derived cover cache is swept by the class
 * that owns it (CoverService::pruneCache, called alongside this).
 */
final class LibraryCleanupService
{
    /**
     * @param  TrackType[]  $areas  which library areas to sweep
     * @return int number of junk files removed
     */
    public function clean(array $areas): int
    {
        $masks = array_values(array_filter((array) config('mixtape.scan.cleanup_masks', [])));
        if ($masks === []) {
            return 0;
        }

        $removed = 0;

        foreach ($areas as $type) {
            $root = trim((string) config('mixtape.library.paths.'.$type->libraryPathKey()));

            if ($root === '') {
                Log::channel('library')->info("cleanup: {$type->value} not configured — skipped");

                continue;
            }

            if (! is_dir($root)) {
                Log::channel('library')->warning("cleanup: skipped {$type->value} — path not found: {$root}");

                continue;
            }

            $finder = (new Finder)
                ->files()
                ->in($root)
                ->ignoreDotFiles(false) // the junk is mostly dotfiles (._*, .DS_Store, .@__*)
                ->ignoreVCS(false)
                ->followLinks()
                ->name($masks); // multiple globs are OR-matched by Finder

            foreach ($finder as $file) {
                $path = $file->getPathname();

                if (@unlink($path)) {
                    $removed++;
                    Log::channel('library')->info("cleanup: removed {$path}");
                } else {
                    Log::channel('library')->warning("cleanup: could not remove {$path}");
                }
            }
        }

        return $removed;
    }
}
