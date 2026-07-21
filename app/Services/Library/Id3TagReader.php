<?php

namespace App\Services\Library;

use App\Enums\Channel;
use App\Services\Library\Contracts\TagReader;
use getID3;
use RuntimeException;

/**
 * getID3-backed TagReader.
 *
 * The reason for getID3 (over the lighter wapmorgan/mp3info the legacy app used)
 * is the identity hash: getID3 reports the audio-data byte range
 * (`avdataoffset` … `avdataend`) — the frames between the leading ID3v2 tag and
 * any trailing ID3v1/APE tag — so we can hash *only the audio*. That is what
 * makes a re-tag keep a track's id: the tag bytes change, the hashed range does
 * not (data-model.md → "identity = a hash of the audio stream").
 */
final class Id3TagReader implements TagReader
{
    /** Read the audio-frame range in 1 MiB chunks so a large file is never fully buffered. */
    private const HASH_CHUNK = 1_048_576;

    public function read(string $absolutePath): TrackMetadata
    {
        $getID3 = new getID3;
        // We only need to KNOW a cover exists (stored as a bool), never its
        // bytes — so don't let getID3 hold embedded art in memory across 12k files.
        $getID3->option_save_attachments = false;

        $info = $getID3->analyze($absolutePath);

        // Surface getID3's OWN diagnosis, errors AND warnings, so a skip is never
        // silent: the useful "why" for an unlocatable stream lives in the warnings
        // (e.g. "garbage data for 49902 bytes between 522 and 50424"), not the
        // errors. The scan service prepends the path; we add the detail. The
        // exception message is what lands in storage/logs/library.log.
        $errors = $this->messages($info['error'] ?? []);
        $warnings = $this->messages($info['warning'] ?? []);

        if ($errors !== []) {
            throw new RuntimeException($this->problem('getID3 could not parse the file', $errors, $warnings));
        }

        if (! isset($info['avdataoffset'], $info['avdataend'])) {
            throw new RuntimeException($this->problem('getID3 found no locatable audio stream', $errors, $warnings));
        }

        // getID3 exposes parsed frames under `tags` (per source), not the merged
        // `comments` array (which stays empty unless tag-copying is invoked). Read
        // id3v2 with id3v1 as the fallback, so re-tagged v2 frames always win.
        $tags = array_merge($info['tags']['id3v1'] ?? [], $info['tags']['id3v2'] ?? []);
        $audio = $info['audio'] ?? [];

        return new TrackMetadata(
            contentHash: $this->hashAudio($absolutePath, (int) $info['avdataoffset'], (int) $info['avdataend']),
            title: $this->tag($tags, 'title'),
            artist: $this->tag($tags, 'artist'),
            albumArtist: $this->tag($tags, 'band') ?? $this->tag($tags, 'artist'), // TPE2 ?? TPE1
            album: $this->tag($tags, 'album'),
            genre: $this->tag($tags, 'genre'),
            composer: $this->tag($tags, 'composer'),
            publisher: $this->tag($tags, 'publisher'),
            year: $this->year($tags),
            track: $this->num($this->tag($tags, 'track_number') ?? $this->tag($tags, 'track')),
            disc: $this->num($this->tag($tags, 'part_of_a_set')),
            codec: $this->codec($info),
            channel: $this->channel($audio['channelmode'] ?? null),
            duration: isset($info['playtime_seconds']) ? (float) $info['playtime_seconds'] : null,
            sampleRate: isset($audio['sample_rate']) ? (int) $audio['sample_rate'] : null,
            bitRate: isset($audio['bitrate']) ? (int) round((float) $audio['bitrate']) : null,
            vbr: ($audio['bitrate_mode'] ?? null) === 'vbr',
            hasCover: ! empty($comments['picture']) || ! empty($info['id3v2']['APIC']) || ! empty($info['id3v2']['PIC']),
        );
    }

    /**
     * sha256 over the audio frames only — the bytes in [$offset, $end). Streams
     * the range so memory stays flat regardless of file size.
     */
    private function hashAudio(string $path, int $offset, int $end): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open for hashing: '.$path);
        }

        $context = hash_init('sha256');

        try {
            if (fseek($handle, $offset) !== 0) {
                throw new RuntimeException('Could not seek to audio data in '.$path);
            }

            $remaining = max(0, $end - $offset);
            while ($remaining > 0 && ! feof($handle)) {
                $chunk = fread($handle, (int) min(self::HASH_CHUNK, $remaining));
                if ($chunk === false) {
                    throw new RuntimeException('Read error while hashing '.$path);
                }
                if ($chunk === '') {
                    break;
                }
                hash_update($context, $chunk);
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($handle);
        }

        return hash_final($context);
    }

    /** First non-empty value of a getID3 tag key (values arrive as arrays), trimmed. */
    private function tag(array $tags, string $key): ?string
    {
        foreach ((array) ($tags[$key] ?? []) as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** Leading integer of a tag, so `"3/12"` → `3` and a bare `"5"` → `5`. */
    private function num(?string $value): ?int
    {
        if ($value === null || ! preg_match('/\d+/', $value, $m)) {
            return null;
        }

        return (int) $m[0];
    }

    /** First 4-digit year found in the year/date/recording-time frames. */
    private function year(array $tags): ?int
    {
        foreach (['year', 'date', 'recording_time', 'creation_date'] as $key) {
            $value = $this->tag($tags, $key);
            if ($value !== null && preg_match('/\d{4}/', $value, $m)) {
                return (int) $m[0];
            }
        }

        return null;
    }

    /** A compact codec label, capped at the `codec` column's 14 chars. */
    private function codec(array $info): ?string
    {
        $mpeg = $info['mpeg']['audio'] ?? null;
        if (isset($mpeg['version'], $mpeg['layer'])) {
            return mb_substr(sprintf('MPEG%s L%s', $mpeg['version'], $mpeg['layer']), 0, 14);
        }

        $format = $info['audio']['dataformat'] ?? null;

        return $format !== null ? mb_substr(strtoupper((string) $format), 0, 14) : null;
    }

    /** Map getID3's `channelmode` string to the Channel enum. */
    private function channel(?string $mode): ?Channel
    {
        return match ($mode) {
            'stereo' => Channel::Stereo,
            'joint stereo' => Channel::JointStereo,
            'dual channel' => Channel::DualMono,
            'mono' => Channel::Mono,
            default => null,
        };
    }

    /**
     * Normalise a getID3 error/warning bag (array of strings, sometimes nested)
     * to a flat list of trimmed, non-empty messages.
     *
     * @return string[]
     */
    private function messages(mixed $bag): array
    {
        $flat = [];
        $bag = (array) $bag; // array_walk_recursive needs a by-ref variable, not a cast expression
        array_walk_recursive($bag, function ($m) use (&$flat) {
            $m = trim((string) $m);
            if ($m !== '') {
                $flat[] = $m;
            }
        });

        return $flat;
    }

    /**
     * Compose a detailed problem string from a headline plus getID3's own errors
     * and warnings — everything we know about why the file couldn't be read.
     *
     * @param  string[]  $errors
     * @param  string[]  $warnings
     */
    private function problem(string $headline, array $errors, array $warnings): string
    {
        $parts = [$headline];
        if ($errors !== []) {
            $parts[] = 'getID3 errors: '.implode(' | ', $errors);
        }
        if ($warnings !== []) {
            $parts[] = 'getID3 warnings: '.implode(' | ', $warnings);
        }

        return implode('; ', $parts);
    }
}
