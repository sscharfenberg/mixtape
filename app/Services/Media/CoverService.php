<?php

namespace App\Services\Media;

use App\Models\Collection;
use App\Models\Track;
use getID3;
use Illuminate\Support\Facades\Log;

/**
 * Cover art for a track, extracted on demand and cached as a JPEG.
 *
 * Ported from the legacy `CoverService`, and for the same reason: cover bytes do
 * not belong in the database (the scanner only records the boolean
 * `tracks.cover`), and re-reading a 12k-file collection's embedded art on every
 * page view is wasteful. So the first request for a cover pays for the
 * extraction and every later one is served from a cached file, keyed by the
 * track id — which is a content hash of the audio, so a re-tagged file keeps its
 * cached cover and a genuinely different file cannot collide with it.
 *
 * Two sources, in the order a ripper writes them: the file's own embedded
 * picture, else an album image sitting beside it in the directory (the configured
 * `mixtape.covers.folder_images` names, matched case-insensitively — see
 * `directoryImage`, and the config for why a single exact name was not enough).
 * Legacy checked the two in this same order.
 *
 * At the ALBUM grain (`albumPath`) that order is deliberately inverted — the folder
 * image wins there, because an album whose files each carry their own inline picture
 * would otherwise get a thumbnail decided by sort order. See that method.
 *
 * WHICH directory image an album has is no longer worked out per request: the scanner
 * resolves it once (`directoryImage`, called from
 * LibraryScanService::syncCollectionCovers) and records the path in
 * `collections.cover_path`, so a listing answers "is there artwork?" from a column.
 * The resolution still lives here, and still runs live when a recorded path has gone
 * stale — one implementation, two callers.
 *
 * Deliberately NOT a queued job: the extraction is a single getID3 read of one
 * file, the request that needs it is the one that pays, and a missing cover must
 * degrade to a placeholder rather than to "come back later".
 */
final class CoverService
{
    /** Cache lives outside the public disk on purpose — covers are served through an auth-gated route, never a URL under /storage. */
    private const CACHE_DIR = 'covers';

