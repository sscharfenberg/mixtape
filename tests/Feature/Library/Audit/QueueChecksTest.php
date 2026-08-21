<?php

namespace Tests\Feature\Library\Audit;

use App\Enums\ArtistFilter;
use App\Enums\AuditCheck;
use App\Models\Artist;
use App\Models\Narrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The review queues: candidates for a human, where most entries are expected to be legitimate.
 *
 * THEY ARE TESTED FOR WHAT THEY DO NOT REPORT as much as for what they do. A queue whose
 * false-positive rate creeps up is worse than no queue — a reader who finds three legitimate
 * entries in a row stops reading the sections above it — so each of these pins the shape that
 * must stay OUT: an album whose files simply have no year, a book with one narrator, an artist
 * whose name holds nothing that looks like a separator.
 */
class QueueChecksTest extends TestCase
{
    use RefreshDatabase;
    use RunsAuditChecks;

    public function test_it_queues_an_album_whose_files_claim_different_years(): void
    {
        $this->album('Akira', [
            'A/Akira/01.mp3' => ['year' => 1988],
            'A/Akira/02.mp3' => ['year' => 1990],
        ]);
        $this->album('Agreed', [
            'A/Agreed/01.mp3' => ['year' => 1994],
            'A/Agreed/02.mp3' => ['year' => 1994],
        ]);

        $this->assertSame(['Akira'], $this->subjects(AuditCheck::AlbumYearDisagreement));
    }

    public function test_an_untagged_year_is_not_a_disagreement(): void
    {
        // "Some files carry no year" is the hygiene check's finding, with a different fix. Folding
        // it in here would report one album twice and send the reader to the wrong cure.
        $this->album('Partial', [
            'A/Partial/01.mp3' => ['year' => 1994],
            'A/Partial/02.mp3' => ['year' => null],
        ]);

        $this->assertSame([], $this->subjects(AuditCheck::AlbumYearDisagreement));
        $this->assertSame(['A/Partial/02.mp3'], $this->subjects(AuditCheck::NoYear));
    }

    public function test_it_queues_a_book_with_two_narrators_but_not_one_with_two_chapters(): void
    {
        $first = Narrator::factory()->create();
        $second = Narrator::factory()->create();

        $this->book('Dual', [
            'B/Dual/01.mp3' => ['narrator_id' => $first->id],
            'B/Dual/02.mp3' => ['narrator_id' => $second->id],
        ]);
        $this->book('Single', [
            'B/Single/01.mp3' => ['narrator_id' => $first->id],
            'B/Single/02.mp3' => ['narrator_id' => $first->id],
        ]);

        $this->assertSame(['Dual'], $this->subjects(AuditCheck::SeveralNarrators));
    }

    public function test_it_queues_an_artist_name_that_reads_as_several_credits(): void
    {
        Artist::factory()->create(['name' => 'Nick Cave & The Bad Seeds']);
        Artist::factory()->create(['name' => 'Radiohead']);

        $this->assertSame(['Nick Cave & The Bad Seeds'], $this->subjects(AuditCheck::LookalikeArtistNames));
    }

    public function test_it_reads_the_same_predicate_the_artists_listing_filters_on(): void
    {
        // The tile and the audit are the same question asked twice; this asserts they agree rather
        // than asserting a number of its own.
        Artist::factory()->create(['name' => 'Massive Attack vs Mad Professor']);
        Artist::factory()->create(['name' => 'Jay-Z / Linkin Park']);
        Artist::factory()->create(['name' => 'Portishead']);

        $this->assertSame(
            ArtistFilter::LookalikeName->count(null),
            $this->check(AuditCheck::LookalikeArtistNames)->total,
        );
    }

    public function test_a_queued_artist_carries_the_song_count_that_makes_it_triageable(): void
    {
        /*
         * The number is the whole triage: a "lookalike" with one song beside an artist with fifty
         * is usually a guest credit that has become an artist of its own, while a real band name
         * has a discography. Without it the section is 113 names and no way to start.
         */
        $artist = Artist::factory()->create(['name' => 'Someone feat. Somebody']);
        $this->album('A', [
            'A/01.mp3' => ['artist_id' => $artist->id],
            'A/02.mp3' => ['artist_id' => $artist->id],
        ]);

        $findings = $this->check(AuditCheck::LookalikeArtistNames)->listed;

        $this->assertSame('Someone feat. Somebody', $findings[0]->subject);
        $this->assertSame(['2'], $findings[0]->cells);
    }
}
