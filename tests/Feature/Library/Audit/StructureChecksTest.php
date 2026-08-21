<?php

namespace Tests\Feature\Library\Audit;

use App\Enums\AlbumFilter;
use App\Enums\AuditCheck;
use App\Enums\CollectionType;
use App\Enums\TrackType;
use App\Models\Collection;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The structural checks: what is wrong with how the library is ORGANISED.
 *
 * WHY THESE NEED REAL TESTS. Every one of them is a predicate over aggregates, where the failure
 * modes are silent in both directions — a check that misses a fault leaves a reader believing
 * their collection is clean, and one that invents a fault sends them editing tags that were
 * right. Neither shows up on screen, and a wrong section of the report looks exactly like a
 * correct one.
 *
 * The negative cases matter as much as the positive ones and are asserted alongside them: an
 * album that is merely SHORT is not incomplete, a multi-disc set is not a merged album, and a
 * compilation is not a split album.
 */
class StructureChecksTest extends TestCase
{
    use RefreshDatabase;
    use RunsAuditChecks;

    public function test_an_album_numbering_past_its_file_count_is_missing_a_track(): void
    {
        $this->album('Short', [
            'A/Short/01.mp3' => ['track' => 1, 'disc' => 1],
            'A/Short/03.mp3' => ['track' => 3, 'disc' => 1],
        ]);
        $this->album('Whole', [
            'A/Whole/01.mp3' => ['track' => 1, 'disc' => 1],
            'A/Whole/02.mp3' => ['track' => 2, 'disc' => 1],
        ]);

        $this->assertSame(['Short'], $this->subjects(AuditCheck::IncompleteAlbums));
    }

    public function test_it_names_which_track_is_missing(): void
    {
        /*
         * WHAT THE SECTION USED TO LEAVE OUT. "This album is short" is where a reader's question
         * begins, not ends, and finding the gap by hand means a query per album — unfindable by eye
         * in a 673-chapter book, which is the case that decided this.
         */
        $this->album('Short', [
            'A/Short/01.mp3' => ['track' => 1, 'disc' => 1],
            'A/Short/02.mp3' => ['track' => 2, 'disc' => 1],
            'A/Short/04.mp3' => ['track' => 4, 'disc' => 1],
        ]);

        $findings = $this->check(AuditCheck::IncompleteAlbums)->listed;

        $this->assertSame(['CD 1 Track 3', 'A/Short'], $findings[0]->cells);
    }

    public function test_the_gap_is_named_per_disc_so_the_reader_looks_on_the_right_one(): void
    {
        // A two-disc set short on its second disc is not missing "track 2 of 5" — a reader given
        // the wrong spelling goes hunting on the wrong disc.
        $this->album('Two Discs', [
            'A/Two/[Disc 1]/01.mp3' => ['track' => 1, 'disc' => 1],
            'A/Two/[Disc 2]/01.mp3' => ['track' => 1, 'disc' => 2],
            'A/Two/[Disc 2]/03.mp3' => ['track' => 3, 'disc' => 2],
        ]);

        $this->assertSame('CD 2 Track 2', $this->check(AuditCheck::IncompleteAlbums)->listed[0]->cells[0]);
    }

    public function test_a_gap_where_the_files_carry_no_disc_reads_as_a_bare_number(): void
    {
        // No `CD n` at all, because a placeholder would invent a disc the tags do not claim.
        $this->album('No Discs', [
            'A/No/01.mp3' => ['track' => 1, 'disc' => null],
            'A/No/03.mp3' => ['track' => 3, 'disc' => null],
        ]);

        $this->assertSame('Track 2', $this->check(AuditCheck::IncompleteAlbums)->listed[0]->cells[0]);
    }

    public function test_a_missing_first_track_counts_from_one_and_not_from_what_is_there(): void
    {
        // A rip starting at 3 IS missing 1 and 2; counting from the lowest present would quietly
        // redefine the album as complete.
        $this->album('Late Start', [
            'A/Late/03.mp3' => ['track' => 3, 'disc' => 1],
        ]);

        $this->assertSame('CD 1 Track 1, 2', $this->check(AuditCheck::IncompleteAlbums)->listed[0]->cells[0]);
    }

