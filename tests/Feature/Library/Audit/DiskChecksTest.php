<?php

namespace Tests\Feature\Library\Audit;

use App\Enums\AuditCheck;
use App\Enums\TrackType;
use App\Services\Library\Audit\AuditScope;
use App\Services\Library\Audit\LibraryFileIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Library\InteractsWithLibraryFiles;
use Tests\TestCase;

/**
 * The checks that read the DISK: scan drift, unindexable audio, and the encoding audit's wrapper.
 *
 * Scan drift is the one check whose whole job is to compare the two sides, and it is the reason
 * the report puts it first: a file on disk that has never been scanned makes a complete album
 * report as missing a track, so a reader who acts on the database sections without reading this
 * one goes looking for files that are already there. That confusion is what these tests protect.
 */
class DiskChecksTest extends TestCase
{
    use InteractsWithLibraryFiles;
    use RefreshDatabase;
    use RunsAuditChecks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeLibraryRoot();
        config(['mixtape.library.paths.audiobooks' => '']);
    }

    protected function tearDown(): void
    {
        $this->removeLibraryRoot();
        parent::tearDown();
    }

    public function test_a_file_on_disk_with_no_row_is_reported_as_on_disk_only(): void
    {
        $this->rawFile('In Flames/[2019] I, the Mask/05 - Follow Me.mp3');

        $findings = $this->check(AuditCheck::ScanDrift)->listed;

        $this->assertCount(1, $findings);
        $this->assertSame('In Flames/[2019] I, the Mask/05 - Follow Me.mp3', $findings[0]->subject);
        $this->assertSame(['on disk only', 'music'], $findings[0]->cells);
    }

    public function test_a_row_whose_file_has_gone_is_reported_as_database_only(): void
    {
        $this->album('Gone', ['Ghost/[1999] Gone/01.mp3' => []]);

        $findings = $this->check(AuditCheck::ScanDrift)->listed;

        $this->assertSame('Ghost/[1999] Gone/01.mp3', $findings[0]->subject);
        $this->assertSame(['database only', 'music'], $findings[0]->cells);
    }

    public function test_a_scanned_library_reports_no_drift_at_all(): void
    {
        // The state a reader is working towards, and the one that makes every database section
        // below it trustworthy.
        $this->rawFile('A/[1994] B/01.mp3');
        $this->album('B', ['A/[1994] B/01.mp3' => []]);

        $this->assertTrue($this->check(AuditCheck::ScanDrift)->isClean());
    }

    public function test_the_two_sides_are_compared_per_area_and_not_across_them(): void
    {
        /*
         * A chapter's path is relative to the audiobooks root and a song's to the music root, so
         * the same string can legitimately exist in both areas — comparing the pooled lists would
         * report a file as missing from one area because it is present in the other.
         */
        $this->makeAudiobookRoot();
        $this->rawFile('Shared/01.mp3');

        $findings = $this->check(AuditCheck::ScanDrift)->listed;

        $this->assertCount(1, $findings);
        $this->assertSame(['on disk only', 'music'], $findings[0]->cells);
    }

    public function test_a_configured_share_that_is_not_there_is_reported_rather_than_skipped(): void
    {
        /*
         * OTHERWISE DRIFT READS `clean` FOR AN AREA NOBODY COULD LOOK AT — under a group blurb
         * promising that two zeroes mean the document describes the disk as it is now. With one
         * root present the check still runs, so the missing one has to say so itself: every
         * database section for that area is describing files nothing can currently see.
         */
        config(['mixtape.library.paths.audiobooks' => $this->root.'/not-mounted']);

        $findings = $this->check(AuditCheck::ScanDrift)->listed;

        $this->assertCount(1, $findings);
        $this->assertSame(['library path not reachable', 'audiobooks'], $findings[0]->cells);
    }

    public function test_an_area_nobody_configured_says_nothing(): void
    {
        // The deliberate absence, which must stay silent: an instance with no audiobooks is
        // complete as it is, and a standing finding about it would be noise on every run for ever.
        config(['mixtape.library.paths.audiobooks' => '']);

        $this->assertTrue($this->check(AuditCheck::ScanDrift)->isClean());
    }

    public function test_an_audio_file_the_scanner_ignores_is_named_rather_than_passed_over(): void
    {
        // The fault it exists for: the file is in no listing at all, and the album it belongs to
        // reports as missing a track instead — sending the reader after a file that is right there.
        $this->rawFile('A/[1994] B/01.mp3');
        $this->rawFile('A/[1994] B/02.flac');
        $this->rawFile('A/[1994] B/folder.jpg');
        $this->rawFile('A/[1994] B/notes.txt');

        $findings = $this->check(AuditCheck::UnindexableAudio)->listed;

        $this->assertCount(1, $findings);
        $this->assertSame('A/[1994] B/02.flac', $findings[0]->subject);
    }

    public function test_an_audiobook_container_counts_as_audio_worth_naming(): void
    {
        // `.m4b` is the standard audiobook container, so a library imported from anywhere else is
        // full of them — and every one would be silently invisible.
        $this->rawFile('B/Book/01.m4b');

        $this->assertSame(['B/Book/01.m4b'], $this->subjects(AuditCheck::UnindexableAudio));
    }

    public function test_the_encoding_check_reports_renames_rather_than_paths(): void
    {
        /*
         * Offenders cluster in directory names: one album folder makes every track under it
         * unnameable, so a flat list of paths shows ten problems where there is one rename. The
         * count in the summary is therefore renames, which is what a reader works through.
         */
        $this->rawFile('Godspeed/[1997] F♯ A♯ ∞/01.mp3');
        $this->rawFile('Godspeed/[1997] F♯ A♯ ∞/02.mp3');

        $findings = $this->check(AuditCheck::PathEncoding)->listed;

        $this->assertCount(1, $findings);
        $this->assertSame('[1997] F♯ A♯ ∞', $findings[0]->subject);
        $this->assertSame('2', $findings[0]->cells[1]);
    }

    public function test_the_encoding_check_carries_its_own_section_body(): void
    {
        // Its findings cannot be a table — half of what it finds is invisible on screen and has to
        // be read by code point, which is what the character inventory is for.
        $this->rawFile('Mgła/01.mp3');
        $check = AuditCheck::PathEncoding->check();
        $check->run(new AuditScope(TrackType::cases()));

        $this->assertStringContainsString('LATIN SMALL LETTER L WITH STROKE', $check->sectionBody());
        $this->assertStringContainsString('### What to rename', $check->sectionBody());
    }

    public function test_the_walk_comes_back_in_a_stable_order(): void
    {
        /*
         * Finder yields filesystem order and nothing promises that is the same order twice. The
         * report is meant to be re-run and compared against the last one — and `--cron` hashes the
         * first fifty findings to decide whether to alert, so on a library with more drift than
         * that an unsorted walk would announce a change on a library where nothing had changed.
         */
        foreach (['C/03.mp3', 'A/01.mp3', 'B/02.mp3'] as $path) {
            $this->rawFile($path);
        }

        $this->assertSame(
            ['A/01.mp3', 'B/02.mp3', 'C/03.mp3'],
            LibraryFileIndex::for([TrackType::Music])->audio(TrackType::Music),
        );
    }

    public function test_the_walk_happens_once_and_is_shared(): void
    {
        /*
         * The walk is the only cost that scales with the size of the collection, and three checks
         * want it. The index is the thing they share; this pins that it reports both halves of one
         * traversal, which is what makes the sharing possible at all.
         */
        $this->rawFile('A/01.mp3');
        $this->rawFile('A/cover.jpg');

        $index = LibraryFileIndex::for([TrackType::Music]);

        $this->assertSame(['A/01.mp3'], $index->audio(TrackType::Music));
        $this->assertSame(['A/cover.jpg'], $index->other(TrackType::Music));
        $this->assertSame(1, $index->scanned());
        $this->assertTrue($index->has(TrackType::Music));
        $this->assertFalse($index->has(TrackType::Audiobook));
    }
}
