<?php

namespace Tests\Feature\Music;

use App\Enums\SongFilter;
use App\Models\Play;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Songs listing's stats strip and the `?filter=` it links to (`/music/songs`, behind auth).
 *
 * ITS OWN FILE, not another block in SongsPageTest, because what is under test here is the pair
 * rather than either half: a tile's COUNT and the TABLE its link opens come from one predicate
 * (SongFilter::apply), and every test below asserts them together. Split across two files, a
 * change that moved one and not the other would still leave both files green.
 *
 * EVERY FIELD A COUNT READS IS PINNED, never left to the factory, because three of the factory's
 * defaults are RANDOM: `cover` is true seven times in ten, `modified_at` lands anywhere this
 * decade, and `track` is a number between 1 and 14. A test that leaves one of those alone passes
 * on some runs and not others — which is how the audiobook case below was found failing after a
 * dozen green ones.
 */
class SongsStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_strip_carries_a_total_and_one_tile_per_filter(): void
    {
        Track::factory()->count(3)->create();

        $this->actingAs(User::factory()->create())
            ->get('/music/songs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 3)
                // One per case, in the enum's order — the strip draws them as they arrive.
                ->has('stats.filters', count(SongFilter::cases()))
                ->where('stats.filters.0.key', SongFilter::NeverPlayed->value)
                ->where('stats.filters.1.key', SongFilter::AddedThisWeek->value)
                ->where('stats.filters.2.key', SongFilter::Duplicated->value)
                ->where('stats.filters.3.key', SongFilter::Uncovered->value)
                // Asserted exhaustively (`hasAll` fails on extras), so a field cannot appear in
                // the payload without somebody deciding a tile should show it.
                ->has('stats.filters.0', fn (Assert $tile) => $tile->hasAll(['key', 'count', 'href', 'active']))
            );
    }

    public function test_a_count_of_zero_offers_no_link(): void
    {
        // Nothing has been added this week and nothing is filed twice, so two of the four tiles
        // have no table worth opening — and a link to an empty table is a promise the page
        // cannot keep. The two that DO have rows carry their href.
        Track::factory()->count(2)->create(['modified_at' => now()->subMonths(2), 'cover' => false]);

        $this->actingAs(User::factory()->create())
            ->get('/music/songs')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.filters.1.count', 0)
                ->where('stats.filters.1.href', null)
                ->where('stats.filters.2.count', 0)
                ->where('stats.filters.2.href', null)
                ->where('stats.filters.3.count', 2)
                ->where('stats.filters.3.href', '/music/songs?filter=no-cover')
            );
    }

    public function test_never_played_counts_the_reader_and_not_the_household(): void
    {
        // The same rule the popular widgets follow, pointing the other way: a song the housemate
        // has worn out is one THIS reader has never played, and the tile is about the reader.
        $reader = User::factory()->create();
        $housemate = User::factory()->create();
        $mine = Track::factory()->create();
        $theirs = Track::factory()->create();
        Track::factory()->create();

        Play::factory()->create(['track_id' => $mine->id, 'user_id' => $reader->id]);
        Play::factory()->count(20)->create(['track_id' => $theirs->id, 'user_id' => $housemate->id]);

        foreach ([[$reader, 2], [$housemate, 2]] as [$viewer, $expected]) {
            $this->actingAs($viewer)
                ->get('/music/songs')
                ->assertInertia(fn (Assert $page) => $page->where('stats.filters.0.count', $expected));
        }
    }

    public function test_added_this_week_is_a_rolling_window_over_the_files_own_date(): void
    {
        // THE FILE'S mtime, not the row's `created_at`, and the fixture is built so the two
        // disagree: the song that counts is an OLD ROW with a NEW FILE, and the one that does not
        // is a fresh row holding a file from years ago. Reading `created_at` would return exactly
        // the opposite pair — and on a rebuilt library it returns everything (SongFilter carries
        // the measurement).
        Track::factory()->create(['created_at' => now()->subYears(3), 'modified_at' => now()->subDays(2)]);
        Track::factory()->create(['created_at' => now(), 'modified_at' => now()->subDays(8)]);

        $this->actingAs($reader = User::factory()->create())
            ->get('/music/songs')
            ->assertInertia(fn (Assert $page) => $page->where('stats.filters.1.count', 1));

        // …and the table its link opens holds that same one song.
        $this->actingAs($reader)
            ->get('/music/songs?filter=added-this-week')
            ->assertInertia(fn (Assert $page) => $page->has('table.rows', 1));
    }

    public function test_duplicates_counts_every_row_sharing_its_audio_and_nothing_else(): void
    {
        // TWO, not one: the tile counts the rows its link will show, and both copies are rows
        // the reader may want to look at — which is also what makes the number and the table
        // agree. The unique song stays out, and so does the audiobook chapter that happens to
        // share a hash: a duplicate is a duplicate within music.
        $shared = str_repeat('a', 64);
        Track::factory()->count(2)->create(['content_hash' => $shared]);
        Track::factory()->create(['content_hash' => str_repeat('b', 64)]);
        Track::factory()->audiobook()->create(['content_hash' => $shared]);

        $this->actingAs(User::factory()->create())
            ->get('/music/songs')
            ->assertInertia(fn (Assert $page) => $page->where('stats.filters.2.count', 2));
    }

    public function test_a_tile_and_the_table_its_link_opens_report_the_same_number(): void
    {
        // THE WHOLE POINT OF THE STRIP, and the one thing that cannot be allowed to drift: the
        // count and the filter are one predicate, so the table a tile opens holds exactly the
        // rows it counted. Two played songs, three not.
        $reader = User::factory()->create();
        $played = Track::factory()->count(2)->create();
        Track::factory()->count(3)->create();
        $played->each(fn (Track $track) => Play::factory()->create([
            'track_id' => $track->id,
            'user_id' => $reader->id,
        ]));

        $this->actingAs($reader)
            ->get('/music/songs')
            ->assertInertia(fn (Assert $page) => $page->where('stats.filters.0.count', 3));

        // The SAME number, asserted against the table the tile's own href opens.
        $this->actingAs($reader)
            ->get('/music/songs?filter=never-played')
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.total', 3)
                ->has('table.rows', 3)
                // …and the strip still describes the whole library, so the reader can see what
                // they have narrowed out of rather than a tile that only ever repeats itself.
                ->where('stats.total', 5)
                ->where('stats.filters.0.count', 3)
            );
    }

    public function test_the_active_filter_is_marked_and_its_tile_offers_the_way_out(): void
    {
        // A filtered table a reader cannot leave is a dead end, and the tile they arrived by is
        // the honest place to put the door.
        Track::factory()->count(2)->create(['cover' => false]);

        $this->actingAs(User::factory()->create())
            ->get('/music/songs?filter=no-cover')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.filters.3.active', true)
                ->where('stats.filters.3.href', '/music/songs')
                // The others are untouched: their links replace the active filter rather than
                // stacking onto it.
                ->where('stats.filters.0.active', false)
                ->where('stats.filters.0.href', '/music/songs?filter=never-played')
                // And the table says what is narrowing it, which is what makes the DataTable
                // drop a row selection that no longer describes the same rows.
                ->where('table.filters', ['filter' => 'no-cover'])
            );
    }

    public function test_the_filter_survives_a_search_and_narrows_it(): void
    {
        // Both apply, and in that order: the filter goes on before DataTableService sees the
        // query, so the search runs inside it rather than around it.
        $reader = User::factory()->create();
        Track::factory()->create(['name' => 'Hidden Gem', 'cover' => false]);
        Track::factory()->create(['name' => 'Hidden Treasure', 'cover' => true]);

        $this->actingAs($reader)
            ->get('/music/songs?filter=no-cover&search=Hidden')
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 1)
                ->where('table.rows.0.name', 'Hidden Gem')
            );
    }

    public function test_an_unknown_filter_shows_the_whole_table_rather_than_refusing(): void
    {
        // The query string is the table's state and readers pass whole URLs around, so a stale
        // or hand-edited filter falls back exactly as a bad `sort` does — never a 422.
        Track::factory()->count(3)->create();

        $this->actingAs(User::factory()->create())
            ->get('/music/songs?filter=bogus')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 3)
                ->where('table.filters', null)
                ->where('stats.filters.0.active', false)
            );
    }

    public function test_a_filter_sent_as_an_array_does_not_break_the_page(): void
    {
        // `?filter[]=x` reaches the controller as an ARRAY, which `tryFrom` typed against a
        // string would answer with a 500 — the same trap DataTableService guards for `?search[]`.
        Track::factory()->count(2)->create();

        $this->actingAs(User::factory()->create())
            ->get('/music/songs?filter[]=never-played')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('table.rows', 2));
    }

    public function test_audiobook_chapters_are_outside_every_count(): void
    {
        // The listing is music, so its strip is music: a chapter must not inflate the total, and
        // an unplayed book must not turn up as songs never played.
        Track::factory()->create(['cover' => false]);
        Track::factory()->audiobook()->count(4)->create(['cover' => false]);

        $this->actingAs(User::factory()->create())
            ->get('/music/songs')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 1)
                ->where('stats.filters.0.count', 1)
                ->where('stats.filters.3.count', 1)
            );
    }
}
