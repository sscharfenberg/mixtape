<?php

namespace App\Enums;

use App\Services\Library\Audit\Checks;
use App\Services\Library\Audit\Contracts\Check;

/**
 * Every question `app:audit` asks — the REGISTRY, and the order of the report.
 *
 * A REGISTRY RATHER THAN A SWITCH, the same shape {@see SearchKind} uses: `cases()` IS the
 * document order, the value is both the `--check=` slug and the anchor in
 * `docs/library-audit.md`, and one class per case holds the question itself. Anything new here
 * adds an ENTRY rather than a branch — which matters most for the checks that read 0 on a
 * well-kept collection, because there will be more of them as other people's libraries turn up
 * faults this one does not have.
 *
 * THE ORDER IS AN ARGUMENT. Scan drift comes first because it qualifies every database check
 * after it: those are true as of the last scan, and drift is the only measure of how long ago
 * that was. Structure comes next because its findings are the ones a reader can act on today,
 * hygiene after it because a meticulous collection reports nothing there, and the queues last
 * because most of what they list is not a fault at all.
 */
enum AuditCheck: string
{
    /* Drift — how far the database has fallen behind the disk. */
    case ScanDrift = 'scan-drift';

    /* Structure — music. */
    case PathEncoding = 'path-encoding';
    case IncompleteAlbums = 'incomplete-albums';
    case AlbumsWithoutFolderImage = 'albums-without-folder-image';
    case RepeatedTrackNumbers = 'repeated-track-numbers';
    case MergedAlbums = 'merged-albums';
    case SplitAlbums = 'split-albums';
    case InconsistentDiscTags = 'inconsistent-disc-tags';

    /* Structure — audiobooks. */
    case IncompleteBooks = 'incomplete-books';
    case ChaptersWithoutAuthor = 'chapters-without-author';
    case ChaptersWithoutNarrator = 'chapters-without-narrator';

    /* Tag hygiene. */
    case NoYear = 'no-year';
    case NoGenre = 'no-genre';
    case NoArtist = 'no-artist';
    case NoEmbeddedCover = 'no-embedded-cover';
    case NoTrackNumber = 'no-track-number';
    case NoCollection = 'no-album';
    case AlbumsWithoutAlbumArtist = 'albums-without-album-artist';
    case Mono = 'mono';
    case LowSampleRate = 'low-sample-rate';
    case ImplausibleYear = 'implausible-year';
    case UnindexableAudio = 'unindexable-audio';

    /* Review queues — candidates, not faults. */
    case AlbumYearDisagreement = 'album-year-disagreement';
    case LookalikeArtistNames = 'lookalike-artist-names';
    case SeveralNarrators = 'several-narrators';

    /**
     * The class that answers this question.
     *
     * Resolved through the container so a check can take a dependency (the encoding check takes
     * the audit service it already had), and named here rather than derived from the case name:
     * a mapping that guesses a class name breaks silently on the first check whose good name is
     * not its slug in CamelCase.
     */
    public function check(): Check
    {
        return app(match ($this) {
            self::ScanDrift => Checks\ScanDriftCheck::class,
            self::PathEncoding => Checks\PathEncodingCheck::class,
            self::IncompleteAlbums => Checks\IncompleteAlbumsCheck::class,
            self::AlbumsWithoutFolderImage => Checks\AlbumsWithoutFolderImageCheck::class,
            self::RepeatedTrackNumbers => Checks\RepeatedTrackNumbersCheck::class,
            self::MergedAlbums => Checks\MergedAlbumsCheck::class,
            self::SplitAlbums => Checks\SplitAlbumsCheck::class,
            self::InconsistentDiscTags => Checks\InconsistentDiscTagsCheck::class,
            self::IncompleteBooks => Checks\IncompleteBooksCheck::class,
            self::ChaptersWithoutAuthor => Checks\ChaptersWithoutAuthorCheck::class,
            self::ChaptersWithoutNarrator => Checks\ChaptersWithoutNarratorCheck::class,
            self::NoYear => Checks\NoYearCheck::class,
            self::NoGenre => Checks\NoGenreCheck::class,
            self::NoArtist => Checks\NoArtistCheck::class,
            self::NoEmbeddedCover => Checks\NoEmbeddedCoverCheck::class,
            self::NoTrackNumber => Checks\NoTrackNumberCheck::class,
            self::NoCollection => Checks\NoCollectionCheck::class,
            self::AlbumsWithoutAlbumArtist => Checks\AlbumsWithoutAlbumArtistCheck::class,
            self::Mono => Checks\MonoCheck::class,
            self::LowSampleRate => Checks\LowSampleRateCheck::class,
            self::ImplausibleYear => Checks\ImplausibleYearCheck::class,
            self::UnindexableAudio => Checks\UnindexableAudioCheck::class,
            self::AlbumYearDisagreement => Checks\AlbumYearDisagreementCheck::class,
            self::LookalikeArtistNames => Checks\LookalikeArtistNamesCheck::class,
            self::SeveralNarrators => Checks\SeveralNarratorsCheck::class,
        });
    }

    /**
     * Read a `--check=` list: the checks it names, and the slugs that name nothing.
     *
     * BOTH ANSWERS FROM ONE PASS, because a caller needs both and splitting them would put "is
     * this a valid slug" in two places with nothing to keep them in step. A typo must not be
     * ignored — quietly running the whole audit for `--check=speling` looks exactly like the
     * option being unsupported, and on a cron it is a report nobody asked for.
     *
     * @param  string[]  $slugs
     * @return array{checks: self[], unknown: string[]}
     */
    public static function parse(array $slugs): array
    {
        $wanted = [];
        $unknown = [];

        foreach ($slugs as $slug) {
            $case = self::tryFrom((string) $slug);

            if ($case === null) {
                $unknown[] = (string) $slug;

                continue;
            }

            $wanted[$case->value] = true;
        }

        return [
            // Registry order, never the order they were typed: the report's shape must not depend
            // on how somebody spelled the option.
            'checks' => array_values(array_filter(self::cases(), fn (self $case) => isset($wanted[$case->value]))),
            'unknown' => $unknown,
        ];
    }
}
