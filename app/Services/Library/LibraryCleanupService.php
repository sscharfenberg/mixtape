<?php

namespace App\Services\Library;

use App\Enums\TrackType;
use App\Models\Collection;
use App\Models\Track;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Finder;

/**
 * Deletes the OS/Samba junk that clients scatter through the library shares —
 * `.DS_Store`, AppleDouble `._*`, `Thumbs.db`, Samba `.@__*` / `.smbdelete*`,
 * etc. (masks in `config('mixtape.scan.cleanup_masks')`). Run FIRST by
 * `app:update`, before anything is analysed, so those files can't be mistaken
 * for media or dirty a directory listing.
 *
 * Ported from the legacy `app:clean`, with one deliberate hardening: legacy shell
 * out to `find … -iname <mask>` with the mask interpolated *unquoted* into a
 * `/bin/sh -c` string, so a mask like `._*` was subject to shell glob expansion
 * in the process CWD before find ever saw it. Here it is pure PHP (Finder), so
 * the masks match only inside the library roots and never touch the CWD.
 *
 * It also sweeps the derived cover cache, which legacy wiped wholesale on a rescan —
 * `pruneCoverCache()`, and see its docblock for why this app can be surgical about it
 * instead. (Legacy's other derived artefact, download zips, still doesn't exist in v2.)
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

    /**
     * Delete cached cover JPEGs that can never be served again, and return how many
     * went. Two kinds:
     *
     *   · an entry whose id is not in the database any more — the track or album it was
     *     extracted for is gone (this is the historical junk: the scanner only started
     *     dropping its own entries on delete/re-tag alongside this method);
     *   · an album's SUPERSEDED mtime variants. `album-<id>-<mtime>.jpg` keys on the
     *     source image's mtime, so replacing the art in place leaves the old scaled
     *     copy behind, unreachable but on disk. Only the newest stamp per album can
     *     still match a request, so the rest go.
     *
     * Surgical rather than legacy's "wipe the whole cache on every rescan", because
     * these files cost real work to rebuild: a full sweep would re-extract from every
     * mp3 someone happens to view next, and this cache is the reason a page render
     * doesn't decode audio at all.
     *
     * Two queries and one directory listing, whatever the cache size — the id sets come
     * back as flat plucks and everything else is array work.
     */
    public function pruneCoverCache(): int
    {
        $directory = storage_path('app/private/covers');

        if (! is_dir($directory)) {
            return 0;
        }

        $entries = glob($directory.'/*.jpg') ?: [];

        if ($entries === []) {
            return 0;
        }

        $trackIds = Track::query()->pluck('id')->all();
        $collectionIds = Collection::query()->pluck('id')->all();
        $known = array_fill_keys(array_merge($trackIds, $collectionIds), true);

        // Highest mtime stamp seen per album id, so the current entry is spared.
        $newest = [];

        foreach ($entries as $entry) {
            [$id, $stamp] = $this->parseCacheName(basename($entry));

            if ($stamp !== null && (! isset($newest[$id]) || $stamp > $newest[$id])) {
                $newest[$id] = $stamp;
            }
        }

        $removed = 0;

        foreach ($entries as $entry) {
            [$id, $stamp] = $this->parseCacheName(basename($entry));

            $orphaned = $id === null || ! isset($known[$id]);
            $superseded = $stamp !== null && $id !== null && $stamp < ($newest[$id] ?? $stamp);

            if (($orphaned || $superseded) && @unlink($entry)) {
                $removed++;
                Log::channel('library')->info('cleanup: dropped stale cover cache entry '.basename($entry));
            }
        }

        return $removed;
    }

    /**
     * Split a cache filename into the id it belongs to and its mtime stamp (null for a
     * track entry, which has none).
     *
     * `<uuid>.jpg` is a track's; `album-<uuid>-<mtime>.jpg` is an album's. An
     * unrecognised name yields a null id, which the caller treats as orphaned — this
     * directory is ours alone, so anything else in it is not something to keep.
     *
     * @return array{0: string|null, 1: int|null}
     */
    private function parseCacheName(string $name): array
    {
        if (preg_match('/^album-([0-9a-f-]{36})-(\d+)\.jpg$/i', $name, $matches) === 1) {
            return [strtolower($matches[1]), (int) $matches[2]];
        }

        if (preg_match('/^([0-9a-f-]{36})\.jpg$/i', $name, $matches) === 1) {
            return [strtolower($matches[1]), null];
        }

        return [null, null];
    }
}
