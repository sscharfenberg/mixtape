<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Enums\TrackType;
use App\Services\Playlists\PlaylistExport;
use Illuminate\Support\Facades\Log;
use Normalizer;
use Symfony\Component\Finder\Finder;

/**
 * Finds the library files whose PATH a Windows-1252 .m3u cannot name.
 *
 * WHY THIS MATTERS AT ALL. Windows-1252 covers about 250 characters, and
 * {@see PlaylistExport} substitutes anything outside them with "?" on
 * the way out. On a path line that is not a cosmetic loss, it is a DEAD line: the player looks
 * for a file that cannot exist, and "?" is not even a legal filename character on FAT. No
 * substitute character and no transliteration fixes it — if a filename holds a character the
 * encoding lacks, no byte sequence in a Windows-1252 file can name that file. The only real fix
 * is to rename the file, which is why this reports rather than repairs.
 *
 * The export modal already warns the reader per playlist
 * (`resources/app/utils/encoding.ts`). This is the other half: the view over the WHOLE
 * collection, so the offenders can be renamed once instead of being warned about forever.
 *
 * IT READS THE FILESYSTEM, NOT THE DATABASE — deliberately. Renaming files and re-scanning are
 * two steps, and the useful moment to run this is between them: you want to know whether the
 * rename you just did was complete, before `app:update` writes the new paths in. A DB-backed
 * audit would answer a question about the last scan instead of about the disk.
 */
final class PathEncodingAudit
{
    /**
     * The encoding audited against.
     *
     * Only one of {@see PlaylistExport::ENCODINGS} can fail: UTF-8
     * represents everything by construction, so auditing against it would always find nothing.
     * A `--encoding` option would therefore be a switch with one useful position.
     */
    public const TARGET = 'Windows-1252';

    /**
     * Walk the given areas and return every path the target encoding cannot carry.
     *
     * A missing or unconfigured area is skipped rather than failed, matching
     * {@see LibraryCleanupService}: an instance with no audiobooks should not have a reporting
     * command that exits non-zero at it.
     *
     * @param  TrackType[]  $areas
     */
    public function scan(array $areas): PathEncodingAuditResult
    {
        $extensions = array_map('strtolower', (array) config('mixtape.scan.extensions', ['mp3']));
        $scanned = 0;
        $findings = [];

        foreach ($areas as $type) {
            $root = trim((string) config('mixtape.library.paths.'.$type->libraryPathKey()));

            if ($root === '' || ! is_dir($root)) {
                Log::channel('library')->info("encoding audit: {$type->value} not configured or missing — skipped");

                continue;
            }

            foreach ((new Finder)->files()->in($root)->followLinks() as $file) {
                if (! in_array(strtolower($file->getExtension()), $extensions, true)) {
                    continue;
                }

                $scanned++;
                $relative = str_replace('\\', '/', $file->getRelativePathname());
                $offenders = self::offendersIn($relative);

                if ($offenders !== []) {
                    $findings[] = new PathEncodingFinding(
                        $type,
                        $relative,
                        $offenders,
                        self::precomposeFixes($relative),
                    );
                }
            }
        }

        return new PathEncodingAuditResult($scanned, $findings);
    }

    /**
     * The characters in `$text` the target encoding cannot carry, de-duplicated and in the
     * order they first appear.
     *
     * Named rather than merely counted because that is what makes the report actionable: "ł"
     * tells you which record and what to type instead, where "this path will not export" leaves
     * you hunting through a name that looks perfectly ordinary.
     *
     * Iterated per CODE POINT (`preg_split` with `/u`), not per byte — a byte walk would report
     * halves of a multi-byte character, which are meaningless to a reader and unsearchable.
     *
     * @return string[]
     */
    public static function offendersIn(string $text): array
    {
        $found = [];

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if (! self::survives($character) && ! in_array($character, $found, true)) {
                $found[] = $character;
            }
        }

        return $found;
    }

    /**
     * Whether NFC normalisation alone would make this path encodable.
     *
     * The subtlest case in a real collection, and worth calling out separately because the fix
     * is free: macOS stores filenames decomposed, so `Chèvre` can reach the server as "e" plus a
     * combining grave. The composed è IS in Windows-1252 and the pair is not, so the same word
     * passes or fails on its normal form alone — and precomposing changes the bytes without
     * changing one visible glyph. A reader told only "U+0300 is unsupported" would go looking
     * for a character they cannot see.
     */
    private static function precomposeFixes(string $path): bool
    {
        $composed = Normalizer::normalize($path, Normalizer::FORM_C);

        return is_string($composed) && $composed !== $path && self::offendersIn($composed) === [];
    }

    /**
     * Whether one character survives a trip into the target encoding.
     *
     * Asked of mbstring rather than of a hand-written table, so this can never disagree with
     * what the exporter actually does — the predicate is the same one, and only the substitute
     * differs. `"none"` DROPS an unmappable character, so an empty result is an unambiguous
     * "cannot represent this"; the exporter's "?" would be indistinguishable from a real
     * question mark in the input. The setting is process-global, so it is read, set and
     * restored around the single call.
     */
    private static function survives(string $character): bool
    {
        $previous = mb_substitute_character();
        mb_substitute_character('none');

        try {
            return mb_convert_encoding($character, self::TARGET, 'UTF-8') !== '';
        } finally {
            mb_substitute_character($previous);
        }
    }
}
