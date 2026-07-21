<?php

namespace Tests\Feature\Library;

use App\Enums\Channel;
use App\Services\Library\Id3TagReader;
use RuntimeException;
use Tests\TestCase;

/**
 * Exercises the REAL getID3-backed reader against committed synthetic fixtures
 * (1s of silence — no copyrighted audio). Unlike the scan tests (which fake the
 * reader), this guards the getID3 tag mapping and the audio-frame hashing itself
 * — the layer a fake can't cover, and where a real bug once hid.
 *
 * Both fixtures share byte-identical audio frames; `retagged.mp3` differs only in
 * its ID3 tags (and their size), so it is the frozen proof that a re-tag keeps
 * the content hash — the anchor for stable track identity.
 */
class Id3TagReaderTest extends TestCase
{
    private function fixture(string $name): string
    {
        return base_path('tests/Fixtures/audio/'.$name);
    }

    public function test_it_extracts_id3_tags_and_stream_fields(): void
    {
        $meta = (new Id3TagReader)->read($this->fixture('tagged.mp3'));

        $this->assertSame('Fixture Song', $meta->title);
        $this->assertSame('Fixture Artist', $meta->artist);
        $this->assertSame('Fixture Album Artist', $meta->albumArtist); // TPE2
        $this->assertSame('Fixture Album', $meta->album);
        $this->assertSame('Testcore', $meta->genre);
        $this->assertSame('Fixture Composer', $meta->composer);
        $this->assertSame('Fixture Publisher', $meta->publisher);
        $this->assertSame(2021, $meta->year);
        $this->assertSame(3, $meta->track);   // parsed from "3/12"
        $this->assertSame(1, $meta->disc);     // parsed from "1/2" (TPOS)
        $this->assertSame(Channel::Stereo, $meta->channel);
        $this->assertSame(44100, $meta->sampleRate);
        $this->assertNotNull($meta->duration);
        $this->assertSame(64, strlen($meta->contentHash)); // sha256 hex
    }

    public function test_retagging_preserves_the_audio_content_hash(): void
    {
        $reader = new Id3TagReader;
        $original = $reader->read($this->fixture('tagged.mp3'));
        $retagged = $reader->read($this->fixture('retagged.mp3'));

        // The files really do differ on disk, and their tags differ…
        $this->assertNotSame(md5_file($this->fixture('tagged.mp3')), md5_file($this->fixture('retagged.mp3')));
        $this->assertNotSame($original->title, $retagged->title);

        // …yet the audio-frame hash is identical, so the row id would be kept.
        $this->assertSame($original->contentHash, $retagged->contentHash);
    }

    public function test_an_unreadable_file_throws_with_getid3_detail(): void
    {
        // A skip must never be silent: the exception carries getID3's own reason,
        // which the scanner logs + e-mails verbatim.
        try {
            (new Id3TagReader)->read($this->fixture('garbage.mp3'));
            $this->fail('expected a RuntimeException for an unreadable file');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('getID3', $e->getMessage());
            $this->assertStringContainsString('MPEG synch', $e->getMessage());
        }
    }
}