    /**
     * What counts as an image when scanning an album directory. Kept to what GD can
     * decode (see writeCache), since anything else would resolve to a cover the app
     * then fails to render.
     */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'gif'];

    /** Memoised answer to "can this process delete from the cache?" — see cacheIsWritable(). */
    private ?bool $cacheWritable = null;

    /**
     * Whether this track can show a cover at all — used by the controller to
     * decide between a real image URL and the placeholder, WITHOUT extracting
     * anything. `tracks.cover` answers the embedded case from the database; the
     * directory image costs one directory read. Both are cheap enough for a page
     * render, and getting it right here is what keeps a broken <img> off the page.
     */
    public function exists(Track $track): bool
    {
        return $track->cover || $this->directoryImage($track->absolutePath()) !== null;
    }

    /**
     * The absolute path to this track's cached cover JPEG, extracting it first if
     * it isn't cached yet. Null when the track has no cover from either source, or
     * when the file/image turned out to be unreadable — the caller renders the
     * placeholder either way, so an unreadable cover is a logged non-event rather
     * than a 500.
     */
    public function path(Track $track): ?string
    {
        $cached = $this->cachePath($track);

        if (is_file($cached)) {
            return $cached;
        }

        $source = $this->sourceImage($track);

        if ($source === null) {
            return null;
        }

        return $this->writeCache($source, $cached, $track->path);
    }

    /**
     * Whether this album can show a cover at all — the `exists()` above at the album
     * grain, and now with NO filesystem access whatsoever: the directory image was
     * resolved and recorded by the scanner, so this is a column read plus one indexed
     * EXISTS for the embedded fallback.
     *
     * A listing asks the same question of columns it already selected rather than
     * calling this per row (AlbumsController), because the EXISTS would be a query per
     * row there; here it is one query for the one album the page is about.
     */
    public function existsForAlbum(Collection $album): bool
    {
        return $album->cover_path !== null
            || $album->tracks()->where('cover', true)->exists();
    }

    /**
     * The absolute path to an ALBUM's cached cover JPEG, or null when the album has
     * art from neither source.
     *
     * The two sources are checked in the OPPOSITE order to a track's: the directory's
     * folder image wins, and an embedded picture is only the fallback. That inversion
     * is the point of this method existing at all — rips are common where every file
     * carries a *different* inline picture (a compilation assembled from singles, a
     * live set with per-song art), and there "the embedded cover" makes an album's
     * thumbnail depend on which track happens to sort first. The Folder.jpg is the one
     * image chosen for the album as a whole, so at this grain it is the truer answer.
     *
     * A track asking the same question keeps its own order (embedded first): there the
     * file's own picture IS the specific answer, and the folder image is the
     * generic one.
     */
    public function albumPath(Collection $album): ?string
    {
        $folderImage = $this->albumFolderImage($album);

        if ($folderImage !== null) {
            $cached = $this->albumCachePath($album, $folderImage);

            if (is_file($cached)) {
                return $cached;
            }

            $bytes = file_get_contents($folderImage);

            return $bytes === false
                ? null
                : $this->writeCache($bytes, $cached, $folderImage);
        }

        // No folder image: fall back to the first track that carries embedded art,
        // and serve it out of that TRACK's cache rather than copying it under a
        // second key — same bytes, and the track cache is keyed by a content hash,
        // so it is the entry that self-invalidates.
        $embedded = $album->tracks()
            ->where('cover', true)
            ->orderBy('disc')
            ->orderBy('track')
            ->orderBy('name')
            ->first();

        return $embedded === null ? null : $this->path($embedded);
    }

    /**
     * The album's directory image as an absolute path, or null.
     *
     * Normally just the path the scanner recorded, resolved against the area root — no
     * directory read at all. It re-resolves LIVE whenever the column has nothing usable
     * to offer, which is two situations and both matter: the path names a file that has
     * since been renamed or deleted, and the path was never recorded (every album, in
     * the window between this migration and the first `app:update`). Either way a
     * direct request for the image still finds it, at the cost of one directory read per
     * IMAGE REQUEST — never per row, which is the cost the column exists to remove.
     *
     * The live branch reads the directory of the album's FIRST track by
     * `(disc, track, name)` — the same rule syncCollectionCovers applies, so the answer
     * cannot depend on whether it came from the column or from disk. A multi-disc set
     * whose discs sit in subdirectories therefore resolves to disc 1's, where a ripper
     * puts the album art.
     */
    private function albumFolderImage(Collection $album): ?string
    {
        if ($album->cover_path !== null) {
            $recorded = Track::absolutePathFor($album->cover_path, $album->type->trackType());

            if (is_file($recorded)) {
                return $recorded;
            }
        }

        $sample = $album->tracks()
            ->orderBy('disc')
            ->orderBy('track')
            ->orderBy('name')
            ->first();

        return $sample === null ? null : $this->directoryImage($sample->absolutePath());
    }

    /**
     * The album image sitting in the same directory as an audio file, or null.
     *
     * PUBLIC because the scanner is the main caller now: `app:update` runs this once
     * per album directory and records the answer in `collections.cover_path`
     * (LibraryScanService::syncCollectionCovers), so a page render doesn't repeat it.
     * The request side still calls it as a fallback when a recorded path has gone
     * stale — which is exactly why the rules live in one method instead of being
     * reimplemented in the scanner.
     *
     * Resolution is a directory READ rather than a stat per candidate name, and that
     * is deliberate: names have to match case-insensitively (measured on the real
     * collection, 923 of 951 album directories spell it `folder.jpg` and exactly one
     * spells it `Folder.jpg` — see the config), and one `scandir` answers every
     * candidate at once instead of one stat per name per case variant. It also hands
     * back the count for free, which the last rule below needs.
     *
     * Two rules, in order:
     *   1. the first configured name (`mixtape.covers.folder_images`) that is present;
     *   2. failing that, the directory's ONLY image, whatever it is called — which
     *      covers art named after the album, and is safe precisely because it is the
     *      only one. With several unrecognised images the answer is null, never a
     *      guess: `back.jpg`, `cd.jpg`, `inlay.jpg` and `booklet.jpg` all exist in
     *      this collection, and every one of them sorts before `folder.jpg`.
     */
    public function directoryImage(string $absoluteTrackPath): ?string
    {
        $directory = dirname($absoluteTrackPath);
        $entries = @scandir($directory);

        if ($entries === false) {
            return null;
        }

        // Keyed by lower-cased name, so a candidate lookup is case-insensitive
        // without walking the list once per spelling.
        $images = [];

        foreach ($entries as $entry) {
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

            if (in_array($extension, self::IMAGE_EXTENSIONS, true) && is_file($directory.'/'.$entry)) {
                $images[strtolower($entry)] = $entry;
            }
        }

        if ($images === []) {
            return null;
        }

        foreach ((array) config('mixtape.covers.folder_images') as $candidate) {
            $name = $images[strtolower((string) $candidate)] ?? null;

            if ($name !== null) {
                return $directory.'/'.$name;
            }
        }

        return count($images) === 1 ? $directory.'/'.reset($images) : null;
    }

    /**
     * The raw image bytes for a track: its embedded picture first, else the album
     * directory's folder image. Null when neither is there.
     */
    private function sourceImage(Track $track): ?string
    {
        if ($track->cover) {
            $embedded = $this->embeddedImage($track->absolutePath());

            if ($embedded !== null) {
                return $embedded;
            }
        }

        $folderImage = $this->directoryImage($track->absolutePath());

        return $folderImage === null ? null : (file_get_contents($folderImage) ?: null);
    }

    /**
     * The picture bytes out of the audio file's ID3v2 tag, via getID3.
     *
     * `option_save_attachments` has to be ON here — the scanner's reader turns it
     * OFF precisely so 12k files don't hold their art in memory, but this reads
     * one file and the bytes ARE the point. getID3 exposes them under
     * `comments.picture` once attachments are kept; the id3v2 APIC frame is the
     * fallback for tags it merged differently.
     */
    private function embeddedImage(string $absolutePath): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $getID3 = new getID3;
        $getID3->option_save_attachments = true;

        $info = $getID3->analyze($absolutePath);

        return $info['comments']['picture'][0]['data']
            ?? $info['id3v2']['APIC'][0]['data']
            ?? $info['id3v2']['PIC'][0]['data']
            ?? null;
    }

    /**
     * Scale the source down to the configured long edge and write it to the cache
     * as JPEG, returning the path (null if the bytes weren't a usable image).
     *
     * Plain GD rather than a new image package: the whole job is decode → scale →
     * encode JPEG, `gd` is already present on the server (and required by nothing
     * else here), and every added composer dependency is another `composer
     * install` step on a deploy.
     *
     * `$source` is named only for the log line — a track's stored path, or a folder
     * image's absolute one — so a warning says which file on the share is unreadable
     * rather than which cache key failed.
     */
    private function writeCache(string $source, string $cached, string $sourceName): ?string
    {
        $image = @imagecreatefromstring($source);

        if ($image === false) {
            Log::channel('library')->warning('Cover for '.$sourceName.' is not a readable image.');

            return null;
        }

        try {
            $width = (int) config('mixtape.covers.width');
            $longEdge = max(imagesx($image), imagesy($image));

            // Only ever scale DOWN: upscaling a small embedded thumbnail just
            // blurs it and costs more bytes than the original.
            if ($longEdge > $width) {
                $scaled = imagescale(
                    $image,
                    (int) round(imagesx($image) * $width / $longEdge),
                    (int) round(imagesy($image) * $width / $longEdge)
                );

                if ($scaled !== false) {
                    imagedestroy($image);
                    $image = $scaled;
                }
            }

            $this->ensureCacheDirectory(dirname($cached));

            imagejpeg($image, $cached, (int) config('mixtape.covers.quality'));
        } finally {
            imagedestroy($image);
        }

        return is_file($cached) ? $cached : null;
    }

    /**
     * Drop a track's cached cover, returning whether there was one to drop.
     *
     * This is the invalidation the cache key cannot do by itself, and the scanner is
     * the only thing that knows when to call it. A track's key is its id — a hash of
     * the audio FRAMES only ([avdataoffset, avdataend), see Id3TagReader), chosen
     * precisely so a re-tag keeps the row's identity. The cost of that choice is here:
     * replacing a file's embedded artwork changes the tag bytes and not the hash, so
     * the key does not move and the OLD picture would be served for good. A re-tag is
     * exactly the event the scanner detects in pass 1, so it deletes the entry there.
     */
    public function forget(Track $track): bool
    {
        $cached = $this->cachePath($track);

        return is_file($cached) && $this->cacheIsWritable() && @unlink($cached);
    }

    /**
     * Drop every cached cover for an album — all mtime variants of its key — and return
     * how many files went.
     *
     * A glob rather than one computed path, because the point is to catch the entries
     * whose mtime suffix no longer matches anything: the album's art having been
     * replaced is precisely when its old scaled copies become unreachable garbage.
     */
    public function forgetAlbum(Collection $album): int
    {
        if (! $this->cacheIsWritable()) {
            return 0;
        }

        $entries = glob($this->cacheDirectory().'/album-'.$album->id.'-*.jpg') ?: [];
        $removed = 0;

        foreach ($entries as $entry) {
            if (@unlink($entry)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Delete cached covers that can never be served again, returning how many went and
     * how many were REFUSED by the filesystem.
     *
     * Two kinds go:
     *   · an entry whose id is not in the database any more — the track or album it was
     *     extracted for is gone. `migrate:fresh` + a rescan makes every entry an orphan
     *     in one step, since a rebuilt row gets a fresh UUID;
     *   · an album's SUPERSEDED mtime variants. `album-<id>-<mtime>.jpg` keys on the
     *     source image's mtime, so replacing the art in place leaves the old scaled copy
     *     behind, unreachable but on disk. Only the newest stamp per album can still
     *     match a request, so the rest go.
     *
     * Surgical rather than legacy's "wipe the cache on every rescan", because these files
     * cost real work to rebuild: a full sweep would re-extract from every mp3 someone
     * happens to view next, and this cache is the reason a page render doesn't decode
     * audio at all.
     *
     * Two queries and one directory listing whatever the cache size — the id sets come
     * back as flat plucks and everything else is array work. It lives HERE, not in the
     * cleanup service that calls it, because the answers depend on this class's own cache
     * layout (where the files are, and how a filename encodes what it belongs to).
     *
     * @return array{removed: int, refused: int}
     */
    public function pruneCache(): array
    {
        $directory = $this->cacheDirectory();

        if (! is_dir($directory)) {
            return ['removed' => 0, 'refused' => 0];
        }

        $entries = glob($directory.'/*.jpg') ?: [];

        if ($entries === []) {
            return ['removed' => 0, 'refused' => 0];
        }

        $known = array_fill_keys(
            array_merge(Track::query()->pluck('id')->all(), Collection::query()->pluck('id')->all()),
            true
        );

        // Highest mtime stamp seen per album id, so the current entry is spared.
        $newest = [];

        foreach ($entries as $entry) {
            [$id, $stamp] = $this->parseCacheName(basename($entry));

            if ($stamp !== null && (! isset($newest[$id]) || $stamp > $newest[$id])) {
                $newest[$id] = $stamp;
            }
        }

        $removed = 0;
        $refused = 0;

        foreach ($entries as $entry) {
            [$id, $stamp] = $this->parseCacheName(basename($entry));

            $orphaned = $id === null || ! isset($known[$id]);
            $superseded = $stamp !== null && $id !== null && $stamp < ($newest[$id] ?? $stamp);

            if (! $orphaned && ! $superseded) {
                continue;
            }

            if ($this->cacheIsWritable() && @unlink($entry)) {
                $removed++;
                Log::channel('library')->info('cleanup: dropped stale cover cache entry '.basename($entry));
            } else {
                $refused++;
            }
        }

        return ['removed' => $removed, 'refused' => $refused];
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

    /**
     * Whether this process can delete from the cache directory — checked once and, when
     * the answer is no, WARNED about once.
     *
     * This guard exists because its absence was a real silent failure, found on the dev
     * box: the cache directory is created at runtime by the web server, so it is
     * www-data's, while `app:update` on that host runs as the admin user. Deleting a file
     * needs write permission on its DIRECTORY, not on the file, so every invalidation
     * `@unlink`ed, failed, returned false, and was counted as "nothing to do" — the scan
     * cheerfully reported `0 cached cover(s) invalidated` while serving stale artwork.
     * (Production is unaffected: there artisan runs `sudo -u www-data`, the same identity
     * that wrote the files.)
     *
     * A warning rather than an exception, because a stale cache is a cosmetic problem and
     * a scan that aborts over one would be worse. Once rather than per file, because a
     * mass re-tag would otherwise write a line per track.
     */
    private function cacheIsWritable(): bool
    {
        if ($this->cacheWritable !== null) {
            return $this->cacheWritable;
        }

        $directory = $this->cacheDirectory();
        $this->cacheWritable = ! is_dir($directory) || is_writable($directory);

        if (! $this->cacheWritable) {
            Log::channel('library')->warning(sprintf(
                'Cover cache %s is not writable by "%s" — stale covers cannot be invalidated and will keep '
                .'being served. Run artisan as the user that owns the cache (the web server), or make the '
                .'directory group-writable for both.',
                $directory,
                $this->processUser(),
            ));
        }

        return $this->cacheWritable;
    }

    /** The effective user, for that warning — the one fact that makes a permission problem diagnosable. */
    private function processUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());

            if (is_array($user) && isset($user['name'])) {
                return (string) $user['name'];
            }
        }

        return (string) (getenv('USER') ?: 'unknown');
    }

    /**
     * Create the cache directory if it isn't there, group-writable and setgid.
     *
     * The mode is the point. This directory is created by whichever process first serves a
     * cover — the web server — while the thing that has to DELETE from it is `app:update`,
     * which on a dev box runs as the admin user instead. Deleting needs write permission
     * on the directory, so a default 0755 hands the invalidation an unwritable cache and
     * costs an operator a manual `chmod` (it cost this project exactly one: see
     * cacheIsWritable). `2775` gives the shared group that write bit, and the setgid bit
     * keeps files created here in the directory's group rather than the writer's primary
     * one, so the next process can still clean up after this one.
     *
     * `chmod` EXPLICITLY rather than trusting mkdir's mode, because umask narrows it — the
     * usual 022 turns a requested 0775 into 0755, which is the very thing being avoided.
     * Only on creation: a directory that already exists carries whatever mode an operator
     * chose, and this is not the place to overrule it. Both calls are best-effort — a
     * cover that cannot be cached is already handled as a logged non-event, and a
     * permission problem here surfaces through the writability guard rather than as a 500.
     */
    private function ensureCacheDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        @mkdir($directory, 0o775, true);
        @chmod($directory, 0o2775);
    }

    /** The one place that knows where cached covers live. */
    private function cacheDirectory(): string
    {
        return storage_path('app/private/'.self::CACHE_DIR);
    }

    /** Where this track's extracted cover is cached (one JPEG per track id). */
    private function cachePath(Track $track): string
    {
        return storage_path('app/private/'.self::CACHE_DIR.'/'.$track->id.'.jpg');
    }

    /**
     * Where an album's scaled folder image is cached — keyed by album id AND by the
     * source image's mtime.
     *
     * The mtime is what makes this safe to cache at all. A collection's id is a plain
     * UUID that survives someone dropping a new Folder.jpg into the directory, so
     * without the mtime in the key the old scaled copy would be served forever. A
     * replaced image simply lands on a new key.
     *
     * That leaves the superseded file behind, which is why `forgetAlbum()` globs: the
     * scanner drops an album's stale entries when its recorded path changes, and the
     * cleanup step sweeps the rest (legacy wiped this cache wholesale on a rescan).
     */
    private function albumCachePath(Collection $album, string $folderImage): string
    {
        $stamp = @filemtime($folderImage) ?: 0;

        return storage_path('app/private/'.self::CACHE_DIR.'/album-'.$album->id.'-'.$stamp.'.jpg');
    }
}
