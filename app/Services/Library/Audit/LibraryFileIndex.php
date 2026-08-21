<?php

declare(strict_types=1);

namespace App\Services\Library\Audit;

use App\Enums\TrackType;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Finder;

/**
 * Every file in the library shares, read ONCE and handed to every disk-side check.
 *
 * THE WALK IS THE EXPENSIVE PART OF AN AUDIT, and three checks need it — the encoding audit
 * wants every audio path, scan drift wants them to compare against the database, and the
 * unindexable-audio check wants the files that are NOT audio as far as this instance is
 * configured. Walking three times would triple the one cost that scales with the size of the
 * collection, so the walk happens here and the checks read a list.
 *
 * It is also built LAZILY (see {@see AuditScope}): a run narrowed to database checks with
 * `--check=` must not touch the disk at all, which is what makes those runs answer in
 * milliseconds on a library the size of this one.
 *
 * PATHS ARE AREA-RELATIVE, exactly as `tracks.path` stores them — that is what lets scan drift
 * compare the two sides without resolving either.
 */
final class LibraryFileIndex
{
    /**
     * @param  array<string, list<string>>  $audio  area key => relative paths with a configured extension
     * @param  array<string, list<string>>  $other  area key => relative paths with any other extension
     * @param  list<string>  $missing  area keys that ARE configured but whose root is not there — kept
     *                                 apart from an area nobody configured, because the two mean
     *                                 opposite things: a deliberate absence, and a share that has
     *                                 gone away while the app still expects it
     */
    private function __construct(
        private readonly array $audio,
        private readonly array $other,
        private readonly array $missing,
    ) {}

    /**
     * Walk the given areas and index what is there.
     *
     * A missing or unconfigured area is RECORDED rather than failed, matching every other
     * library command: an instance with no audiobooks should not have a reporting command that
     * exits non-zero at it. The record is what lets the report say "skipped" instead of
     * silently printing a clean check for an area it never looked at — which would be the same
     * output as a healthy one.
     *
     * @param  TrackType[]  $areas
     */
    public static function for(array $areas): self
    {
        $extensions = array_map('strtolower', (array) config('mixtape.scan.extensions', ['mp3']));
        $audio = [];
        $other = [];
        $missing = [];

        foreach ($areas as $type) {
            $key = $type->libraryPathKey();
            $root = trim((string) config('mixtape.library.paths.'.$key));

            if ($root === '' || ! is_dir($root)) {
                Log::channel('library')->info("audit: {$key} not configured or missing — skipped");

                // CONFIGURED AND ABSENT is a finding; never configured is a fact about the
                // instance. An unmounted share is the case worth waking somebody for, and it looks
                // identical to "no audiobooks here" unless the two are recorded apart.
                if ($root !== '') {
                    $missing[] = $key;
                }

                continue;
            }

            $audio[$key] = [];
            $other[$key] = [];

            foreach ((new Finder)->files()->in($root)->followLinks() as $file) {
                // Backslashes normalised for the same reason the scanner does it: a path that
                // came in from a Samba client must compare equal to the one in the database.
                $relative = str_replace('\\', '/', $file->getRelativePathname());

                if (in_array(strtolower($file->getExtension()), $extensions, true)) {
                    $audio[$key][] = $relative;
                } else {
                    $other[$key][] = $relative;
                }
            }

            /*
             * SORTED, because Finder yields filesystem order and nothing promises that is the same
             * order twice. Two things depend on it being stable. The report is meant to be re-run
             * and compared against the last one, and `--cron` decides whether to alert by hashing
             * the first fifty findings — so on a library with more drift than that, an unsorted
             * walk would raise "the findings changed" on a library where nothing had.
             */
            sort($audio[$key]);
            sort($other[$key]);
        }

        return new self($audio, $other, $missing);
    }

    /**
     * The audio files in one area, area-relative — what the encoding and drift checks read.
     *
     * @return list<string>
     */
    public function audio(TrackType $area): array
    {
        return $this->audio[$area->libraryPathKey()] ?? [];
    }

    /**
     * Everything else in one area, area-relative: images, playlists, booklets — and the audio
     * files this instance is not configured to index, which is the point of keeping them.
     *
     * @return list<string>
     */
    public function other(TrackType $area): array
    {
        return $this->other[$area->libraryPathKey()] ?? [];
    }

    /** Whether an area was actually walked — false when it is unconfigured or its root is gone. */
    public function has(TrackType $area): bool
    {
        return array_key_exists($area->libraryPathKey(), $this->audio);
    }

    /**
     * Whether an area is configured but its root was not there — a share that has gone away.
     *
     * The one unavailability worth REPORTING rather than passing over: an instance with no
     * audiobooks configured is complete as it is, while an audiobooks path that does not resolve
     * means every audiobook row in the database describes files nothing can currently see.
     */
    public function isMissing(TrackType $area): bool
    {
        return in_array($area->libraryPathKey(), $this->missing, true);
    }

    /** How many audio files were examined, so a report can say "3 of 9,861" rather than "3". */
    public function scanned(): int
    {
        return array_sum(array_map('count', $this->audio));
    }
}
