<?php

namespace App\Services\Library;

use App\Enums\Channel;

/**
 * The normalised result of reading one audio file — tags + technical stream
 * fields + the identity hash — decoupled from how any one tag library names
 * things. The per-type mapping (which tag becomes artist vs. narrator, etc.)
 * lives in LibraryScanService, not here: this DTO carries the raw normalised
 * values, the scanner decides what they *mean* for the area being scanned.
 *
 * `contentHash` is the sha256 of the audio frames only (not the whole file), so
 * a re-tag leaves it unchanged — the anchor for stable identity across renames
 * and re-tags (data-model.md → "the one fact").
 */
final class TrackMetadata
{
    public function __construct(
        public readonly string $contentHash,
        public readonly ?string $title,
        public readonly ?string $artist,       // ID3 `artist` (TPE1)
        public readonly ?string $albumArtist,  // ID3 `band` (TPE2), falls back to artist
        public readonly ?string $album,
        public readonly ?string $genre,
        public readonly ?string $composer,      // TCOM — also the audiobook author
        public readonly ?string $publisher,     // TPUB
        public readonly ?int $year,
        public readonly ?int $track,
        public readonly ?int $disc,             // TPOS
        public readonly ?string $codec,
        public readonly ?Channel $channel,
        public readonly ?float $duration,       // seconds
        public readonly ?int $sampleRate,
        public readonly ?int $bitRate,
        public readonly bool $vbr,
        public readonly bool $hasCover,
    ) {}
}
