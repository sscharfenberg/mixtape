<?php

namespace Tests\Feature\Library\Audit;

use App\Enums\AuditCheck;
use App\Enums\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tag-hygiene checks: a tag that is missing, or one that cannot be right.
 *
 * EVERY ONE OF THESE READS 0 ON THE COLLECTION THIS WAS BUILT AGAINST, which is exactly why they
 * are tested rather than eyeballed: there is no library here to notice them being wrong on. They
 * exist for somebody else's collection — the one imported from fifteen years of mixed sources —
 * and a check nobody can see fail is a check that has to be pinned by a test.
 *
 * Two of them carry a trap worth the test on its own: mono must not catch joint stereo, and an
 * area-blind check must not report every song as a bad audiobook.
 */
class HygieneChecksTest extends TestCase
{
    use RefreshDatabase;
    use RunsAuditChecks;

    public function test_it_finds_a_file_with_no_year(): void
    {
        $this->album('A', [
            'A/01.mp3' => ['year' => null],
            'A/02.mp3' => ['year' => 1994],
        ]);

        $this->assertSame(['A/01.mp3'], $this->subjects(AuditCheck::NoYear));
    }

    public function test_it_finds_a_song_with_no_genre_or_artist(): void
    {
        $this->album('A', [
            'A/01.mp3' => ['genre_id' => null],
            'A/02.mp3' => ['artist_id' => null],
            'A/03.mp3' => [],
        ]);

        $this->assertSame(['A/01.mp3'], $this->subjects(AuditCheck::NoGenre));
        $this->assertSame(['A/02.mp3'], $this->subjects(AuditCheck::NoArtist));
    }

    public function test_a_chapter_is_not_reported_for_having_no_genre(): void
    {
        // An audiobook chapter has no genre or artist BY CONSTRUCTION — the tracks CHECK
        // constraint forbids them — so an area-blind predicate would report every chapter in the
        // library as an untagged song.
        $this->book('B', ['B/01.mp3' => []]);

        $this->assertSame([], $this->subjects(AuditCheck::NoGenre));
        $this->assertSame([], $this->subjects(AuditCheck::NoArtist));
    }

    public function test_it_finds_a_file_with_no_embedded_cover(): void
    {
        $this->album('A', [
            'A/01.mp3' => ['cover' => false],
            'A/02.mp3' => ['cover' => true],
        ]);

        $this->assertSame(['A/01.mp3'], $this->subjects(AuditCheck::NoEmbeddedCover));
    }

    public function test_it_finds_a_file_with_no_track_number(): void
    {
        $this->album('A', [
            'A/01.mp3' => ['track' => null],
            'A/02.mp3' => ['track' => 2],
        ]);

        $this->assertSame(['A/01.mp3'], $this->subjects(AuditCheck::NoTrackNumber));
    }

    public function test_it_finds_a_file_belonging_to_no_collection(): void
    {
        $this->album('A', ['A/01.mp3' => []]);
        $this->orphanTrack('loose/02.mp3');

        $this->assertSame(['loose/02.mp3'], $this->subjects(AuditCheck::NoCollection));
    }

    public function test_it_finds_an_album_with_no_album_artist(): void
    {
        $this->album('Soundtrack', ['A/01.mp3' => []], ['album_artist_id' => null]);
        $this->album('Owned', ['B/01.mp3' => []]);

        $this->assertSame(['Soundtrack'], $this->subjects(AuditCheck::AlbumsWithoutAlbumArtist));
    }

    public function test_mono_is_mono_and_never_joint_stereo(): void
    {
        /*
         * THE TRAP THIS CHECK EXISTS AGAINST. MP3 encodes most stereo material as JOINT stereo, so
         * `channel <> 'stereo'` reads as a fault on a library that has none — measured, 5,708 of
         * them on a collection with zero mono files. The predicate has to name the fault.
         */
        $this->album('A', [
            'A/01.mp3' => ['channel' => Channel::Mono],
            'A/02.mp3' => ['channel' => Channel::JointStereo],
            'A/03.mp3' => ['channel' => Channel::Stereo],
            'A/04.mp3' => ['channel' => Channel::DualMono],
        ]);

        $this->assertSame(['A/01.mp3'], $this->subjects(AuditCheck::Mono));
    }

    public function test_it_finds_a_file_sampled_below_cd_rate(): void
    {
        $this->album('A', [
            'A/01.mp3' => ['sample_rate' => 22_050],
            'A/02.mp3' => ['sample_rate' => 44_100],
            'A/03.mp3' => ['sample_rate' => 48_000],
        ]);

        $this->assertSame(['A/01.mp3'], $this->subjects(AuditCheck::LowSampleRate));
    }

    public function test_it_finds_a_year_no_recording_could_carry(): void
    {
        $this->album('A', [
            'A/01.mp3' => ['year' => 1899],
            'A/02.mp3' => ['year' => 9999],
            'A/03.mp3' => ['year' => 1900],
            'A/04.mp3' => ['year' => null],
        ]);

        $this->assertSame(['A/01.mp3', 'A/02.mp3'], $this->subjects(AuditCheck::ImplausibleYear));
    }

    public function test_next_years_release_is_not_an_impossible_year(): void
    {
        // A January pressing legitimately arrives tagged with the year it ships in, so a window
        // ending at "this year" would fire every December on files that are perfectly correct.
        $this->album('A', [
            'A/01.mp3' => ['year' => (int) now()->format('Y') + 1],
            'A/02.mp3' => ['year' => (int) now()->format('Y') + 2],
        ]);

        $this->assertSame(['A/02.mp3'], $this->subjects(AuditCheck::ImplausibleYear));
    }
}
