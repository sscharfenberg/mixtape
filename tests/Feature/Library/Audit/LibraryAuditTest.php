<?php

namespace Tests\Feature\Library\Audit;

use App\Enums\AuditCheck;
use App\Enums\AuditGroup;
use App\Enums\TrackType;
use App\Services\Library\Audit\AuditScope;
use App\Services\Library\Audit\LibraryAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Library\InteractsWithLibraryFiles;
use Tests\TestCase;

/**
 * The orchestrator: which checks run, and which are skipped and why.
 *
 * IT OWNS THE TWO DECISIONS A CHECK CANNOT MAKE FOR ITSELF — whether its area is in scope, and
 * whether the disk it needs is there — and both have the same failure mode if got wrong: a check
 * reports zero, which reads exactly like a healthy library. The third thing pinned here is that a
 * database-only run never walks the shares, because that is the difference between an audit that
 * answers in milliseconds and one that traverses 12,000 files to ask about a column.
 */
class LibraryAuditTest extends TestCase
{
    use InteractsWithLibraryFiles;
    use RefreshDatabase;
    use RunsAuditChecks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeLibraryRoot();
    }

    protected function tearDown(): void
    {
        $this->removeLibraryRoot();
        parent::tearDown();
    }

    public function test_every_registered_check_runs_and_answers(): void
    {
        /*
         * THE TEST THE REGISTRY EXISTS FOR. A new check is one enum case and one class, and the
         * way to get it wrong is to add the case and forget to wire the class — which throws only
         * when that check runs. This asserts every case resolves and returns findings, so the
         * mistake fails here rather than on somebody's library.
         */
        config(['mixtape.library.paths.audiobooks' => $this->makeAudiobookRoot()]);

        $result = app(LibraryAudit::class)->run(AuditCheck::cases(), TrackType::cases());

        $this->assertCount(count(AuditCheck::cases()), $result->results);

        foreach ($result->results as $check) {
            $this->assertNull($check->skipped, $check->case->value.' should have run');
            $this->assertNotSame('', $check->check->title());
            $this->assertNotSame('', $check->check->blurb());
        }
    }

    public function test_every_finding_has_exactly_as_many_cells_as_its_check_has_columns(): void
    {
        /*
         * THE INVARIANT THE WHOLE TABLE RENDERER RESTS ON, and the one a new check breaks silently:
         * a cell too few shifts every column after it, a cell too many spills outside the header,
         * and Markdown renders both without complaint — so the report looks fine and says the wrong
         * thing. Asserted over the real registry with a library holding one of everything, so a
         * check that grows a column without widening its header fails here.
         */
        config(['mixtape.library.paths.audiobooks' => $this->makeAudiobookRoot()]);

        // One fixture per shape the checks report on, so most of them have something to say.
        $this->rawFile('Mgła/[1994] Album/01.mp3');
        $this->album('Album', [
            'Mgła/[1994] Album/01.mp3' => ['track' => 1, 'disc' => 1],
            'Mgła/[1994] Album/09.mp3' => ['track' => 9, 'disc' => 1, 'year' => null, 'cover' => false],
        ], ['cover_path' => null, 'album_artist_id' => null]);
        $this->book('Book', [
            'Reader/Book/01.mp3' => ['track' => 1, 'disc' => 1, 'author_id' => null],
            'Reader/Book/04.mp3' => ['track' => 4, 'disc' => 1, 'narrator_id' => null],
        ]);

        $result = app(LibraryAudit::class)->run(AuditCheck::cases(), TrackType::cases());
        $checked = 0;

        foreach ($result->withFindings() as $check) {
            foreach ($check->findings->listed as $finding) {
                $this->assertCount(
                    count($check->check->columns()),
                    $finding->cells,
                    $check->case->value.' reported '.count($finding->cells).' cell(s) for '
                        .count($check->check->columns()).' column(s)',
                );
                $checked++;
            }
        }

        // The assertion above is vacuous if nothing found anything, which a broken fixture would
        // do silently.
        $this->assertGreaterThan(5, $checked);
    }

    public function test_a_check_outside_the_areas_asked_for_is_skipped_rather_than_clean(): void
    {
        $result = app(LibraryAudit::class)->run(
            [AuditCheck::ChaptersWithoutAuthor, AuditCheck::IncompleteAlbums],
            [TrackType::Music],
        );

        $this->assertSame('outside the areas this run was asked for', $result->results[0]->skipped);
        $this->assertNull($result->results[1]->skipped);
        // Skipped is NOT clean: the difference is what stops a reader trusting a number nobody asked for.
        $this->assertFalse($result->results[0]->isClean());
    }

    public function test_a_disk_check_with_no_library_path_is_skipped_with_a_reason(): void
    {
        config(['mixtape.library.paths.music' => '', 'mixtape.library.paths.audiobooks' => '']);

        $result = app(LibraryAudit::class)->run([AuditCheck::PathEncoding], TrackType::cases());

        $this->assertSame('library path not configured, or missing', $result->results[0]->skipped);
    }

    public function test_a_database_only_run_never_walks_the_disk(): void
    {
        // The walk is the only cost that scales with the collection. `--check=mono` asks a column
        // a question and must not traverse the shares to do it.
        $this->rawFile('A/01.mp3');

        $result = app(LibraryAudit::class)->run([AuditCheck::Mono], TrackType::cases());

        $this->assertSame(0, $result->scanned);
    }

    public function test_a_run_holding_a_disk_check_reports_what_it_walked(): void
    {
        // …and the denominator matters: "3 findings" reads very differently from "3 of 9,861".
        $this->rawFile('A/01.mp3');
        $this->rawFile('A/02.mp3');

        $result = app(LibraryAudit::class)->run([AuditCheck::PathEncoding], [TrackType::Music]);

        $this->assertSame(2, $result->scanned);
    }

    public function test_the_report_order_is_the_registry_order_whatever_was_asked_for(): void
    {
        // The document's shape must not depend on how somebody spelled the option.
        ['checks' => $checks] = AuditCheck::parse(['mono', 'scan-drift', 'incomplete-albums']);

        $this->assertSame(
            [AuditCheck::ScanDrift, AuditCheck::IncompleteAlbums, AuditCheck::Mono],
            $checks,
        );
    }

    public function test_a_group_with_no_check_in_the_run_is_not_rendered(): void
    {
        $result = app(LibraryAudit::class)->run([AuditCheck::Mono], [TrackType::Music]);

        $this->assertSame([AuditGroup::Hygiene], $result->groups());
    }

    public function test_a_run_where_nothing_ran_is_not_clean(): void
    {
        /*
         * SKIPPED IS NOT CLEAN, and this is the roll-up where the two would otherwise become the
         * same number: every check skipped means nobody looked, and reporting that as a clean
         * library is the failure the whole design warns about. Under `--cron` it would be worse —
         * a scheduler with a mis-typed flag sitting green for ever.
         */
        $result = app(LibraryAudit::class)->run([AuditCheck::ChaptersWithoutAuthor], [TrackType::Music]);

        $this->assertSame([], $result->ran());
        $this->assertFalse($result->isClean());
    }

    public function test_a_run_that_asked_something_and_found_nothing_is_clean(): void
    {
        // The other side of the same predicate, so "clean" still means what it says.
        $result = app(LibraryAudit::class)->run([AuditCheck::Mono], [TrackType::Music]);

        $this->assertCount(1, $result->ran());
        $this->assertTrue($result->isClean());
    }

    public function test_the_collision_detection_is_shared_by_the_two_checks_that_read_it(): void
    {
        // It hangs off the scope for the same reason the disk walk does: two checks read the same
        // detection from opposite sides, and resolving one instance each would run its two
        // full-table queries twice per audit with the memo inside it never firing.
        $scope = new AuditScope([TrackType::Music]);

        $this->assertSame($scope->collisions(), $scope->collisions());
    }

    public function test_a_skipped_check_is_absent_from_the_fingerprint_rather_than_zero(): void
    {
        /*
         * WHAT STOPS AN AREA GOING OFFLINE READING AS A BATCH OF FIXES. Recorded as zero, the next
         * `--cron` run would announce every audiobook fault as repaired, and the run after it —
         * once the share is back — would announce them all as new. Absent means "no opinion", so
         * the previous fingerprint simply stands.
         */
        $result = app(LibraryAudit::class)->run(
            [AuditCheck::ChaptersWithoutAuthor, AuditCheck::Mono],
            [TrackType::Music],
        );

        $this->assertArrayNotHasKey('chapters-without-author', $result->fingerprint());
        $this->assertArrayHasKey('mono', $result->fingerprint());
    }
}
