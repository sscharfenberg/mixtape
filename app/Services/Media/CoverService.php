<?php

namespace App\Services\Media;

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
 * picture, else `Folder.jpg` (config `mixtape.covers.folder_image`) sitting
 * beside it in the album directory. Legacy checked them in this same order.
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
     * Whether this track can show a cover at all — used by the controller to
     * decide between a real image URL and the placeholder, WITHOUT extracting
     * anything. `tracks.cover` answers the embedded case from the database; the
     * folder image costs one stat. Both are cheap enough for a page render, and
     * getting it right here is what keeps a broken <img> off the page.
     */
    public function exists(Track $track): bool
    {
        return $track->cover || is_file($this->folderImagePath($track));
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

        return $this->writeCache($source, $cached, $track);
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

        $folderImage = $this->folderImagePath($track);

        return is_file($folderImage) ? (file_get_contents($folderImage) ?: null) : null;
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
     */
    private function writeCache(string $source, string $cached, Track $track): ?string
    {
        $image = @imagecreatefromstring($source);

        if ($image === false) {
            Log::channel('library')->warning('Cover for '.$track->path.' is not a readable image.');

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

            if (! is_dir(dirname($cached))) {
                mkdir(dirname($cached), 0o755, true);
            }

            imagejpeg($image, $cached, (int) config('mixtape.covers.quality'));
        } finally {
            imagedestroy($image);
        }

        return is_file($cached) ? $cached : null;
    }

    /** Where this track's extracted cover is cached (one JPEG per track id). */
    private function cachePath(Track $track): string
    {
        return storage_path('app/private/'.self::CACHE_DIR.'/'.$track->id.'.jpg');
    }

    /** The album directory's fallback image (`Folder.jpg`), beside the audio file. */
    private function folderImagePath(Track $track): string
    {
        return dirname($track->absolutePath()).'/'.config('mixtape.covers.folder_image');
    }
}
