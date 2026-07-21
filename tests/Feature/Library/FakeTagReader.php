<?php

namespace Tests\Feature\Library;

use App\Services\Library\Contracts\TagReader;
use App\Services\Library\TrackMetadata;
use RuntimeException;

/**
 * A TagReader that reads its metadata from the file's own body (a small JSON
 * blob), so tests drive the scanner with fully controlled tags + content hash
 * and never need a real mp3 or getID3. A body of `{"__fail": true}` (or any
 * non-JSON) simulates an unreadable file.
 */
class FakeTagReader implements TagReader
{
    public function read(string $absolutePath): TrackMetadata
    {
        $raw = @file_get_contents($absolutePath);
        $data = $raw !== false ? json_decode($raw, true) : null;

        if (! is_array($data) || ($data['__fail'] ?? false)) {
            throw new RuntimeException("fake: unreadable file {$absolutePath}");
        }

        return new TrackMetadata(
            contentHash: $data['hash'] ?? hash('sha256', (string) $raw),
            title: $data['title'] ?? null,
            artist: $data['artist'] ?? null,
            albumArtist: $data['albumArtist'] ?? ($data['artist'] ?? null),
            album: $data['album'] ?? null,
            genre: $data['genre'] ?? null,
            composer: $data['composer'] ?? null,
            publisher: $data['publisher'] ?? null,
            year: $data['year'] ?? null,
            track: $data['track'] ?? null,
            disc: $data['disc'] ?? null,
            codec: $data['codec'] ?? 'MPEG1 L3',
            channel: null,
            duration: isset($data['duration']) ? (float) $data['duration'] : 123.0,
            sampleRate: 44100,
            bitRate: 128000,
            vbr: false,
            hasCover: (bool) ($data['cover'] ?? false),
        );
    }
}
