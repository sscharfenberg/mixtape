<?php

declare(strict_types=1);

namespace App\Services\Playlists;

use App\Models\Playlist;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Renders a playlist as an .m3u file, for a listener who wants it somewhere this app is not
 * — a phone, a car head unit, a desktop player.
 *
 * Ported from the legacy PlaylistService::exportM3u, with six things changed. They are listed
 * because each was a real defect rather than a matter of taste, and because the legacy file is
 * still the reference anyone will reach for:
 *
 * 1. NOTHING IS WRITTEN TO DISK. Legacy put the file at `storage/app/downloads/{playlistId}.m3u`
 *    and downloaded it from there. Nothing ever deleted it, so the directory grew a copy per
 *    playlist forever — and because the name was the playlist's id, two exports of one playlist
 *    at once wrote over each other mid-download. A playlist is a few kilobytes of text built
 *    from rows already in hand; it goes straight out (see {@see render}).
 * 2. THE SOURCE ENCODING IS DECLARED, NOT DETECTED. Legacy asked `mb_detect_encoding` what it
 *    had just built. Detection is a guess by design — it reads pure-ASCII content as ASCII and
 *    can mistake one 8-bit encoding for another — and a wrong guess silently mangles every
 *    accent. The string comes from the database, so it is UTF-8, and saying so is both correct
 *    and cheaper.
 * 3. UNMAPPABLE CHARACTERS BECOME "?" ON PURPOSE. Windows-1252 cannot represent "Sigur Rós"'s
 *    neighbours in this collection — Japanese titles, "ø", "ł" — and mbstring's DEFAULT is to
 *    drop them without a trace, silently shortening a title. A visible "?" says something was
 *    lost. The setting is global and process-wide, so it is set and restored around the one
 *    conversion rather than left changed for whatever runs next in the worker.
 * 4. THE REQUEST IS VALIDATED. Legacy passed `$request->get('encoding')` straight into
 *    `mb_convert_encoding`, so any string a caller liked reached mbstring. The rules now live
 *    in ExportPlaylistRequest, which is this project's standing rule anyway.
 * 5. AN UNKNOWN DURATION IS `-1`, the value the extended-m3u convention reserves for it.
 *    Legacy wrote `floor(null)` = 0, which players read as a zero-length track.
 * 6. THE MIME TYPE IS REAL. Legacy sent `application/vnd`, which is not a type at all.
 *
 * ONE THING IS DELIBERATELY UNCHANGED: `\r\n`. Every player reads CRLF; some Windows-era ones
 * do not read a bare LF, and no player minds the extra byte.
 */
final class PlaylistExport
{
    /** The two shapes an .m3u comes in. `extended` adds the `#EXTM3U` header and `#EXTINF` lines. */
    public const FORMATS = ['simple', 'extended'];

    /**
     * The encodings offered.
     *
     * UTF-8 is right for everything modern. WINDOWS-1252 exists for one real device — the
     * owner's VW ID-7 head unit, which renders a UTF-8 playlist as mojibake — and is the reason
     * the choice is exposed at all rather than being decided here.
     */
    public const ENCODINGS = ['UTF-8', 'Windows-1252'];

    /** Every player reads CRLF; some old ones do not read a bare LF. */
    private const EOL = "\r\n";

    /**
     * The file's bytes, ready to send.
     *
     * Built as one string rather than streamed line by line: the biggest playlist this app can
     * hold is the library (12k tracks, well under a megabyte of text), and a string that exists
     * whole can be encoded in one pass and given a `Content-Length`.
     *
     * @param  string  $format  one of {@see FORMATS}
     * @param  string  $encoding  one of {@see ENCODINGS}
     * @param  string  $prefix  what to put in front of every stored path — see {@see line}
     */
    public static function render(Playlist $playlist, string $format, string $encoding, string $prefix): string
    {
        $extended = $format === 'extended';
        $body = $extended ? '#EXTM3U'.self::EOL : '';

        foreach (self::entries($playlist) as $entry) {
            if ($extended) {
                $body .= self::extinf($entry).self::EOL;
            }

            $body .= self::line($prefix, $entry->path).self::EOL;
        }

        return self::encode($body, $encoding);
    }

