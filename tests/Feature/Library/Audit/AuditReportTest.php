<?php

namespace Tests\Feature\Library\Audit;

use App\Enums\AuditCheck;
use App\Enums\TrackType;
use App\Models\Artist;
use App\Services\Library\Audit\AuditReport;
use App\Services\Library\Audit\LibraryAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The document itself: what a reader actually opens.
 *
 * IT IS TESTED BECAUSE IT IS THE PRODUCT. The checks decide what is true; this decides whether a
 * reader can act on it — and the three ways it can quietly fail are all invisible in a rendered
 * page that looks fine: a truncated list that does not admit it, a clean check that is
 * indistinguishable from one that never ran, and a table broken by a character in a filename.
 */
class AuditReportTest extends TestCase
{
    use RefreshDatabase;
    use RunsAuditChecks;

    /** Render the document for a set of checks, the way the command does. */
    private function render(array $cases, array $areas = [TrackType::Music]): string
    {
        return AuditReport::render(
            app(LibraryAudit::class)->run($cases, $areas),
            '2026-08-20 13:00',
        );
    }

    public function test_a_clean_check_gets_a_row_and_no_section(): void
    {
        /*
         * THE LAYOUT DECISION THE WHOLE DOCUMENT RESTS ON. Twenty-five sections, most of them
         * saying "nothing", would bury the four that matter — but dropping a clean check entirely
         * would leave a reader unable to tell "checked and fine" from "not checked". A row costs
         * one line and says both.
         */
        $report = $this->render([AuditCheck::Mono]);

        $this->assertStringContainsString('| Mono files | database | clean |', $report);
        $this->assertStringNotContainsString('## Mono files', $report);
    }

    public function test_a_skipped_check_says_why_rather_than_reporting_zero(): void
    {
        // "0" for a check that never looked is the worst output an audit can produce: it is the
        // same reading as a healthy library, and a reader would act on it.
        $report = $this->render([AuditCheck::ChaptersWithoutAuthor]);

        $this->assertStringContainsString('*skipped — outside the areas this run was asked for*', $report);
    }

    public function test_a_check_with_findings_is_linked_from_the_summary_to_its_section(): void
    {
        $this->album('Short', [
            'A/Short/01.mp3' => ['track' => 1, 'disc' => 1],
            'A/Short/09.mp3' => ['track' => 9, 'disc' => 1],
        ]);

        $report = $this->render([AuditCheck::IncompleteAlbums]);

        $this->assertStringContainsString('[Albums missing a track](#albums-missing-a-track)', $report);
        $this->assertStringContainsString('## Albums missing a track', $report);
    }

    public function test_a_truncated_section_admits_what_it_left_out(): void
    {
        /*
         * NO SILENT CAPS. A badly tagged library can answer one check with thousands of rows, and
         * a list that stops without saying so reads as "that is all of them" — which is the one
         * wrong impression an audit must never leave.
         */
        // Fifty-five FILES in one album rather than fifty-five albums: `CollectionFactory` names
        // itself through Faker's `unique()`, whose seen-list and 10,000-retry budget are shared by
        // every call in the run — fifty-five sentences exhaust it and the failure surfaces here
        // rather than where the factory is.
        $files = [];
        for ($i = 1; $i <= 55; $i++) {
            $files['A/Album/'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'.mp3'] = ['track' => null];
        }
        $this->album('Unnumbered', $files);

        $report = $this->render([AuditCheck::NoTrackNumber]);

        $this->assertStringContainsString('…and 5 more, not listed (55 in total).', $report);
        // The summary still carries the real total, which is what a reader acts on.
        $this->assertStringContainsString('| **55** |', $report);
    }

    public function test_a_pipe_in_a_name_does_not_break_the_table_it_is_printed_in(): void
    {
        // Real album titles contain pipes, and GFM splits a table row on them before it parses
        // inline code — so a code span is no protection and an unescaped one eats the cell.
        $this->album('AC|DC Live', ['AC|DC/01.mp3' => []], ['cover_path' => null]);

        $report = $this->render([AuditCheck::AlbumsWithoutFolderImage]);

        $this->assertStringContainsString('`AC\|DC Live`', $report);
    }

    public function test_a_backtick_in_a_name_is_printed_rather_than_replaced(): void
    {
        /*
         * A backtick would close the code span, and the obvious fix — swapping it for an
         * apostrophe — is the one thing this must not do: the reader has to FIND this file, and a
         * name they cannot search for is worse than an ugly cell. GFM allows a longer fence.
         */
        $this->album('Don`t', ['A/01.mp3' => []], ['cover_path' => null]);

        $report = $this->render([AuditCheck::AlbumsWithoutFolderImage]);

        $this->assertStringContainsString('`` Don`t ``', $report);
    }

    public function test_a_queue_section_says_it_is_a_queue_where_the_reader_is_looking(): void
    {
        /*
         * The promise has to be next to the findings, not only in the summary: a reader who
         * scrolls straight to 113 artist names and reads them as 113 mistakes will not trust the
         * sections above them either.
         */
        Artist::factory()->create(['name' => 'Nick Cave & The Bad Seeds']);

        $report = $this->render([AuditCheck::LookalikeArtistNames]);

        $this->assertStringContainsString('*A review queue — most entries here are legitimate.*', $report);
    }

    public function test_it_states_the_areas_and_never_promises_a_repair(): void
    {
        $report = $this->render([AuditCheck::Mono], [TrackType::Music]);

        $this->assertStringContainsString('Areas: `music`.', $report);
        $this->assertStringContainsString('reports and never', $report);
        $this->assertStringContainsString('Generated 2026-08-20 13:00', $report);
    }
}