    public function test_a_long_run_of_gaps_is_capped_and_says_how_many_it_left_out(): void
    {
        // Numbered 1 and 300 with nothing between, one row would be taller than its section. Capped
        // and COUNTED, never silently cut.
        $this->album('Sparse', [
            'A/Sparse/01.mp3' => ['track' => 1, 'disc' => 1],
            'A/Sparse/300.mp3' => ['track' => 300, 'disc' => 1],
        ]);

        $cell = $this->check(AuditCheck::IncompleteAlbums)->listed[0]->cells[0];

        // Grouped, so the phrase that makes the cell unambiguous is not repeated ten times.
        $this->assertStringStartsWith('CD 1 Track 2, 3, 4,', $cell);
        $this->assertStringEndsWith('…and 288 more', $cell);
    }

    public function test_a_book_names_the_chapter_it_is_short_of(): void
    {
        $this->book('The Stand', [
            'K/Stand/01.mp3' => ['track' => 1, 'disc' => 1],
            'K/Stand/02.mp3' => ['track' => 2, 'disc' => 1],
            'K/Stand/04.mp3' => ['track' => 4, 'disc' => 1],
        ]);

        $findings = $this->check(AuditCheck::IncompleteBooks)->listed;

        // The same spelling as an album's, deliberately: these files ARE CD tracks, and one
        // vocabulary across the report beats two that each look right in isolation.
        $this->assertSame('CD 1 Track 3', $findings[0]->cells[0]);
    }

    public function test_it_reads_the_same_predicate_the_listing_filters_on(): void
    {
        /*
         * A TILE'S COUNT AND AN AUDIT'S COUNT ARE THE SAME QUESTION ASKED TWICE. Written out twice
         * they drift, and the drift reads as a wrong number rather than as a wrong filter — so
         * this asserts the two agree rather than asserting a number of its own.
         */
        $this->album('Short', [
            'A/Short/01.mp3' => ['track' => 1, 'disc' => 1],
            'A/Short/05.mp3' => ['track' => 5, 'disc' => 1],
        ]);

        $this->assertSame(
            AlbumFilter::Incomplete->count(null),
            $this->check(AuditCheck::IncompleteAlbums)->total,
        );
    }

    public function test_more_files_than_numbers_is_not_reported_as_incomplete(): void
    {
        // The mirror fault, and it belongs to the other section: calling it incomplete sends a
        // reader hunting for a file that was never missing.
        $this->album('Doubled', [
            'A/Doubled/01.mp3' => ['track' => 1, 'disc' => 1],
            'A/Doubled/01b.mp3' => ['track' => 1, 'disc' => 1],
        ]);

        $this->assertSame([], $this->subjects(AuditCheck::IncompleteAlbums));
    }

    public function test_a_book_missing_a_chapter_is_found_by_the_same_predicate(): void
    {
        $this->book('Necrophobia', [
            'B/Necrophobia/01.mp3' => ['track' => 1, 'disc' => 1],
            'B/Necrophobia/04.mp3' => ['track' => 4, 'disc' => 1],
        ]);
        // …and an album is NOT reported by the book check, which is the half a shared predicate
        // could plausibly get wrong.
        $this->album('Short', [
            'A/Short/01.mp3' => ['track' => 1, 'disc' => 1],
            'A/Short/09.mp3' => ['track' => 9, 'disc' => 1],
        ]);

        $this->assertSame(['Necrophobia'], $this->subjects(AuditCheck::IncompleteBooks));
        $this->assertSame(['Short'], $this->subjects(AuditCheck::IncompleteAlbums));
    }

    public function test_an_album_with_no_recorded_folder_image_is_reported_with_its_folder(): void
    {
        $this->album('Coverless', ['A/[1996] Coverless/01.mp3' => []], ['cover_path' => null]);
        $this->album('Covered', ['A/[1997] Covered/01.mp3' => []]);

        $findings = $this->check(AuditCheck::AlbumsWithoutFolderImage)->listed;

        $this->assertCount(1, $findings);
        $this->assertSame('Coverless', $findings[0]->subject);
        // The folder, because that is where the reader has to put the file.
        $this->assertSame(['A/[1996] Coverless'], $findings[0]->cells);
    }

    public function test_two_files_sharing_a_number_in_one_folder_are_repeated_numbering(): void
    {
        $this->album('Era Vulgaris', [
            'QOTSA/[2007] Era Vulgaris/12 - Suture.mp3' => ['track' => 12, 'disc' => 1],
            'QOTSA/[2007] Era Vulgaris/12 - Hidden.mp3' => ['track' => 12, 'disc' => 1],
        ]);

        $findings = $this->check(AuditCheck::RepeatedTrackNumbers)->listed;

        $this->assertSame('Era Vulgaris', $findings[0]->subject);
        $this->assertSame(['CD 1 Track 12', 'QOTSA/[2007] Era Vulgaris'], $findings[0]->cells);
        // The SAME collision must not also be reported as two albums in one row.
        $this->assertSame([], $this->subjects(AuditCheck::MergedAlbums));
    }