    /**
     * The download's filename — the playlist's name, made safe for a header and a filesystem.
     *
     * Legacy sent `$playlist->name.".m3u"` raw. A name is free text: a slash makes it a path, a
     * quote or a newline breaks the `Content-Disposition` header it is interpolated into, and a
     * leading dot hides the file. Anything outside a conservative set becomes an underscore,
     * runs are collapsed, and a name that reduces to nothing falls back to "playlist" rather
     * than producing a file called ".m3u".
     */
    public static function filename(Playlist $playlist): string
    {
        $safe = preg_replace('/[^\p{L}\p{N} ._-]+/u', '_', $playlist->name) ?? '';
        $safe = trim(preg_replace('/_{2,}/', '_', $safe) ?? '', ' ._-');

        return ($safe === '' ? 'playlist' : $safe).'.m3u';
    }

    /**
     * The playlist's entries in the reader's own order, with just the four columns a line needs.
     *
     * A query rather than the relation, so an export of a long playlist does not hydrate a Track
     * model per row to read four strings off it.
     *
     * @return Collection<int, object>
     */
    private static function entries(Playlist $playlist): Collection
    {
        return DB::table('playlist_tracks')
            ->join('tracks', 'tracks.id', '=', 'playlist_tracks.track_id')
            ->leftJoin('artists', 'tracks.artist_id', '=', 'artists.id')
            ->where('playlist_tracks.playlist_id', $playlist->id)
            ->orderBy('playlist_tracks.position')
            // The same tiebreak the page renders by, so the file and the screen agree even
            // where two entries share a position.
            ->orderBy('playlist_tracks.id')
            ->select(['tracks.path', 'tracks.name', 'tracks.duration', 'artists.name as artist'])
            ->get();
    }

    /**
     * One `#EXTINF` line: the running time in whole seconds, then "Artist - Title".
     *
     * `-1` for a track whose tags carried no duration — the convention's own value for unknown,
     * where legacy wrote 0 and players showed a zero-length track. A track crediting nobody is
     * its title alone rather than " - Title", which is what a bare concatenation produces.
     */
    private static function extinf(object $entry): string
    {
        $seconds = $entry->duration === null ? -1 : (int) floor((float) $entry->duration);
        $label = $entry->artist === null ? $entry->name : $entry->artist.' - '.$entry->name;

        return '#EXTINF:'.$seconds.','.$label;
    }

    /**
     * One path line: the reader's prefix joined to the stored, area-relative path.
     *
     * THE JOIN IS THE FIDDLY PART. Stored paths are relative to the area root and carry no
     * leading slash (LibraryScanService::relativePath), while a prefix is typed by a person into
     * a text field and may or may not end in one — so both sides are trimmed and exactly one
     * separator is put back. Legacy concatenated the two, which gave a doubled slash for anyone
     * who typed the trailing one.
     *
     * An empty prefix yields the bare relative path, which is what a player wants when the file
     * sits beside the playlist.
     */
    private static function line(string $prefix, string $path): string
    {
        $prefix = rtrim($prefix, '/');
        $path = ltrim($path, '/');

        return $prefix === '' ? $path : $prefix.'/'.$path;
    }

    /**
     * Convert from UTF-8 — which is what the database gave us — to what the reader asked for.
     *
     * `mb_substitute_character` is global process state, so it is read, set and restored around
     * the one call: a php-fpm worker serves many requests, and leaving it changed would alter
     * how some later, unrelated conversion behaves. See point 3 in the class docblock for why
     * "?" rather than mbstring's silent default.
     */
    private static function encode(string $body, string $encoding): string
    {
        if ($encoding === 'UTF-8') {
            return $body;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0x3F); // "?"

        try {
            return mb_convert_encoding($body, $encoding, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }
}
