<?php

namespace Tests\Feature\Search;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cross-kind search endpoint (`GET /search`, behind auth) — docs/search.md.
 *
 * PHPUnit owns the ENGINE, because nearly all of it is a server decision, and the file is
 * ordered by how badly each rule would be missed if a later "improvement" broke it:
 *
 *   1. A row matches its OWN name. This is the rule the whole feature turns on and the one
 *      most likely to be "fixed" back into a wide match — 77 songs against 1,238 for one
 *      query — so it is asserted from both directions: a song IS found by its title, and is
 *      NOT found by its artist's, album's or genre's.
 *   2. The four ranking tiers, and that the tie-break is TOTAL. A partial order under
 *      `LIMIT 5` returns different rows for identical queries, which a reader sees as results
 *      flickering as they type.
 *   3. The type narrowings. `tracks` and `collections` are unified tables; an audiobook
 *      answering a music search would link to a page that does not exist.
 *   4. Playlists are the caller's own, and a stranger's never appears whatever it is called.
 *   5. `kinds=` narrows without reordering, and the totals are the real counts rather than the
 *      length of the truncated list.
 *
 * The GROUP ORDER is asserted by index rather than by searching the payload for a kind: the
 * order is the contract (artists → albums → playlists → songs → genres), and a test that looked
 * each kind up by name would pass with the groups in any order at all.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/search?q=black')->assertRedirect('/login');
    }

    public function test_it_refuses_a_query_shorter_than_three_characters(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=bl')
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    /**
     * Whitespace is trimmed BEFORE the floor is measured, so "  a  " is refused as a missing
     * query rather than accepted as five characters of input.
     */
    public function test_a_query_of_nothing_but_spaces_is_no_query_at_all(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/search?q='.urlencode('   '))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_it_refuses_a_kind_it_does_not_know(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=black&kinds=podcast')
            ->assertStatus(422)
            ->assertJsonValidationErrors('kinds.0');
    }

    /**
     * The response must never be stored anywhere: one of its five kinds is the reader's own
     * playlists, so two accounts asking the same question get different answers.
     */
    public function test_the_answer_is_marked_private_and_uncacheable(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=black')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    // ---------------------------------------------------------------- (1) own name only

    public function test_a_song_is_found_by_its_own_title(): void
    {
        $song = Track::factory()->create(['name' => 'Back in Black']);

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=black&kinds=song')
            ->assertOk()
            ->assertJsonPath('groups.0.kind', 'song')
            ->assertJsonPath('groups.0.total', 1)
            ->assertJsonPath('groups.0.rows.0.id', $song->id)
            ->assertJsonPath('groups.0.rows.0.href', "/music/songs/{$song->id}");
    }

    /**
     * THE FEATURE'S CENTRAL RULE. Every one of these three songs would be returned by the
     * Songs LISTING's wide search, which matches artist, album and genre as well — and that is
     * exactly where the wide reading belongs, behind "see all". In a dropdown it buries the
     * artist the reader probably meant under two hundred of their tracks.
     */
    public function test_a_song_is_not_found_by_its_artist_album_or_genre(): void
    {
        $blackArtist = Artist::factory()->create(['name' => 'Black Sabbath']);
        $blackAlbum = Collection::factory()->create(['name' => 'Black Album']);
        $blackGenre = Genre::factory()->create(['name' => 'Black Metal']);

        Track::factory()->create(['name' => 'Paranoid', 'artist_id' => $blackArtist->id]);
        Track::factory()->create(['name' => 'Enter Sandman', 'collection_id' => $blackAlbum->id]);
        Track::factory()->create(['name' => 'Freezing Moon', 'genre_id' => $blackGenre->id]);

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=black&kinds=song')
            ->assertOk()
            // No song group at all — the artist, album and genre are the answers, each as
            // itself, and they are asked for separately below.
            ->assertJsonCount(0, 'groups');
    }

    public function test_the_artist_album_and_genre_answer_as_themselves(): void
    {
        Artist::factory()->create(['name' => 'Black Sabbath']);
        Collection::factory()->create(['name' => 'Black Album']);
        Genre::factory()->create(['name' => 'Black Metal']);

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=black')
            ->assertOk()
            // The fixed order, by index: artists, then albums, then genres (playlists and
            // songs have nothing here, and an empty group is dropped rather than sent).
            ->assertJsonPath('groups.0.kind', 'artist')
            ->assertJsonPath('groups.1.kind', 'album')
            ->assertJsonPath('groups.2.kind', 'genre')
            ->assertJsonCount(3, 'groups');
    }

    /** A playlist is the one kind matched on a second column — the blurb its owner wrote. */
    public function test_a_playlist_is_found_by_its_description_as_well_as_its_name(): void
    {
        $reader = User::factory()->create();
        Playlist::factory()->for($reader)->create([
            'name' => 'Sonntagmorgen',
            'description' => 'Für die lange Fahrt.',
        ]);

        $this->actingAs($reader)
            ->getJson('/search?q='.urlencode('fahrt').'&kinds=playlist')
            ->assertOk()
            ->assertJsonPath('groups.0.rows.0.name', 'Sonntagmorgen');
    }

    /** The fold columns are what make this work on both drivers — see FoldedSearch. */
    public function test_the_search_is_accent_and_case_insensitive(): void
    {
        Artist::factory()->create(['name' => 'Sigur Rós']);

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=ROS&kinds=artist')
            ->assertOk()
            ->assertJsonPath('groups.0.rows.0.name', 'Sigur Rós');
    }

    // ---------------------------------------------------------------- (2) ranking

    /**
     * Exact, then starts-with, then word-start, then anywhere else. Every one of these five
     * contains "black", so nothing but the tiers can be putting them in this order.
     */
    public function test_the_four_ranking_tiers_order_the_matches(): void
    {
        foreach (['Blackberry Way', 'Back in Black', 'Black', 'Unblackened', 'Black Dog'] as $name) {
            Track::factory()->create(['name' => $name]);
        }

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=black&kinds=song')
            ->assertOk()
            ->assertJsonPath('groups.0.rows.0.name', 'Black')          // exact
            ->assertJsonPath('groups.0.rows.1.name', 'Black Dog')      // starts with, then A→Z
            ->assertJsonPath('groups.0.rows.2.name', 'Blackberry Way') // starts with
            ->assertJsonPath('groups.0.rows.3.name', 'Back in Black')  // word start
            ->assertJsonPath('groups.0.rows.4.name', 'Unblackened');   // anywhere else
    }

    /**
     * THE TIE-BREAK HAS TO BE TOTAL. Six songs with the SAME title cannot be separated by the
     * tiers or by the name, so with `LIMIT 5` over a partial order the engine may legally
     * return a different five each time — which a reader sees as the list flickering while
     * they type. Asking twice and comparing is the only assertion that can catch that.
     */
    public function test_identical_names_still_return_the_same_five_rows_every_time(): void
    {
        Track::factory()->count(6)->create(['name' => 'Untitled']);

        $reader = User::factory()->create();

        $first = $this->actingAs($reader)->getJson('/search?q=untitled&kinds=song')->json('groups.0.rows');
        $second = $this->actingAs($reader)->getJson('/search?q=untitled&kinds=song')->json('groups.0.rows');

        $this->assertCount(5, $first);
        $this->assertSame(array_column($first, 'id'), array_column($second, 'id'));
    }

    // ---------------------------------------------------------------- (3) type narrowings

    public function test_an_audiobook_chapter_never_answers_a_song_search(): void
    {
        Track::factory()->audiobook()->create(['name' => 'Black Chapter One']);

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=black&kinds=song')
            ->assertOk()
            ->assertJsonCount(0, 'groups');
    }

    public function test_an_audiobook_never_answers_an_album_search(): void
    {
        Collection::factory()->audiobook()->create(['name' => 'Black Swan']);

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=black&kinds=album')
            ->assertOk()
            ->assertJsonCount(0, 'groups');
    }

    // ---------------------------------------------------------------- (4) playlist scope

    public function test_a_stranger_playlist_never_appears(): void
    {
        $reader = User::factory()->create();
        Playlist::factory()->for(User::factory()->create())->create(['name' => 'Blackout']);

        $this->actingAs($reader)
            ->getJson('/search?q=black&kinds=playlist')
            ->assertOk()
            ->assertJsonCount(0, 'groups');
    }

    public function test_the_reader_own_playlist_appears_with_its_track_count(): void
    {
        $reader = User::factory()->create();
        $playlist = Playlist::factory()->for($reader)->create(['name' => 'Blackout']);

        foreach (Track::factory()->count(3)->create() as $index => $track) {
            PlaylistTrack::factory()->create([
                'playlist_id' => $playlist->id,
                'track_id' => $track->id,
                'position' => $index,
            ]);
        }

        $this->actingAs($reader)
            ->getJson('/search?q=black&kinds=playlist')
            ->assertOk()
            ->assertJsonPath('groups.0.rows.0.count', 3)
            ->assertJsonPath('groups.0.rows.0.href', "/playlists/{$playlist->id}")
            // No hand-off: the playlists listing is a hand-ordered list with no `?search=`.
            ->assertJsonPath('groups.0.seeAll', null);
    }

    // ---------------------------------------------------------------- (5) totals and narrowing

    /**
     * The total is the real count, not the length of the truncated list — that is what lets a
     * group header say "seven" while showing five, and what decides that a hand-off is worth
     * offering at all.
     */
    public function test_a_group_reports_its_real_total_and_offers_the_wide_listing(): void
    {
        foreach (range(1, 7) as $number) {
            Track::factory()->create(['name' => "Black Song {$number}"]);
        }

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=black&kinds=song')
            ->assertOk()
            ->assertJsonPath('groups.0.total', 7)
            ->assertJsonCount(5, 'groups.0.rows')
            ->assertJsonPath('groups.0.seeAll', '/music/songs?search=black');
    }

    /** Nothing more to see means nothing to offer — the hand-off is not decoration. */
    public function test_a_group_that_fits_offers_no_hand_off(): void
    {
        Track::factory()->create(['name' => 'Back in Black']);

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=black&kinds=song')
            ->assertOk()
            ->assertJsonPath('groups.0.total', 1)
            ->assertJsonPath('groups.0.seeAll', null);
    }

    /**
     * `kinds=` narrows the answer and cannot reorder it: asked for genres before artists, the
     * groups still arrive artists-first, because the order is the enum's rather than the URL's.
     */
    public function test_the_kind_filter_narrows_without_reordering(): void
    {
        Artist::factory()->create(['name' => 'Black Sabbath']);
        Genre::factory()->create(['name' => 'Black Metal']);
        Collection::factory()->create(['name' => 'Black Album']);

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=black&kinds=genre,artist')
            ->assertOk()
            ->assertJsonCount(2, 'groups')
            ->assertJsonPath('groups.0.kind', 'artist')
            ->assertJsonPath('groups.1.kind', 'genre');
    }

    /** An empty filter is the same statement as no filter: every kind may answer. */
    public function test_an_empty_kind_filter_means_every_kind(): void
    {
        Artist::factory()->create(['name' => 'Black Sabbath']);

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=black&kinds=')
            ->assertOk()
            ->assertJsonPath('groups.0.kind', 'artist');
    }

    /**
     * The second line of each row, per kind — a dropdown of rows all called "Black" is a
     * dropdown a reader cannot choose from.
     */
    public function test_every_row_carries_one_line_of_context(): void
    {
        $artist = Artist::factory()->create(['name' => 'Blackfield']);
        Collection::factory()->count(2)->create(['album_artist_id' => $artist->id]);

        $genre = Genre::factory()->create(['name' => 'Blackgaze']);
        Track::factory()->count(2)->create(['genre_id' => $genre->id]);

        Track::factory()->create(['name' => 'Black Dog', 'artist_id' => $artist->id]);

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=black')
            ->assertOk()
            // An artist counts their discography; a song names its performer; a genre counts
            // its songs. Raw values either way — the client pluralises and prints.
            ->assertJsonPath('groups.0.rows.0.count', 2)
            ->assertJsonPath('groups.0.rows.0.text', null)
            ->assertJsonPath('groups.1.rows.0.text', 'Blackfield')
            ->assertJsonPath('groups.2.rows.0.count', 2);
    }
}
