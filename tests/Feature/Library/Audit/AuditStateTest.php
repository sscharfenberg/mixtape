<?php

namespace Tests\Feature\Library\Audit;

use App\Enums\AuditCheck;
use App\Enums\TrackType;
use App\Services\Library\Audit\AuditResult;
use App\Services\Library\Audit\AuditState;
use App\Services\Library\Audit\LibraryAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Library\InteractsWithLibraryFiles;
use Tests\TestCase;

/**
 * The `--cron` baseline: what makes a weekly job worth having.
 *
 * A JOB THAT REPEATS ITSELF GETS FILTERED, and the week that matters gets filtered with it — so
 * the alert is about the delta, and the delta needs a baseline it can trust. Two ways of getting
 * that wrong are silent and both are pinned here: a count-only comparison misses a swap (one album
 * fixed, another broken, in the same week), and a first run with no baseline has to report
 * everything rather than nothing.
 */
class AuditStateTest extends TestCase
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

    /** One check's worth of audit, which is all a comparison needs. */
    private function audit(): AuditResult
    {
        return app(LibraryAudit::class)->run([AuditCheck::NoTrackNumber], [TrackType::Music]);
    }

    public function test_a_first_run_with_no_baseline_reports_everything_it_found(): void
    {
        // A missing, unreadable or corrupt state file all read as "no baseline" — which is also
        // what a reader gets by deleting the file to force a full alert.
        $this->album('A', ['A/01.mp3' => ['track' => null]]);

        $changes = AuditState::changes([], $this->audit());

        $this->assertSame(['Files with no track number: 1'], $changes);
    }

    public function test_a_first_run_does_not_announce_the_checks_that_are_clean(): void
    {
        /*
         * Without this the first `--cron` run lists every clean check as new — "Mono files: 0"
         * twenty times over — and an alert whose first outing is mostly noise is one nobody reads
         * the second time. The measured run said 22 lines where 12 were findings.
         */
        $this->assertSame([], AuditState::changes([], $this->audit()));
    }

    public function test_a_check_that_drops_to_zero_against_a_known_baseline_is_still_reported(): void
    {
        // The other half, and it is the reader finding out their fixes landed — which is only
        // distinguishable from the case above because there IS a baseline to compare with.
        $album = $this->album('A', ['A/01.mp3' => ['track' => null]]);
        $previous = $this->audit()->fingerprint();

        $album->tracks()->update(['track' => 1]);

        $this->assertSame(['Files with no track number: 0 (was 1)'], AuditState::changes($previous, $this->audit()));
    }

    public function test_an_unchanged_run_says_nothing(): void
    {
        $this->album('A', ['A/01.mp3' => ['track' => null]]);
        $previous = $this->audit()->fingerprint();

        $this->assertSame([], AuditState::changes($previous, $this->audit()));
    }

    public function test_a_new_finding_is_reported_with_what_it_was_before(): void
    {
        $this->album('A', ['A/01.mp3' => ['track' => null]]);
        $previous = $this->audit()->fingerprint();

        $this->album('B', ['B/01.mp3' => ['track' => null]]);

        $this->assertSame(['Files with no track number: 2 (was 1)'], AuditState::changes($previous, $this->audit()));
    }

    public function test_a_swap_that_leaves_the_count_alone_is_still_a_change(): void
    {
        /*
         * WHY THE STATE HOLDS A HASH AND NOT ONLY A COUNT. One fault fixed and another appearing
         * in the same week reads as no change to a counter — and that is precisely the week worth
         * hearing about, because something moved that nobody was watching.
         */
        $album = $this->album('A', ['A/01.mp3' => ['track' => null]]);
        $previous = $this->audit()->fingerprint();

        $album->tracks()->delete();
        $this->album('B', ['B/01.mp3' => ['track' => null]]);

        $changes = AuditState::changes($previous, $this->audit());

        $this->assertSame(['Files with no track number: 1 (was 1)'], $changes);
    }

    public function test_the_same_findings_in_a_different_order_compare_equal(): void
    {
        // The hash is over sorted KEYS, so a query that happens to return rows in another order
        // does not read as a library that changed overnight.
        $this->album('A', ['A/01.mp3' => ['track' => null], 'A/02.mp3' => ['track' => null]]);

        $first = $this->audit()->fingerprint();
        $second = $this->audit()->fingerprint();

        $this->assertSame($first, $second);
    }

    public function test_a_narrowed_run_keeps_the_other_checks_history(): void
    {
        /*
         * THE BUG A REPLACING WRITE PRODUCES, and it is the one this whole mode exists to avoid.
         * `fingerprint()` deliberately has no opinion about a check that did not run — so if the
         * write REPLACED the file, a run narrowed by `--check=` (or one whose share was unmounted)
         * would drop every other check's entry, and the next full run would re-announce every
         * standing finding as brand new with no "(was …)" beside it. Measured before the fix: 25
         * entries, then 22 after one mount went away.
         */
        $this->album('A', ['A/01.mp3' => ['track' => null]]);
        $path = $this->root.'/library-audit.state.json';

        AuditState::write($path, $this->audit());

        // A different, narrower run — nothing to say about the check above.
        $narrow = app(LibraryAudit::class)->run([AuditCheck::Mono], [TrackType::Music]);
        AuditState::write($path, $narrow);

        $state = AuditState::read($path);

        $this->assertArrayHasKey('no-track-number', $state);
        $this->assertArrayHasKey('mono', $state);
        // …and the standing finding is still not news.
        $this->assertSame([], AuditState::changes($state, $this->audit()));
    }

    public function test_a_corrupt_state_file_reads_as_no_baseline_rather_than_throwing(): void
    {
        $path = $this->root.'/library-audit.state.json';
        file_put_contents($path, 'not json at all');

        $this->assertSame([], AuditState::read($path));
    }

    public function test_the_baseline_lives_beside_the_report_it_belongs_to(): void
    {
        // Derived from the report path so two reports — a per-area run, say — keep their own
        // baselines without anybody having to configure it.
        $this->assertSame('/tmp/music-only.state.json', AuditState::pathFor('/tmp/music-only.md'));
        $this->assertSame('library-audit.state.json', AuditState::pathFor('library-audit.md'));
    }
}
