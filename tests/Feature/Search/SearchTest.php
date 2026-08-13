<?php

namespace Tests\Feature\Search;

use App\Models\Artist;
use App\Models\Author;
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
            ->assertJsonPath('groups.0.rows.0.facts.tracks', 3)
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
            // `searchIn=name` is what makes the offer honest: the listing's own search is wider
            // than this group's, so without the mode "all 7" would open a table of rather more
            // than 7 (DataTableService::SEARCH_IN_NAME).
            ->assertJsonPath('groups.0.seeAll', '/music/songs?search=black&searchIn=name');
    }

    /**
     * The hand-off carries the mode only where the listing HAS two readings. Artists and genres
     * already match nothing but the name, so a mode there would be a claim with nothing behind it —
     * and the toolbar would offer a way out of a narrowing that never happened.
     */
    public function test_only_the_wider_listings_are_handed_off_narrowed(): void
    {
        foreach (range(1, 6) as $number) {
            Artist::factory()->create(['name' => "Black Artist {$number}"]);
            Collection::factory()->create(['name' => "Black Album {$number}"]);
        }

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=black&kinds=artist,album')
            ->assertOk()
            ->assertJsonPath('groups.0.seeAll', '/music/artists?search=black')
            ->assertJsonPath('groups.1.seeAll', '/music/albums?search=black&searchIn=name');
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
     * THE TWO FACTS EACH KIND CARRIES (the owner's set, 2026-08-13) — a dropdown of rows all
     * called "Black" is a dropdown a reader cannot choose from, and which two facts tell them
     * apart is a per-kind decision worth pinning rather than re-deriving:
     *
     *   artist → albums, total runtime      album → artist, tracks
     *   song   → artist, runtime            genre → artists, songs
     *
     * All RAW: seconds, not clocks, and counts without separators. The client draws them as pips.
     */
    public function test_every_row_carries_the_two_facts_its_kind_shows(): void
    {
        $artist = Artist::factory()->create(['name' => 'Blackfield']);
        $album = Collection::factory()->create(['name' => 'Blackout', 'album_artist_id' => $artist->id]);
        Collection::factory()->create(['album_artist_id' => $artist->id]);

        $genre = Genre::factory()->create(['name' => 'Blackgaze']);
        Track::factory()->count(2)->create(['genre_id' => $genre->id, 'artist_id' => $artist->id, 'duration' => 100.0]);

        Track::factory()->create([
            'name' => 'Black Dog',
            'artist_id' => $artist->id,
            'collection_id' => $album->id,
            'duration' => 285.5,
        ]);

        $response = $this->actingAs(User::factory()->create())->getJson('/search?q=black')->assertOk();

        // Artists: their discography, and what their own tracks add up to (2 × 100 + 285.5).
        $response->assertJsonPath('groups.0.kind', 'artist')
            ->assertJsonPath('groups.0.rows.0.facts.albums', 2)
            ->assertJsonPath('groups.0.rows.0.facts.duration', 485.5);

        // Albums: who it is by, and how many tracks it holds.
        $response->assertJsonPath('groups.1.kind', 'album')
            ->assertJsonPath('groups.1.rows.0.facts.artist', 'Blackfield')
            ->assertJsonPath('groups.1.rows.0.facts.songs', 1);

        // Songs: who performs it, and how long it runs — the track's own column, raw seconds.
        $response->assertJsonPath('groups.2.kind', 'song')
            ->assertJsonPath('groups.2.rows.0.facts.artist', 'Blackfield')
            ->assertJsonPath('groups.2.rows.0.facts.duration', 285.5);

        // Genres: artists whose MAIN genre it is, and every music track carrying it.
        $response->assertJsonPath('groups.3.kind', 'genre')
            ->assertJsonPath('groups.3.rows.0.facts.artists', 1)
            ->assertJsonPath('groups.3.rows.0.facts.songs', 2);
    }

    /** A playlist's two facts, over its pivot — a song held twice is two entries and twice as long. */
    public function test_a_playlist_row_carries_its_length_and_runtime(): void
    {
        $reader = User::factory()->create();
        $playlist = Playlist::factory()->for($reader)->create(['name' => 'Blackout']);
        $track = Track::factory()->create(['duration' => 200.0]);

        foreach ([0, 1] as $position) {
            PlaylistTrack::factory()->create([
                'playlist_id' => $playlist->id,
                'track_id' => $track->id,
                'position' => $position,
            ]);
        }

        $this->actingAs($reader)
            ->getJson('/search?q=black&kinds=playlist')
            ->assertOk()
            ->assertJsonPath('groups.0.rows.0.facts.tracks', 2)
            // 400, not 200: the same track twice really does play twice — the opposite of the
            // rule play COUNTS need over this pivot.
            //
            // Asserted as an INT because that is what lands on the wire: `json_encode` drops a
            // whole float's fraction without JSON_PRESERVE_ZERO_FRACTION, so 400.0 travels as
            // `400` while the 485.5 above keeps its point. Immaterial to the client, where both
            // are `number` — but `assertJsonPath` compares strictly.
            ->assertJsonPath('groups.0.rows.0.facts.duration', 400);
    }

    /**
     * A MISSING FACT IS NULL, NOT ZERO, and the client draws no pip for it. Both cases here are
     * real: a file whose tags carried no duration, and an artist credited on albums who performs
     * no tracks of their own (a compilation owner). "0:00" on either would read as a broken row.
     */
    public function test_a_fact_the_row_does_not_have_is_null_rather_than_zero(): void
    {
        $artist = Artist::factory()->create(['name' => 'Black Compilations']);
        Collection::factory()->create(['album_artist_id' => $artist->id]);
        Track::factory()->create(['name' => 'Black Untagged', 'duration' => null]);

        $response = $this->actingAs(User::factory()->create())->getJson('/search?q=black')->assertOk();

        $response->assertJsonPath('groups.0.rows.0.facts.albums', 1)
            ->assertJsonPath('groups.0.rows.0.facts.duration', null);
        $response->assertJsonPath('groups.1.kind', 'song')
            ->assertJsonPath('groups.1.rows.0.facts.duration', null);
    }

    public function test_an_audiobook_is_found_by_its_own_title(): void
    {
        $book = Collection::factory()->audiobook()->create(['name' => 'Berge des Wahnsinns']);
        Track::factory()->audiobook()->count(2)->create(['collection_id' => $book->id]);

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=wahnsinns&kinds=audiobook')
            ->assertOk()
            ->assertJsonPath('groups.0.kind', 'audiobook')
            ->assertJsonPath('groups.0.total', 1)
            ->assertJsonPath('groups.0.rows.0.id', $book->id)
            ->assertJsonPath('groups.0.rows.0.href', "/audiobooks/{$book->id}")
            // A NUMBER, never a phrase — "2 Kapitel" composed here would be German on a page
            // read in English.
            ->assertJsonPath('groups.0.rows.0.facts.tracks', 2)
            // No listing at `?search=` to hand off to: the area page's tabs are a browse, not
            // a search result, and pointing "show all" at a page that ignores the query is
            // worse than not offering it.
            ->assertJsonPath('groups.0.seeAll', null);
    }

    public function test_an_audiobook_is_not_found_by_its_author(): void
    {
        /*
         * The own-name rule, one area over. Searching a book by its author would make
         * "Lovecraft" return six books, when what a reader typing a person's name wants is the
         * person — and an author has no page here to be an answer, so the honest result for a
         * name is the BOOK, found by its title.
         */
        $book = Collection::factory()->audiobook()->create(['name' => 'Berge des Wahnsinns']);
        Track::factory()->audiobook()->create([
            'collection_id' => $book->id,
            'author_id' => Author::factory()->create(['name' => 'H.P. Lovecraft'])->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/search?q=lovecraft&kinds=audiobook')
            ->assertOk()
            ->assertJsonCount(0, 'groups');
    }

    public function test_an_album_and_an_audiobook_of_the_same_name_answer_as_themselves(): void
    {
        // `collections` is one table with a `type` discriminator, so the two kinds are the same
        // query with opposite filters — which is exactly why they are two registry entries
        // rather than one kind with a branch inside it.
        Collection::factory()->create(['name' => 'Solarstation']);
        Collection::factory()->audiobook()->create(['name' => 'Solarstation']);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/search?q=solarstation')
            ->assertOk();

        $kinds = array_column($response->json('groups'), 'kind');
        $this->assertContains('album', $kinds);
        $this->assertContains('audiobook', $kinds);
    }

    public function test_narrowing_to_the_music_kinds_leaves_audiobooks_out(): void
    {
        // What the Music page's field sends (`only`), and the whole of keeping the areas apart:
        // a book turning up there would send a reader somewhere they were not browsing.
        Collection::factory()->create(['name' => 'Solarstation']);
        Collection::factory()->audiobook()->create(['name' => 'Solarstation']);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/search?q=solarstation&kinds=artist,album,playlist,song,genre')
            ->assertOk();

        $this->assertNotContains('audiobook', array_column($response->json('groups'), 'kind'));
    }
}
