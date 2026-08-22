<?php

namespace App\Services\Media;

/**
 * A .zip archive written straight to the response, one file at a time.
 *
 * WHY NOT `ZipArchive`. PHP's own zip extension can only write to a FILE — it has no
 * streaming output at all — so using it means building the whole archive on disk and
 * then sending it. Measured against this collection that is a bad trade: the largest
 * album is 1.1 GB, so a temp file costs the media disk a full read, the system disk a
 * full write and a second full read, and it costs the reader 20–30 seconds of a browser
 * showing nothing before the first byte arrives. (It is also a trap on this box, where
 * `/tmp` is a 16 GB **tmpfs** — a gigabyte of RAM for a download.) And a temp file has to
 * be swept: `deleteFileAfterSend` does not reliably run when the client disconnects
 * mid-download, so the alternative ends up pruning a whole download directory before every
 * single export. Streaming has none of those problems, so the only thing bought by a
 * dependency (or by shelling out to `/usr/bin/zip`) is deflate — see next.
 *
 * STORED, NOT DEFLATED. Every byte in here is already compressed: mp3 audio, JPEG
 * artwork, PDF booklets. Deflate would spend a core per download to save approximately
 * nothing. Storing also makes the archive's size arithmetic EXACT before a byte is
 * written (see `contentLength`), which is what lets the response carry a real
 * `Content-Length` — so the browser shows a progress bar and a time estimate rather than
 * an endless spinner. That is a better download than the temp-file version, not a
 * compromise for it.
 *
 * NO DATA DESCRIPTORS. A streaming writer normally cannot fill in the CRC before the
 * data, and defers it to a trailing descriptor (general-purpose flag bit 3) — which some
 * readers handle poorly. This one computes each CRC with `hash_file` first, so every
 * local header is complete and ordinary. That reads each file twice, and the second read
 * comes off the page cache the first one just warmed.
 *
 * TWO KINDS OF ENTRY, and only one of them is a file. An album's archive is built from paths
 * on disk; a playlist export is built from text this app has just generated, which exists
 * nowhere else and has nothing to point at. Writing that text to a temp file first so it could
 * be added as a path would reintroduce, for kilobytes, the whole problem the streaming above
 * exists to avoid. So an entry carries either a path or a body, and the two differ in exactly
 * two places: where the CRC comes from (`hash_file` against `crc32`) and where the bytes come
 * from. Everything else — stored, exact `Content-Length`, no data descriptors — holds for both.
 *
 * STORED APPLIES TO THE TEXT TOO, where the "already compressed" argument does not: an .m3u
 * would deflate well. It stays stored because the exact length is worth more than the bytes are
 * — these archives are kilobytes, and deflating them would cost the progress bar to save
 * nothing anybody would notice.
 *
 * NOT ZIP64, deliberately: the format's 4 GB ceiling on an entry, on the archive and its
 * 65535-entry limit are all far above an album (1.1 GB / 154 files at the extremes of
 * this collection). Should something ever need more — a whole artist, an audiobook
 * series — that is the one thing here that would have to be added.
 *
 * Every offset and field width below is from APPNOTE.TXT (the PKWARE .ZIP specification),
 * sections 4.3.7 (local header), 4.3.12 (central directory) and 4.3.16 (end of central
 * directory).
 */
final class ZipStream
{
    /** Local file header: signature + 26 fixed bytes, before the name. APPNOTE 4.3.7. */
    private const LOCAL_HEADER_SIZE = 30;

    /** Central directory entry: signature + 42 fixed bytes, before the name. APPNOTE 4.3.12. */
    private const CENTRAL_HEADER_SIZE = 46;

    /** End of central directory record, with no archive comment. APPNOTE 4.3.16. */
    private const END_RECORD_SIZE = 22;

    /**
     * Bit 11 — the name is UTF-8. This collection's directories are full of umlauts, and
     * without this flag a reader is entitled to decode them as CP437.
     */
    private const FLAG_UTF8 = 0x0800;

    /** 2.0: the oldest version that reads everything written here. */
    private const VERSION = 20;

    /** "Made by" 2.0 on a UNIX filesystem — what makes the mode bits below meaningful. */
    private const VERSION_MADE_BY = 0x0314;

    /** `0100644` (regular file, rw-r--r--) in the high half, where a UNIX zip puts st_mode. */
    private const EXTERNAL_ATTRIBUTES = 0o100644 << 16;