    public function test_the_same_collision_across_folders_is_two_albums_in_one_row(): void
    {
        /*
         * ONE DETECTION, TWO DIAGNOSES, and the directory is the whole difference: inside one
         * folder the cure is to renumber, across two it is to give one album a distinguishing
         * ALBUM tag. Reported without the split it is one problem shown as eight, with the wrong
         * advice on every row.
         */
        $this->album('Once Sent From The Golden Hall', [
            'Amon Amarth/[1997] Once Sent/01.mp3' => ['track' => 1, 'disc' => 1],
            'Amon Amarth/[1997] Once Sent/02.mp3' => ['track' => 2, 'disc' => 1],
            'Amon Amarth/[2009] Once Sent (Remastered)/01.mp3' => ['track' => 1, 'disc' => 1],
            'Amon Amarth/[2009] Once Sent (Remastered)/02.mp3' => ['track' => 2, 'disc' => 1],
        ]);

        $findings = $this->check(AuditCheck::MergedAlbums)->listed;

        $this->assertCount(1, $findings);
        $this->assertSame('Once Sent From The Golden Hall', $findings[0]->subject);
        $this->assertStringContainsString(' + ', $findings[0]->cells[0]);
        $this->assertSame('CD 1 Track 1, 2', $findings[0]->cells[1]);
        // …and it is NOT also in the repeated-numbers section.
        $this->assertSame([], $this->subjects(AuditCheck::RepeatedTrackNumbers));
    }

    public function test_an_untagged_multi_disc_set_is_named_as_a_disc_tag_fault(): void
    {
        /*
         * THE FALSE POSITIVE THE CAUSE COLUMN EXISTS FOR. A two-CD rip in two folders whose files
         * were never disc-numbered collides on every track exactly as a merged album does, so it
         * reaches this check — and the advice a merged album gets is the opposite of what it needs:
         * renaming one folder's ALBUM tag would split a record that belongs together. Nothing else
         * catches it either, since the inconsistent-disc-tags check requires SOME file to carry one.
         */
        $this->album('Mellon Collie', [
            'Pumpkins/[1995] Mellon Collie/Dawn/01.mp3' => ['track' => 1, 'disc' => null],
            'Pumpkins/[1995] Mellon Collie/Dawn/02.mp3' => ['track' => 2, 'disc' => null],
            'Pumpkins/[1995] Mellon Collie/Twilight/01.mp3' => ['track' => 1, 'disc' => null],
            'Pumpkins/[1995] Mellon Collie/Twilight/02.mp3' => ['track' => 2, 'disc' => null],
        ]);

        $findings = $this->check(AuditCheck::MergedAlbums)->listed;

        $this->assertCount(1, $findings);
        $this->assertSame('no DISC tags', $findings[0]->cells[2]);
        // The numbers read without a `CD n`, which is the visible half of the same fact.
        $this->assertSame('Track 1, 2', $findings[0]->cells[1]);
        // …and the disc-tag check cannot see it, which is why the cause is reported here.
        $this->assertSame([], $this->subjects(AuditCheck::InconsistentDiscTags));
    }

    public function test_a_genuinely_merged_album_is_named_as_a_tag_collision(): void
    {
        // The other value of the same column: both rips ARE disc-tagged, so the numbering is fine
        // and the ALBUM tag is what has to change.
        $this->album('Once Sent', [
            'Amon Amarth/[1997] Once Sent/01.mp3' => ['track' => 1, 'disc' => 1],
            'Amon Amarth/[2009] Once Sent (Remastered)/01.mp3' => ['track' => 1, 'disc' => 1],
        ]);

        $this->assertSame('same ALBUM tag', $this->check(AuditCheck::MergedAlbums)->listed[0]->cells[2]);
    }

