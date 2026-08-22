<?php

declare(strict_types=1);

namespace App\Services\Playlists;

use App\Models\Playlist;
use Illuminate\Support\Collection;

/**
 * Every playlist a reader keeps, as the entries of one .zip.
 *
 * WHY AN ARCHIVE AT ALL, and it is a browser constraint rather than a preference: a page cannot
 * hand over twelve downloads. The export is a plain navigation (PlaylistExportController says
 * why that beats a fetch-and-blob), of which there is exactly one per page — and even the
 * hidden-iframe trick that gets around that runs into Chrome asking "allow multiple downloads?"
 * after the first, with every file after a refusal disappearing silently. One archive is one
 * download, needs no permission, and arrives whole or not at all.
 *
 * NOTHING IS READ FROM DISK. Every entry is text this app renders on the way past
 * ({@see PlaylistExport::render}), so the archive is built from strings — the second kind of
 * entry ZipStream carries, and the reason it carries one.
 *
 * THE SAME THREE OPTIONS FOR ALL OF THEM, which is what makes this worth offering: the reader
 * is describing one device, and a dialog that asked per playlist would be the retyping export
 * presets exist to remove.
 */
final class PlaylistArchive
{
    /**
     * The archive's entries, as `file name inside the zip => the .m3u's bytes`.
     *
     * NAMES ARE MADE UNIQUE HERE, and that is not defensive coding: `PlaylistExport::filename`
     * replaces every run of characters a filesystem will not take with one "_", so two playlists
     * a reader can tell apart ("Rock/Pop" and "Rock?Pop") arrive at one file name — and entry
     * names are the KEYS ZipStream de-duplicates on, so the collision would silently drop a
     * playlist from the download. A counter before the extension keeps every playlist in it.
     *
     * UNIQUENESS IS TESTED CASE-INSENSITIVELY, which is a second collision hiding behind the
     * first. `playlists.name` is unique per owner under a DETERMINISTIC collation, so "Rock" and
     * "rock" are two playlists somebody can really keep — and they produce two perfectly valid,
     * distinct zip entries. The loss happens at the far end instead: macOS and Windows unpack
     * onto case-insensitive filesystems, where the second file overwrites the first. Same silent
     * drop, one step later, so it is closed the same way.
     *
     * An EMPTY playlist still gets a file. It renders to no lines (or to a bare `#EXTM3U`), which
     * is a truthful export of a playlist holding nothing — where an absent file would read as
     * one that failed to export.
     *
     * @param  Collection<int, Playlist>  $playlists  in the order the listing draws them
     * @return array<string, string>
     */
    public static function entries(Collection $playlists, string $format, string $encoding, string $prefix): array
    {
        $entries = [];

        // Folded names, which is what a collision is tested against — kept beside `$entries`
        // rather than derived from its keys each time, so the test is one lookup per playlist.
        $claimed = [];

        foreach ($playlists as $playlist) {
            $name = self::unique(PlaylistExport::filename($playlist), $claimed);

            $claimed[mb_strtolower($name)] = true;
            $entries[$name] = PlaylistExport::render($playlist, $format, $encoding, $prefix);
        }

        return $entries;
    }

    /**
     * What the browser saves the archive as.
     *
     * A fixed name rather than one built from anything: an archive of ALL the reader's playlists
     * has no subject to be named after, and the account's own name has no business in a file
     * landing in somebody's Downloads folder. The date is deliberately absent too — a reader who
     * exports twice in a week wants their file manager to offer "replace", which a stamped name
     * would quietly turn into a second copy.
     */
    public static function filename(): string
    {
        return 'playlists.zip';
    }

    /**
     * `$candidate`, or the next free variant of it — "Rock_Pop.m3u", "Rock_Pop (2).m3u".
     *
     * The suffix goes before the extension rather than after, so every entry in the archive is
     * still an .m3u to whatever unpacks it.
     *
     * `mb_strtolower` rather than `strtolower`: a playlist named in any language this collection
     * holds may carry accents, and folding those a byte at a time would let "Björk" and "björk"
     * through as distinct.
     *
     * @param  array<string, true>  $claimed  names already used, folded for comparison
     */
    private static function unique(string $candidate, array $claimed): string
    {
        if (! array_key_exists(mb_strtolower($candidate), $claimed)) {
            return $candidate;
        }

        $stem = preg_replace('/\.m3u$/', '', $candidate) ?? $candidate;

        for ($suffix = 2; ; $suffix++) {
            $next = "{$stem} ({$suffix}).m3u";

            if (! array_key_exists(mb_strtolower($next), $claimed)) {
                return $next;
            }
        }
    }
}