    /**
     * What actually goes in — the sizes SNAPSHOT at construction so `contentLength()` and
     * `stream()` cannot disagree.
     *
     * `path` names a file on disk and `body` holds bytes already in memory; exactly one of the
     * two is set per entry.
     *
     * @var list<array{name: string, path: ?string, body: ?string, size: int, time: int}>
     */
    private array $files = [];

    /**
     * Take the file list and stat it once.
     *
     * Anything unreadable or gone is dropped here rather than at write time, because by
     * then the `Content-Length` has been promised: a file that vanishes between the scan
     * and the click must not truncate the archive. Entry names are the array KEYS, so a
     * collision (the same name from two directories) resolves to one entry instead of
     * producing a zip with two files of the same name.
     *
     * @param  array<string, string>  $entries  entry name inside the archive => absolute path on disk
     */
    public function __construct(array $entries)
    {
        foreach ($entries as $name => $path) {
            $size = @filesize($path);

            if ($size === false || ! is_file($path) || ! is_readable($path)) {
                continue;
            }

            $this->files[] = [
                'name' => $name,
                'path' => $path,
                'body' => null,
                'size' => $size,
                'time' => @filemtime($path) ?: time(),
            ];
        }
    }

    /**
     * An archive of GENERATED text — one entry per string, nothing read from disk.
     *
     * The playlist export's shape: every .m3u is rendered on the way past and exists only in
     * memory, so there is no file to stat and nothing to drop. An EMPTY string is kept rather
     * than skipped, unlike a missing file above: a playlist with no tracks renders to no lines,
     * and a reader who exports twelve playlists should receive twelve files — one of them empty
     * — rather than eleven and no explanation.
     *
     * `$time` is one instant for the whole archive, passed in rather than read from a clock
     * here, so a caller's tests can pin it and every entry carries the same stamp.
     *
     * @param  array<string, string>  $entries  entry name inside the archive => its bytes
     */
    public static function ofContents(array $entries, int $time): self
    {
        $archive = new self([]);

        foreach ($entries as $name => $body) {
            $archive->files[] = [
                'name' => $name,
                'path' => null,
                'body' => $body,
                'size' => strlen($body),
                'time' => $time,
            ];
        }

        return $archive;
    }

    /** Whether anything survived the stat — an archive of nothing is a 404, not an empty zip. */
    public function isEmpty(): bool
    {
        return $this->files === [];
    }

    /**
     * The exact byte length of the archive this will write.
     *
     * Possible only because nothing is compressed: each entry costs its local header, its
     * name and its bytes, plus a central-directory record and its name again, and the
     * whole thing ends with one fixed record. Worth the arithmetic — it is the difference
     * between a download the browser can put a progress bar on and one it cannot.
     */
    public function contentLength(): int
    {
        $total = self::END_RECORD_SIZE;

        foreach ($this->files as $file) {
            $name = strlen($file['name']);

            $total += self::LOCAL_HEADER_SIZE + $name + $file['size']
                + self::CENTRAL_HEADER_SIZE + $name;
        }

        return $total;
    }

    /**
     * Write the archive to the output stream.
     *
     * `set_time_limit(0)` because the clock would otherwise run out on a gigabyte over a
     * home uplink — this is a copy waiting on a socket, not a computation. The connection
     * dropping still stops it: `ignore_user_abort` stays at its default, so a reader who
     * cancels stops the reads immediately rather than leaving php-fpm copying a
     * gigabyte to nobody.
     */
    public function stream(): void
    {
        set_time_limit(0);

        $output = fopen('php://output', 'wb');

        if ($output === false) {
            return;
        }

        $central = '';
        $offset = 0;

        foreach ($this->files as $file) {
            $offset += $this->writeEntry($output, $file, $central, $offset);
        }

        fwrite($output, $central);
        fwrite($output, $this->endRecord(strlen($central), $offset));
        fclose($output);
    }