    public function test_a_multi_disc_album_in_subfolders_is_not_a_merged_album(): void
    {
        /*
         * THE FALSE POSITIVE THE FIRST DRAFT PRODUCED. Looking for `[Disc n]` in folder names
         * found three albums that were perfectly fine, because a real collection spells its disc
         * folders three different ways. Distinct DISC NUMBERS are what stop a multi-disc set
         * colliding, which is a fact in the tags rather than in the paths — so the folders here
         * are named to look nothing like a disc, and the check must still be quiet.
         */
        $this->album('Stadium Arcadium', [
            'RHCP/[2006] Stadium Arcadium/Jupiter/01.mp3' => ['track' => 1, 'disc' => 1],
            'RHCP/[2006] Stadium Arcadium/Jupiter/02.mp3' => ['track' => 2, 'disc' => 1],
            'RHCP/[2006] Stadium Arcadium/Mars/01.mp3' => ['track' => 1, 'disc' => 2],
            'RHCP/[2006] Stadium Arcadium/Mars/02.mp3' => ['track' => 2, 'disc' => 2],
        ]);

        $this->assertSame([], $this->subjects(AuditCheck::MergedAlbums));
        $this->assertSame([], $this->subjects(AuditCheck::RepeatedTrackNumbers));
    }

    public function test_one_folder_feeding_two_album_rows_is_a_split_album(): void
    {
        $this->album('Djinn', ['UADA/[2024] Djinn/01.mp3' => ['track' => 1]]);
        $this->album('Djinn ', ['UADA/[2024] Djinn/02.mp3' => ['track' => 2]]);

        $findings = $this->check(AuditCheck::SplitAlbums)->listed;

        $this->assertCount(1, $findings);
        // Keyed and named by the FOLDER: the rows are what the next scan will replace, the folder
        // is the thing that is wrong.
        $this->assertSame('UADA/[2024] Djinn', $findings[0]->subject);
        $this->assertStringContainsString('Djinn', $findings[0]->cells[0]);
    }

    public function test_an_album_spread_over_its_own_disc_folders_is_not_split(): void
    {
        // Two directories, ONE row — the normal shape of a multi-disc rip, and the inverse of the
        // fault. It would be a false positive for a check that grouped the other way round.
        $this->album('Strange Days', [
            'Doors/[1967] Strange Days/[Disc 1]/01.mp3' => ['track' => 1, 'disc' => 1],
            'Doors/[1967] Strange Days/[Disc 2]/01.mp3' => ['track' => 1, 'disc' => 2],
        ]);

        $this->assertSame([], $this->subjects(AuditCheck::SplitAlbums));
    }

    public function test_an_album_where_only_some_files_carry_a_disc_is_reported(): void
    {
        $this->album('Djinn', [
            'UADA/[2024] Djinn/01.mp3' => ['track' => 1, 'disc' => null],
            'UADA/[2024] Djinn/02.mp3' => ['track' => 2, 'disc' => 1],
        ]);
        // All-tagged and none-tagged are both fine; only the mixture is a fault.
        $this->album('Tagged', [
            'X/Tagged/01.mp3' => ['track' => 1, 'disc' => 1],
            'X/Tagged/02.mp3' => ['track' => 2, 'disc' => 1],
        ]);
        $this->album('Untagged', [
            'X/Untagged/01.mp3' => ['track' => 1, 'disc' => null],
            'X/Untagged/02.mp3' => ['track' => 2, 'disc' => null],
        ]);

        $this->assertSame(['Djinn'], $this->subjects(AuditCheck::InconsistentDiscTags));
    }

    public function test_a_chapter_with_no_author_or_narrator_is_reported_with_its_path(): void
    {
        $this->book('Necrophobia', [
            'B/Necrophobia/01.mp3' => ['track' => 1, 'author_id' => null],
            'B/Necrophobia/02.mp3' => ['track' => 2, 'narrator_id' => null],
            'B/Necrophobia/03.mp3' => ['track' => 3],
        ]);

        $this->assertSame(['B/Necrophobia/01.mp3'], $this->subjects(AuditCheck::ChaptersWithoutAuthor));
        $this->assertSame(['B/Necrophobia/02.mp3'], $this->subjects(AuditCheck::ChaptersWithoutNarrator));
    }

    public function test_a_song_is_never_reported_as_a_chapter_with_no_author(): void
    {
        // Songs have no author column to fill, so an area-blind check would report the whole music
        // library as broken audiobooks.
        Track::factory()->for(Collection::factory()->create(['type' => CollectionType::Album]))->create([
            'type' => TrackType::Music,
            'path' => 'A/B/01.mp3',
            'author_id' => null,
        ]);

        $this->assertSame([], $this->subjects(AuditCheck::ChaptersWithoutAuthor));
    }
}
