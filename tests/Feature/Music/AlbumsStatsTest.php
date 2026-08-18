<?php

namespace Tests\Feature\Music;

use App\Enums\AlbumFilter;
use App\Models\Collection;
use App\Models\Play;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Albums listing's stats strip and the `?filter=` it links to (`/music/albums`, behind auth).
 *
 * Its own file for the reason SongsStatsTest is: what is under test is the PAIR — a tile's count
 * and the table its link opens come from one predicate (AlbumFilter::apply), and asserting either
 * alone would let the two drift apart while both files stayed green.
 *
 * The interesting half here is `incomplete`, which is asked per DISC. Numbering restarts on disc
 * 2, so an album of two ten-track discs has a highest number of 10 against twenty files: asked
 * album-wide, every multi-disc set in the library would be reported as missing tracks. That case
 * has its own test below and is the reason this filter is not a one-liner.
 */
class AlbumsStatsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An album with `$tracks` numbered from 1, on one disc.
     *
     * @param  list<int>|null  $numbers  explicit track numbers, for the gap cases
     */
    private function album(int $tracks = 3, ?array $numbers = null, int $disc = 1, array $attributes = [], array $trackAttributes = []): Collection
    {
        $album = Collection::factory()->create($attributes);

        foreach ($numbers ?? range(1, $tracks) as $number) {
            Track::factory()->create([
                'collection_id' => $album->id,
                'track' => $number,
                'disc' => $disc,
                // PINNED, because the factory's `modified_at` lands anywhere this decade and one
                // of the four tiles counts by it — an unpinned fixture would make "added this
                // week" a coin toss on every run.
                'modified_at' => now()->subYear(),
                ...$trackAttributes,
            ]);
        }

        return $album;
    }

    public function test_the_strip_carries_a_total_and_one_tile_per_filter(): void
    {
        $this->album();
        $this->album();

        $this->actingAs(User::factory()->create())
            ->get('/music/albums')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 2)
                ->has('stats.filters', count(AlbumFilter::cases()))
                ->where('stats.filters.0.key', AlbumFilter::NeverPlayed->value)
                ->where('stats.filters.1.key', AlbumFilter::AddedThisWeek->value)
                ->where('stats.filters.2.key', AlbumFilter::Incomplete->value)
                ->where('stats.filters.3.key', AlbumFilter::SingleTrack->value)
                ->has('stats.filters.0', fn (Assert $tile) => $tile->hasAll(['key', 'count', 'href', 'active']))
            );
    }

    public function test_never_played_means_not_one_of_its_tracks(): void
    {
        // An album is played when ANYTHING on it has been, which is what makes this a question
        // about tracks rather than about the album row. And it counts the READER: an album the
        // housemate has worn out is one this reader has never played.
        $reader = User::factory()->create();
        $housemate = User::factory()->create();

        $mine = $this->album();
        $theirs = $this->album();
        $this->album();

        Play::factory()->create([
            'track_id' => Track::query()->where('collection_id', $mine->id)->value('id'),
            'user_id' => $reader->id,
        ]);
        Play::factory()->count(9)->create([
            'track_id' => Track::query()->where('collection_id', $theirs->id)->value('id'),
            'user_id' => $housemate->id,
        ]);

        $this->actingAs($reader)
            ->get('/music/albums')
            ->assertInertia(fn (Assert $page) => $page->where('stats.filters.0.count', 2));
    }

    public function test_added_this_week_reads_the_files_dates_and_not_the_album_rows(): void
    {
        // An album is new when one of its FILES is. The fixture makes the two readings disagree:
        // the album that counts is an old row holding one fresh file, and the two that must not
        // are fresh rows holding old files — which is the state every album is in after the
        // library tables are rebuilt (AlbumFilter carries the measurement: 925 of 925).
        $this->album(attributes: ['created_at' => now()->subYears(3)], trackAttributes: ['modified_at' => now()->subDays(2)]);
        $this->album(attributes: ['created_at' => now()]);
        $this->album(attributes: ['created_at' => now()]);

        $this->actingAs($reader = User::factory()->create())
            ->get('/music/albums')
            ->assertInertia(fn (Assert $page) => $page->where('stats.filters.1.count', 1));

        $this->actingAs($reader)
            ->get('/music/albums?filter=added-this-week')
            ->assertInertia(fn (Assert $page) => $page->has('table.rows', 1));
    }

    public function test_incomplete_finds_the_gap_in_the_numbering(): void
    {
        // Five files numbered up to six: one is missing, whichever it is.
        $this->album(numbers: [1, 2, 3, 5, 6]);
        $this->album(numbers: [1, 2, 3]);

        $this->actingAs(User::factory()->create())
            ->get('/music/albums')
            ->assertInertia(fn (Assert $page) => $page->where('stats.filters.2.count', 1));
    }

    public function test_incomplete_asks_the_question_per_disc(): void
    {
        // THE CASE THAT DECIDES THE QUERY. A complete two-disc album has a highest track number
        // of 5 against ten files, so an album-wide comparison would report it as missing five
        // tracks — and with a real library that reads as "every box set is broken".
        $complete = Collection::factory()->create();

        foreach ([1, 2] as $disc) {
            foreach (range(1, 5) as $number) {
                Track::factory()->create(['collection_id' => $complete->id, 'disc' => $disc, 'track' => $number]);
            }
        }

        // …and a set whose SECOND disc is short is still found, which is the other half: the
        // first disc being whole must not excuse the second.
        $gapped = Collection::factory()->create();

        foreach (range(1, 5) as $number) {
            Track::factory()->create(['collection_id' => $gapped->id, 'disc' => 1, 'track' => $number]);
        }
        foreach ([1, 2, 4] as $number) {
            Track::factory()->create(['collection_id' => $gapped->id, 'disc' => 2, 'track' => $number]);
        }

        $this->actingAs(User::factory()->create())
            ->get('/music/albums?filter=incomplete')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.filters.2.count', 1)
                ->has('table.rows', 1)
                ->where('table.rows.0.id', $gapped->id)
            );
    }

    public function test_more_files_than_the_numbering_reaches_is_not_called_incomplete(): void
    {
        // THE MISDIAGNOSIS THIS RULE EXISTS TO AVOID. Two files sharing track 1 number no higher
        // than 2 against three files: nothing is missing, the numbering repeats — a reissue whose
        // bonus disc claims disc 1, or an album with two track 4s. Measured on the live library,
        // 96 albums number ABOVE their file count (genuinely short a track) against 4 below, and
        // reporting those four as "incomplete" sends a reader hunting a file that always existed.
        $this->album(numbers: [1, 1, 2]);
        $this->album(numbers: [1, 2, 3]);

        $this->actingAs(User::factory()->create())
            ->get('/music/albums')
            ->assertInertia(fn (Assert $page) => $page->where('stats.filters.2.count', 0));
    }

    public function test_an_album_with_no_track_numbers_at_all_is_not_called_incomplete(): void
    {
        // A different fault — nothing is numbered, so nothing says a track is missing. Folding
        // it in would make one tile mean two things, and a rip with no numbering is a tagging
        // job rather than a re-rip.
        $unnumbered = Collection::factory()->create();
        Track::factory()->count(4)->create(['collection_id' => $unnumbered->id, 'track' => null]);

        $this->actingAs(User::factory()->create())
            ->get('/music/albums')
            ->assertInertia(fn (Assert $page) => $page->where('stats.filters.2.count', 0));
    }

    public function test_single_track_albums_are_counted_and_the_link_opens_them(): void
    {
        // Usually a loose file that got a folder of its own, which is why it is worth a door.
        $lonely = $this->album(1);
        $this->album(4);

        $this->actingAs(User::factory()->create())
            ->get('/music/albums?filter=single-track')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.filters.3.count', 1)
                ->has('table.rows', 1)
                ->where('table.rows.0.id', $lonely->id)
                // The strip still describes every album, so the reader can see what they have
                // narrowed out of.
                ->where('stats.total', 2)
            );
    }

    public function test_a_count_of_zero_offers_no_link(): void
    {
        // A complete, played, long-standing album leaves three of the four tiles at zero — and a
        // link to an empty table is a promise the page cannot keep.
        $album = $this->album(trackAttributes: ['modified_at' => now()->subYear()]);
        Play::factory()->create([
            'track_id' => Track::query()->where('collection_id', $album->id)->value('id'),
            'user_id' => ($reader = User::factory()->create())->id,
        ]);

        $this->actingAs($reader)
            ->get('/music/albums')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.filters.0.count', 0)
                ->where('stats.filters.0.href', null)
                ->where('stats.filters.1.count', 0)
                ->where('stats.filters.1.href', null)
                ->where('stats.filters.2.count', 0)
                ->where('stats.filters.2.href', null)
            );
    }

    public function test_the_active_filter_is_marked_and_its_tile_offers_the_way_out(): void
    {
        $this->album(1);

        $this->actingAs(User::factory()->create())
            ->get('/music/albums?filter=single-track')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.filters.3.active', true)
                ->where('stats.filters.3.href', '/music/albums')
                ->where('stats.filters.0.active', false)
                ->where('stats.filters.0.href', '/music/albums?filter=never-played')
                ->where('table.filters', ['filter' => 'single-track'])
            );
    }

    public function test_an_unknown_filter_shows_the_whole_table_rather_than_refusing(): void
    {
        // The query string is the table's state and readers pass whole URLs around — a stale or
        // hand-edited filter falls back, exactly as a bad `sort` does. An ARRAY value must not
        // 500 it either.
        $this->album();
        $this->album();

        foreach (['filter=bogus', 'filter[]=single-track'] as $query) {
            $this->actingAs(User::factory()->create())
                ->get("/music/albums?{$query}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('table.rows', 2)
                    ->where('table.filters', null)
                );
        }
    }

    public function test_audiobooks_are_outside_every_count(): void
    {
        // The listing is albums, so its strip is albums: a book must not inflate the total, and
        // an unplayed book must not turn up as an album never played. A one-chapter book is the
        // sharp case — it would otherwise be a "single track album".
        $this->album();
        $book = Collection::factory()->audiobook()->create();
        Track::factory()->audiobook()->create(['collection_id' => $book->id, 'track' => 1]);

        $this->actingAs(User::factory()->create())
            ->get('/music/albums')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 1)
                ->where('stats.filters.0.count', 1)
                ->where('stats.filters.3.count', 0)
            );
    }
}