    /**
     * Write one entry — its local header and its bytes — appending that entry's central
     * directory record to `$central`, and return how many bytes went out.
     *
     * The CRC is taken FIRST (see the class docblock: it is what removes the need for a
     * trailing data descriptor), and the copy is then capped at the size promised in
     * `contentLength()`. A file that has GROWN since is truncated to that promise and a
     * file that has SHRUNK is padded out to it, because the alternative is a response
     * whose length disagrees with its header — which a browser reports as a failed
     * download of everything, rather than as one bad file inside an otherwise good
     * archive.
     *
     * @param  resource  $output
     * @param  array{name: string, path: ?string, body: ?string, size: int, time: int}  $file
     */
    private function writeEntry($output, array $file, string &$central, int $offset): int
    {
        // The two kinds of entry part company here and in nowhere else: bytes in hand are
        // summed directly, a file is hashed off the disk it is about to be read from.
        $crc = $file['body'] !== null
            ? crc32($file['body'])
            : (int) hexdec(hash_file('crc32b', (string) $file['path']) ?: '0');

        [$time, $date] = $this->dosTimestamp($file['time']);

        $written = fwrite($output, $this->localHeader($file, $crc, $time, $date)) ?: 0;

        if ($file['body'] !== null) {
            $written += fwrite($output, $file['body']) ?: 0;
        } elseif (($source = fopen((string) $file['path'], 'rb')) !== false) {
            $written += stream_copy_to_stream($source, $output, $file['size']) ?: 0;
            fclose($source);
        }

        $short = self::LOCAL_HEADER_SIZE + strlen($file['name']) + $file['size'] - $written;

        if ($short > 0) {
            $written += fwrite($output, str_repeat("\0", $short)) ?: 0;
        }

        $central .= $this->centralHeader($file, $crc, $time, $date, $offset);

        // Push the entry out now rather than at the end of the archive: on a slow link the
        // buffer is what stands between a reader and a progress bar that moves.
        flush();

        return $written;
    }

    /**
     * The local file header — APPNOTE 4.3.7.
     *
     * Compressed and uncompressed size are the same number because nothing is deflated,
     * and the extra field is empty.
     *
     * @param  array{name: string, path: ?string, body: ?string, size: int, time: int}  $file
     */
    private function localHeader(array $file, int $crc, int $time, int $date): string
    {
        return pack(
            'VvvvvvVVVvv',
            0x04034B50,          // signature
            self::VERSION,       // version needed to extract
            self::FLAG_UTF8,     // general purpose bit flag
            0,                   // compression method: stored
            $time,
            $date,
            $crc,
            $file['size'],       // compressed size
            $file['size'],       // uncompressed size
            strlen($file['name']),
            0,                   // extra field length
        ).$file['name'];
    }

    /**
     * One central directory record — APPNOTE 4.3.12.
     *
     * Repeats the local header's fields and adds where that header sits in the archive,
     * which is what a reader uses to jump straight to a single file inside a gigabyte.
     *
     * @param  array{name: string, path: ?string, body: ?string, size: int, time: int}  $file
     */
    private function centralHeader(array $file, int $crc, int $time, int $date, int $offset): string
    {
        return pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014B50,               // signature
            self::VERSION_MADE_BY,
            self::VERSION,            // version needed to extract
            self::FLAG_UTF8,
            0,                        // compression method: stored
            $time,
            $date,
            $crc,
            $file['size'],            // compressed size
            $file['size'],            // uncompressed size
            strlen($file['name']),
            0,                        // extra field length
            0,                        // file comment length
            0,                        // disk number where the file starts
            0,                        // internal file attributes
            self::EXTERNAL_ATTRIBUTES,
            $offset,                  // where this entry's local header is
        ).$file['name'];
    }

    /**
     * The end of central directory record — APPNOTE 4.3.16, and the last 22 bytes of the
     * archive. A reader looks for THIS first and works backwards, which is why an
     * archive is unopenable until it has arrived.
     */
    private function endRecord(int $centralSize, int $centralOffset): string
    {
        $count = count($this->files);

        return pack(
            'VvvvvVVv',
            0x06054B50,      // signature
            0,               // number of this disk
            0,               // disk where the central directory starts
            $count,          // entries on this disk
            $count,          // entries in total
            $centralSize,
            $centralOffset,
            0,               // archive comment length
        );
    }

    /**
     * A UNIX timestamp as the MS-DOS date and time pair the format still stores.
     *
     * Two-second resolution and an epoch of 1980 — both are the format's, not a
     * simplification: a file older than 1980 (which a wrongly-stamped rip can be) would
     * encode as a negative year, so it is clamped to the epoch rather than written as
     * garbage a reader may refuse.
     *
     * @return array{0: int, 1: int} [time, date]
     */
    private function dosTimestamp(int $timestamp): array
    {
        $parts = getdate(max($timestamp, 315532800)); // 1980-01-01 UTC

        if ($parts['year'] < 1980) {
            return [0, (1 << 5) | 1]; // 1980-01-01 00:00:00
        }

        return [
            ($parts['hours'] << 11) | ($parts['minutes'] << 5) | intdiv($parts['seconds'], 2),
            (($parts['year'] - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday'],
        ];
    }
}
