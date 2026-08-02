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
}
