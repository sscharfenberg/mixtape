<?php

namespace App\Services\Music;

use App\Enums\TrackType;
use App\Models\Collection;
use App\Models\Track;

/**
 * What goes into an album's .zip, and what that .zip is called.
 *
 * THE ALBUM AS IT SITS ON THE SHARE, which is the whole point of offering a download at
 * all: the mp3s under their own file names, and the things beside them that are not audio
 * — the `folder.jpg` every page already shows, the booklet PDFs (27 of them in this
 * collection), the odd `.m3u8` a ripper left. A listener who downloads a record wants
 * what the shelf holds, not just the songs. Legacy MixTape rebuilt the files under
 * invented names (`"$track - $name.mp3"`) and added exactly one image; this copies.
 *
 * TRACKS COME FROM THE DATABASE, EXTRAS COME FROM THE DIRECTORY, and that split is what
 * keeps the archive honest. A directory can hold audio belonging to a different album —
 * a "singles" folder, a rip filed under the wrong artist — so taking every mp3 in it
 * would put another album's music in this download; taking the album's own rows cannot.
 * Non-audio files carry no such risk: a `folder.jpg` two albums share is a picture both
 * of them want.
 *
 * MULTI-DISC SETS KEEP THEIR SHAPE. This collection spells them as `[Disc 1]` /
 * `[Disc 2]` subdirectories under the album's own folder, with the booklet at the album
 * level and sometimes a second one inside a disc. Entry names are therefore relative to
 * the album's COMMON directory, so the archive unpacks to the same tree it came from
 * instead of collapsing two discs of "01 - …" into one folder that loses half of them.
 *
 * Only the directories the album actually occupies are read — never the area root. A rip
 * whose files sit loose at the top of `/var/media/music` would otherwise make "the
 * album's directory" mean the whole 96 GB collection.
 */
final class AlbumArchive
{
    /**
     * Every file the download should contain, as `entry name inside the zip => absolute
     * path on disk`.
     *
     * Tracks first, in the album's own running order (disc, then track, then name — the
     * ordering the album page and the cover service both use), so an unpacked folder
     * reads the way the record does. Extras follow.
     *
     * The entry name is the KEY, which also settles collisions: one file cannot be added
     * twice, and the album directory being scanned both as a track directory and as the
     * common base costs nothing.
     *
     * @return array<string, string>
     */
    public static function entries(Collection $album): array
    {
        $type = $album->type->trackType();

        $paths = $album->tracks()
            ->orderBy('disc')
            ->orderBy('track')
            ->orderBy('name')
            ->pluck('path')
            // Stored paths are area-relative, but a leading slash still turns up in
            // seeded data; normalising here keeps the directory arithmetic below honest.
            ->map(fn (string $path): string => ltrim($path, '/'))
            ->all();

        if ($paths === []) {
            return [];
        }

        $directories = array_values(array_unique(array_map(self::directoryOf(...), $paths)));
        $base = self::commonDirectory($directories);

        $entries = [];

        foreach ($paths as $path) {
            $entries[self::entryName($path, $base)] = Track::absolutePathFor($path, $type);
        }

        // The album's own directory as well as its discs': a booklet usually sits one
        // level above the audio on a multi-disc set. Empty means the area root, which is
        // never scanned — see the class docblock.
        foreach (array_unique([...$directories, $base]) as $directory) {
            if ($directory === '') {
                continue;
            }

            foreach (self::extras($directory, $type) as $path) {
                $entries[self::entryName($path, $base)] = Track::absolutePathFor($path, $type);
            }
        }

        return $entries;
    }

    /**
     * The download's filename: "Artist - Album.zip", or "Album.zip" for a compilation
     * filed under no album-artist.
     *
     * Names are free text out of a file's tags, and this one ends up in a
     * `Content-Disposition` header and then on somebody's disk — so anything outside a
     * conservative set becomes an underscore, runs collapse, and a name that reduces to
     * nothing falls back to "album" rather than producing a file called ".zip". Same rule
     * and same reasoning as PlaylistExport::filename.
     */
    public static function filename(Collection $album): string
    {
        $artist = $album->albumArtist?->name;
        $title = ($artist === null ? '' : $artist.' - ').$album->name;

        $safe = preg_replace('/[^\p{L}\p{N} ._-]+/u', '_', $title) ?? '';
        $safe = trim(preg_replace('/_{2,}/', '_', $safe) ?? '', ' ._-');

        return ($safe === '' ? 'album' : $safe).'.zip';
    }

    /**
     * The non-audio files sitting directly in one of the album's directories, as
     * area-relative paths.
     *
     * Audio is excluded because it comes from the database instead (see the class
     * docblock), matched against the same `mixtape.scan.extensions` the scanner picks up
     * — so an area that grows flac support cannot start leaking a neighbouring album's
     * flacs into a zip. Dot-files go too: they are the junk the cleanup step deletes
     * (`._*`, `.DS_Store`, Samba's temp files), not something anybody meant to keep.
     *
     * One `scandir` per directory, and only for directories this album occupies.
     *
     * @return list<string>
     */
    private static function extras(string $directory, TrackType $type): array
    {
        $absolute = Track::absolutePathFor($directory, $type);
        $names = @scandir($absolute);

        if ($names === false) {
            return [];
        }

        $audio = array_map('strtolower', (array) config('mixtape.scan.extensions'));
        $extras = [];

        foreach ($names as $name) {
            if (str_starts_with($name, '.')) {
                continue;
            }

            if (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $audio, true)) {
                continue;
            }

            if (is_file($absolute.'/'.$name)) {
                $extras[] = $directory.'/'.$name;
            }
        }

        return $extras;
    }

    /**
     * The directory part of an area-relative path, with "no directory at all" spelled as
     * an empty string rather than `dirname`'s ".".
     */
    private static function directoryOf(string $path): string
    {
        $directory = dirname($path);

        return $directory === '.' || $directory === '/' ? '' : $directory;
    }

    /**
     * The deepest directory every one of these is inside — the album's own folder for a
     * multi-disc set, and simply the folder itself for the ordinary case.
     *
     * Compared SEGMENT by segment, not character by character: `Wintersun/[2017] Time`
     * and `Wintersun/[2024] Time II` share the string "Wintersun/[20", which names no
     * directory at all and would produce entry names beginning mid-word.
     *
     * @param  list<string>  $directories
     */
    private static function commonDirectory(array $directories): string
    {
        $common = explode('/', array_shift($directories) ?? '');

        foreach ($directories as $directory) {
            $segments = explode('/', $directory);
            $shared = [];

            foreach ($common as $index => $segment) {
                if (($segments[$index] ?? null) !== $segment) {
                    break;
                }

                $shared[] = $segment;
            }

            $common = $shared;
        }

        return implode('/', $common);
    }

    /**
     * An area-relative path as it should be named inside the archive — relative to the
     * album's own directory, so unpacking rebuilds that folder and nothing above it.
     *
     * A path that is somehow NOT under the base keeps its full relative name rather than
     * being forced flat: it cannot collide with anything, and it says where it came from.
     */
    private static function entryName(string $path, string $base): string
    {
        return $base !== '' && str_starts_with($path, $base.'/')
            ? substr($path, strlen($base) + 1)
            : $path;
    }
}
