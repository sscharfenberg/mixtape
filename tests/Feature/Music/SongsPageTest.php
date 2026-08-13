<?php

namespace Tests\Feature\Music;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Songs listing (`/music/songs`, behind auth) — the server-driven DataTable
 * payload SongsController shapes.
 *
 * Search IS covered here now: matching moved onto the `name_fold` columns with a
 * plain `like` (FoldedSearch), so the same query runs on SQLite as on Postgres.
 * Before that it was `COLLATE "C" ILIKE`, which SQLite answers with a hard
 * `near "ILIKE": syntax error` — the search path could not be tested at all.
 */
class SongsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/music/songs')->assertRedirect('/login');
    }

    public function test_every_row_carries_the_href_that_makes_it_clickable(): void
    {
        $song = Track::factory()->create(['name' => 'Lightning Strikes', 'duration' => 185.4]);

        $this->actingAs(User::factory()->create())
            ->get('/music/songs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Music/Songs/SongsPage')
                ->has('table.rows', 1)
                ->where('table.rows.0.name', 'Lightning Strikes')
                // Raw seconds, not a "3:05" clock: the page's cell-duration slot
                // formats it (Utils/formatting.ts → formatClock).
                ->where('table.rows.0.duration', 185.4)
                // The row click and the title link both navigate to this; a
                // relative path, so it holds whatever host serves the app.
                ->where('table.rows.0.href', "/music/songs/{$song->id}")
            );
    }

    public function test_audiobook_chapters_stay_out_of_the_song_listing(): void
    {
        Track::factory()->count(2)->create();
        Track::factory()->audiobook()->create();

        $this->actingAs(User::factory()->create())
            ->get('/music/songs')
            ->assertInertia(fn (Assert $page) => $page->has('table.rows', 2));
    }

    public function test_search_covers_every_column_the_table_shows(): void
    {
        // One song per searchable column, each findable only through that column —
        // title, artist, album, genre. The album case is the one that surprises:
        // "Moto" is a substring of Badmotorfinger, so it returns the whole album.
        $songs = [
            'title' => Track::factory()->create(['name' => 'Rusty Cage']),
            'artist' => Track::factory()->create([
                'artist_id' => Artist::factory()->create(['name' => 'Soundgarden'])->id,
            ]),
            'album' => Track::factory()->create([
                'collection_id' => Collection::factory()->create(['name' => 'Badmotorfinger'])->id,
            ]),
            'genre' => Track::factory()->create([
                'genre_id' => Genre::factory()->create(['name' => 'Grunge'])->id,
            ]),
        ];

        $user = User::factory()->create();

        foreach (['Rusty' => 'title', 'Soundgar' => 'artist', 'Moto' => 'album', 'Grun' => 'genre'] as $term => $key) {
            $this->actingAs($user)
                ->get('/music/songs?search='.$term)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('table.rows', 1)
                    ->where('table.rows.0.id', $songs[$key]->id)
                );
        }
    }

    public function test_search_ignores_accents_and_case(): void
    {
        $song = Track::factory()->create([
            'artist_id' => Artist::factory()->create(['name' => 'Mgła'])->id,
        ]);
        Track::factory()->create(['artist_id' => Artist::factory()->create(['name' => 'Deafheaven'])->id]);

        $user = User::factory()->create();

        // Typed without the accent, shouted, and typed exactly — all three must
        // land on the same row, because both sides go through the same fold.
        foreach (['mgla', 'MGLA', 'Mgła', 'MGŁA'] as $term) {
            $this->actingAs($user)
                ->get('/music/songs?search='.urlencode($term))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('table.rows', 1)
                    ->where('table.rows.0.id', $song->id)
                );
        }
    }

    public function test_search_still_finds_a_name_with_no_ascii_form(): void
    {
        // Folding KEEPS what it cannot transliterate, so a CJK title stays
        // searchable as typed — the case that would have been lost had the fold
        // simply dropped unmappable characters.
        $song = Track::factory()->create(['name' => 'Bloody Tyrant 暴君']);
        Track::factory()->create(['name' => 'Something Else Entirely']);

        $this->actingAs(User::factory()->create())
            ->get('/music/songs?search='.urlencode('暴君'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 1)
                ->where('table.rows.0.id', $song->id)
            );
    }

    public function test_the_table_reports_its_unfiltered_size_alongside_the_filtered_one(): void
    {
        // What the frontend hides the pager by. The two numbers are the same until a search
        // narrows the table, and then they must differ — judged on the filtered count the
        // control would appear and vanish as somebody types.
        Track::factory()->count(3)->create(['name' => 'Sundowning']);
        Track::factory()->count(5)->create(['name' => 'Something Else']);

        $this->actingAs(User::factory()->create())
            ->get('/music/songs?search=Sundown')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.total', 3)
                ->where('table.totalUnfiltered', 8)
            );
    }

    public function test_an_unsearched_table_reports_the_same_number_twice(): void
    {
        // The common request pays for no second COUNT: with no search the filtered total
        // already IS the unfiltered one.
        Track::factory()->count(4)->create();

        $this->actingAs(User::factory()->create())
            ->get('/music/songs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('table.total', 4)
                ->where('table.totalUnfiltered', 4)
            );
    }

    public function test_search_leaves_the_media_type_scope_alone(): void
    {
        // The OR'd column matches are nested inside their own where(), so they
        // cannot escape and drag audiobook chapters into a music listing.
        Track::factory()->create(['name' => 'Sundowning']);
        Track::factory()->audiobook()->create(['name' => 'Sundowning']);

        $this->actingAs(User::factory()->create())
            ->get('/music/songs?search=Sundown')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('table.rows', 1));
    }

    /**
     * THE WIDE SEARCH IS THE DEFAULT, and it is what a reader who came to browse wants: "black"
     * finds a song called Black, everything by an artist with Black in their name, and everything
     * filed under Black Metal.
     */
    public function test_the_default_search_matches_artist_album_and_genre_as_well_as_the_title(): void
    {
        Track::factory()->create(['name' => 'Back in Black']);
        Track::factory()->create(['name' => 'Paranoid', 'artist_id' => Artist::factory()->create(['name' => 'Black Sabbath'])->id]);
        Track::factory()->create(['name' => 'Freezing Moon', 'genre_id' => Genre::factory()->create(['name' => 'Black Metal'])->id]);
        Track::factory()->create(['name' => 'Enter Sandman', 'collection_id' => Collection::factory()->create(['name' => 'Black Album'])->id]);

        $this->actingAs(User::factory()->create())
            ->get('/music/songs?search=black')
            ->assertInertia(fn (Assert $page) => $page->has('table.rows', 4)->where('table.searchIn', null));
    }

    /**
     * …AND `?searchIn=name` NARROWS IT TO THE TITLE, which is the mode the cross-kind search
     * dropdown hands off in. It exists because the two disagreed in front of the reader: the
     * dropdown counted 70 songs called something with Black in the title and its "show all" opened
     * a table of 2,000+ (the owner's report, 2026-08-13). Same query, one reading.
     */
    public function test_searching_in_the_name_matches_the_title_alone(): void
    {
        Track::factory()->create(['name' => 'Back in Black']);
        Track::factory()->create(['name' => 'Paranoid', 'artist_id' => Artist::factory()->create(['name' => 'Black Sabbath'])->id]);
        Track::factory()->create(['name' => 'Freezing Moon', 'genre_id' => Genre::factory()->create(['name' => 'Black Metal'])->id]);

        $this->actingAs(User::factory()->create())
            ->get('/music/songs?search=black&searchIn=name')
            ->assertInertia(fn (Assert $page) => $page
                ->has('table.rows', 1)
                ->where('table.rows.0.name', 'Back in Black')
                // Echoed back, which is how the toolbar knows to say the table is narrowed and to
                // offer the way out of it.
                ->where('table.searchIn', 'name')
            );
    }

    /** An unknown mode is not a search of its own — it falls back to the listing's default. */
    public function test_an_unknown_search_mode_leaves_the_wide_search_in_place(): void
    {
        Track::factory()->create(['name' => 'Back in Black']);
        Track::factory()->create(['name' => 'Paranoid', 'artist_id' => Artist::factory()->create(['name' => 'Black Sabbath'])->id]);

        $this->actingAs(User::factory()->create())
            ->get('/music/songs?search=black&searchIn=nonsense')
            ->assertInertia(fn (Assert $page) => $page->has('table.rows', 2)->where('table.searchIn', null));
    }
}
