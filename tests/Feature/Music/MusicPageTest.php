<?php

namespace Tests\Feature\Music;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Play;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Music browse page (`/music`, behind auth) — four widgets (albums,
 * artists, genres, songs), each carrying a latest + random set capped at four
 * entries (MusicController).
 */
class MusicPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/music')->assertRedirect('/login');
    }

    public function test_authenticated_user_sees_the_music_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Music/MusicPage')
                ->has('albums.latest')->has('albums.random')
                ->has('artists.latest')->has('artists.random')
                ->has('genres.latest')->has('genres.random')
                ->has('songs.latest')->has('songs.random')
                // Every widget ships all three modes; what "popular" ranks by is what
                // differs between them (MusicController).
                ->has('albums.popular')
                ->has('songs.popular')
                ->has('artists.popular')
                ->has('genres.popular')
                // the stats widget's collection totals.
                ->has('stats', fn (Assert $stats) => $stats->hasAll([
                    'songs', 'sizeBytes', 'playtimeSeconds', 'albums', 'artists', 'genres',
                    'firstYear', 'lastYear',
                ]))
            );
    }

    public function test_each_widget_is_capped_at_four_entries_with_the_expected_shape(): void
    {
        // Six music tracks pull in six albums / artists / genres through their
        // FKs, so every widget has more than four candidates to cap.
        $user = User::factory()->create();
        $tracks = Track::factory()->count(6)->create();
        // The songs and albums "popular" sets only rank what has been played, so give every
        // track a listen or those two would be empty rather than capped.
        $tracks->each(fn (Track $t) => Play::factory()->create(['track_id' => $t->id, 'user_id' => $user->id]));

        $this->actingAs($user)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->has('albums.latest', 4)->has('albums.random', 4)->has('albums.popular', 4)
                ->has('artists.latest', 4)->has('artists.random', 4)->has('artists.popular', 4)
                ->has('genres.latest', 4)->has('genres.random', 4)->has('genres.popular', 4)
                ->has('songs.latest', 4)->has('songs.random', 4)->has('songs.popular', 4)
                // Every entry carries what its card renders: a name, the link the whole row
                // becomes, and the facts it shows as pips. Asserted exhaustively (`hasAll`
                // fails on extras too) so a field cannot quietly appear in the payload
                // without someone deciding the card should show it.
                ->has('albums.latest.0', fn (Assert $album) => $album->hasAll(['id', 'name', 'artist', 'year', 'plays', 'href']))
                ->has('songs.latest.0', fn (Assert $song) => $song->hasAll(['id', 'name', 'artist', 'year', 'plays', 'href']))
                ->has('artists.latest.0', fn (Assert $artist) => $artist->hasAll(['id', 'name', 'albums', 'songs', 'duration', 'plays', 'href']))
                ->has('genres.latest.0', fn (Assert $genre) => $genre->hasAll(['id', 'name', 'artists', 'albums', 'songs', 'plays', 'href']))
            );
    }

    public function test_an_artists_widget_entry_counts_its_own_albums_songs_and_playing_time(): void
    {
        // The three numbers its pips show. `albums` is what the artist is the ALBUM-ARTIST
        // of — the same relation their own page's discography lists — not every album
        // holding a track of theirs, which would count each compilation they appear on.
        $artist = Artist::factory()->create();
        $ownAlbum = Collection::factory()->create(['album_artist_id' => $artist->id]);
        $compilation = Collection::factory()->create(['album_artist_id' => null]);

        Track::factory()->count(2)->create([
            'artist_id' => $artist->id,
            'collection_id' => $ownAlbum->id,
            'duration' => 100.0,
        ]);
        Track::factory()->create([
            'artist_id' => $artist->id,
            'collection_id' => $compilation->id,
            'duration' => 50.0,
        ]);

        $response = $this->actingAs(User::factory()->create())->get('/music');
        $entry = collect($this->inertiaProp($response, 'artists.latest'))->firstWhere('id', $artist->id);

        $this->assertSame(1, $entry['albums'], 'the compilation is not their album');
        $this->assertSame(3, $entry['songs'], 'but all three tracks are their songs');
        $this->assertSame(250.0, $entry['duration']);
    }

    public function test_a_genres_widget_entry_counts_by_the_same_rules_its_own_page_uses(): void
    {
        // artists and albums by DOMINANT genre, songs literally — so a reader meeting this
        // genre on its own page is not told two different things.
        $genre = Genre::factory()->create();
        $other = Genre::factory()->create();

        // Mostly-this-genre artist, and their album: both belong here.
        $native = Artist::factory()->create();
        $album = Collection::factory()->create(['album_artist_id' => $native->id]);
        Track::factory()->count(3)->create([
            'artist_id' => $native->id,
            'collection_id' => $album->id,
            'genre_id' => $genre->id,
        ]);

        // A dabbler, mostly the other genre: their one track counts as a song here, but
        // they are not one of this genre's artists.
        // `collection_id => null` throughout: Track::factory() mints an album per track
        // otherwise, and each of those would be an album of whichever genre its one track
        // carries — which is not what these two numbers are being tested for.
        $dabbler = Artist::factory()->create();
        Track::factory()->create(['artist_id' => $dabbler->id, 'genre_id' => $genre->id, 'collection_id' => null]);
        Track::factory()->count(4)->create(['artist_id' => $dabbler->id, 'genre_id' => $other->id, 'collection_id' => null]);

        $response = $this->actingAs(User::factory()->create())->get('/music');
        $entry = collect($this->inertiaProp($response, 'genres.latest'))->firstWhere('id', $genre->id);

        $this->assertSame(1, $entry['artists'], 'the dabbler is not one of this genre\'s artists');
        $this->assertSame(1, $entry['albums']);
        $this->assertSame(4, $entry['songs'], 'but their track is still one of its songs');
    }

    public function test_a_genre_that_is_nobodys_main_genre_reports_zero_rather_than_null(): void
    {
        // The LEFT joins: a genre can hold songs without winning anyone, and the pips must
        // read 0 instead of blanking.
        $genre = Genre::factory()->create();
        $other = Genre::factory()->create();
        $artist = Artist::factory()->create();

        // Loose tracks (see the note above) so no album is minted for either genre.
        Track::factory()->create(['artist_id' => $artist->id, 'genre_id' => $genre->id, 'collection_id' => null]);
        Track::factory()->count(5)->create(['artist_id' => $artist->id, 'genre_id' => $other->id, 'collection_id' => null]);

        $response = $this->actingAs(User::factory()->create())->get('/music');
        $entry = collect($this->inertiaProp($response, 'genres.latest'))->firstWhere('id', $genre->id);

        $this->assertSame(0, $entry['artists']);
        $this->assertSame(0, $entry['albums']);
        $this->assertSame(1, $entry['songs']);
    }

    public function test_artists_widget_excludes_artists_with_no_tracks(): void
    {
        // Two performers (each Track::factory mints its own artist) plus one
        // album-artist-only artist — a compilation owner like "Irish Folk
        // Festival" that performs nothing, so its max(modified_at) is NULL.
        // Postgres sorts that NULL to the TOP of "latest" (the reported bug);
        // the controller's has('tracks') filter drops it. Both modes should
        // therefore return only the two real performers. (On SQLite the NULL
        // would sort last, not first, so this asserts the filter itself — count
        // 2, not 3 — independently of the DB's NULL ordering.)
        Track::factory()->count(2)->create();
        Artist::factory()->create(['name' => 'No Tracks Compilation']);

        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->has('artists.latest', 2)
                ->has('artists.random', 2)
            );
    }

    public function test_albums_popular_ranks_the_readers_own_plays_and_leaves_out_everything_else(): void
    {
        // The albums card takes the SONGS rule rather than the taxonomies': an album nobody
        // has played has no place in a most-played list, so there is no duration fall-back to
        // keep the unplayed ones in. The fixture holds all three ways out of the set — never
        // played at all, played only by the housemate, and having no tracks to play.
        $reader = User::factory()->create();
        $housemate = User::factory()->create();

        $hot = Collection::factory()->create(['name' => 'Hot Album']);
        $cold = Collection::factory()->create(['name' => 'Cold Album']);
        $theirs = Collection::factory()->create(['name' => 'Housemate Album']);
        $untouched = Collection::factory()->create(['name' => 'Untouched Album']);

        $hotTrack = Track::factory()->create(['collection_id' => $hot->id]);
        $coldTrack = Track::factory()->create(['collection_id' => $cold->id]);
        $theirsTrack = Track::factory()->create(['collection_id' => $theirs->id]);
        Track::factory()->create(['collection_id' => $untouched->id]);

        Play::factory()->count(5)->create(['track_id' => $hotTrack->id, 'user_id' => $reader->id]);
        Play::factory()->count(1)->create(['track_id' => $coldTrack->id, 'user_id' => $reader->id]);
        Play::factory()->count(40)->create(['track_id' => $theirsTrack->id, 'user_id' => $housemate->id]);

        $this->actingAs($reader)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                // TWO rows: the housemate's forty listens are not this reader's, and the
                // ranking has to agree with the play pip printed beside it.
                ->has('albums.popular', 2)
                ->where('albums.popular.0.name', 'Hot Album')
                ->where('albums.popular.0.plays', 5)
                ->where('albums.popular.1.name', 'Cold Album')
                ->where('albums.popular.1.plays', 1)
            );
    }

    public function test_albums_popular_breaks_ties_totally_so_the_same_press_returns_the_same_order(): void
    {
        // Equal counts are the NORMAL case on a young `plays` table, and the card's refresh
        // button re-runs this query. Without a total order the engine may answer with a
        // different four each press — which a reader reads as the random mode leaking in.
        $reader = User::factory()->create();
        $albums = Collection::factory()->count(3)->create();

        $albums->each(function (Collection $album) use ($reader) {
            $track = Track::factory()->create(['collection_id' => $album->id]);
            Play::factory()->create(['track_id' => $track->id, 'user_id' => $reader->id]);
        });

        // The tie-break is the id, and hex digits sort identically under every collation the
        // two engines offer — which is what makes this assertable at all (see SearchRanking).
        $expected = $albums->pluck('id')->sort()->values();

        $this->actingAs($reader)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->where('albums.popular.0.id', $expected[0])
                ->where('albums.popular.1.id', $expected[1])
                ->where('albums.popular.2.id', $expected[2])
            );
    }

    public function test_songs_popular_ranks_by_plays_and_includes_a_single_play(): void
    {
        $user = User::factory()->create();
        $hot = Track::factory()->create(['name' => 'Hot Track']);
        $warm = Track::factory()->create(['name' => 'Warm Track']);
        $cold = Track::factory()->create(['name' => 'Cold Track']);
        Track::factory()->create(['name' => 'Never Played']);
        Play::factory()->count(5)->create(['track_id' => $hot->id, 'user_id' => $user->id]);
        Play::factory()->count(2)->create(['track_id' => $warm->id, 'user_id' => $user->id]);
        Play::factory()->count(1)->create(['track_id' => $cold->id, 'user_id' => $user->id]);

        // Ranked by play count, and the SINGLE-play track is in it: gating at >1 hides the
        // answer on a library with three played songs. What stays out is the song nobody has
        // played — there is no ranking to put it in.
        $this->actingAs($user)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->has('songs.popular', 3)
                ->where('songs.popular.0.name', 'Hot Track')
                ->where('songs.popular.1.name', 'Warm Track')
                ->where('songs.popular.2.name', 'Cold Track')
            );
    }

    public function test_the_artists_card_holds_only_artists_this_reader_has_played(): void
    {
        // However much audio the unplayed one has. Total file duration used to ride behind the
        // play count as a second key, which kept this card populated — at the price of ranking
        // artists nobody had listened to, in a list that says "popular", above nothing but each
        // other, with a play pip beside them saying so.
        $reader = User::factory()->create();
        $played = Artist::factory()->create(['name' => 'Played Artist']);
        $bigger = Artist::factory()->create(['name' => 'Bigger Artist']);

        $track = Track::factory()->create(['artist_id' => $played->id, 'duration' => 100]);
        Track::factory()->create(['artist_id' => $bigger->id, 'duration' => 5000]);
        Play::factory()->create(['track_id' => $track->id, 'user_id' => $reader->id]);

        $this->actingAs($reader)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->has('artists.popular', 1)
                ->where('artists.popular.0.name', 'Played Artist')
            );
    }

    public function test_the_genres_card_holds_only_genres_this_reader_has_played(): void
    {
        $reader = User::factory()->create();
        $played = Genre::factory()->create(['name' => 'Played Genre']);
        $bigger = Genre::factory()->create(['name' => 'Bigger Genre']);

        $track = Track::factory()->create(['genre_id' => $played->id, 'duration' => 100]);
        Track::factory()->create(['genre_id' => $bigger->id, 'duration' => 5000]);
        Play::factory()->create(['track_id' => $track->id, 'user_id' => $reader->id]);

        $this->actingAs($reader)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->has('genres.popular', 1)
                ->where('genres.popular.0.name', 'Played Genre')
            );
    }

    public function test_every_popular_set_is_empty_until_something_has_been_played(): void
    {
        // All four at once, because they are one query shape (MusicController::mostPlayed) and
        // the answer has to be the same on every card: a library with plenty in it and no
        // listening yet has nothing to rank, and each card says "not enough data" rather than
        // ranking something else. The artists and genres cards OPEN on this mode, so this is
        // what a brand-new instance shows — deliberately, over an order the word does not mean.
        Track::factory()->count(4)->create();

        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->has('albums.popular', 0)
                ->has('songs.popular', 0)
                ->has('artists.popular', 0)
                ->has('genres.popular', 0)
                // …while the other two modes are full, which is what makes the empty one a
                // statement about listening rather than about the collection.
                ->has('albums.latest', 4)
                ->has('artists.random', 4)
            );
    }

    public function test_popular_ranks_by_the_viewers_own_listening_not_the_households(): void
    {
        // The order has to agree with the pip printed beside it, and the pip is the reader's
        // own. Ranking by the household would put a card showing "1×" above one showing "2×"
        // with nothing on screen to explain it — which is what a reader calls a bug.
        $reader = User::factory()->create();
        $housemate = User::factory()->create();

        $mine = Artist::factory()->create(['name' => 'Mine']);
        $theirs = Artist::factory()->create(['name' => 'Theirs']);
        $mineTrack = Track::factory()->create(['artist_id' => $mine->id, 'duration' => 100]);
        $theirsTrack = Track::factory()->create(['artist_id' => $theirs->id, 'duration' => 100]);

        Play::factory()->count(2)->create(['track_id' => $mineTrack->id, 'user_id' => $reader->id]);
        Play::factory()->count(40)->create(['track_id' => $theirsTrack->id, 'user_id' => $housemate->id]);

        $this->actingAs($reader)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page->where('artists.popular.0.name', 'Mine'));

        $this->actingAs($housemate)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page->where('artists.popular.0.name', 'Theirs'));
    }

    public function test_songs_popular_ranks_by_the_viewers_own_listening_too(): void
    {
        // Same rule for the songs card: it ranks the READER, not the household. A song only
        // the housemate has played is not in the reader's popular set at all.
        $reader = User::factory()->create();
        $housemate = User::factory()->create();
        $mine = Track::factory()->create(['name' => 'Mine']);
        $theirs = Track::factory()->create(['name' => 'Theirs']);

        Play::factory()->create(['track_id' => $mine->id, 'user_id' => $reader->id]);
        Play::factory()->count(40)->create(['track_id' => $theirs->id, 'user_id' => $housemate->id]);

        $this->actingAs($reader)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->has('songs.popular', 1)
                ->where('songs.popular.0.name', 'Mine')
            );
    }

    public function test_stats_count_music_only(): void
    {
        // Three music files (each mints its own album/artist/genre) plus one
        // audiobook chapter, which must NOT count toward the music stats.
        Track::factory()->count(3)->create(['size' => 1000]);
        Track::factory()->audiobook()->create(['size' => 999999]);

        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.songs', 3)
                ->where('stats.sizeBytes', 3000) // audiobook's size excluded
                ->where('stats.albums', 3)
                ->where('stats.artists', 3)
                ->where('stats.genres', 3)
            );
    }

    public function test_every_widget_entry_carries_the_readers_own_play_count(): void
    {
        // The only per-viewer number on this page. One track, played three times by the
        // reader and forty by a housemate: every card about that track — the song, its
        // album, its artist, its genre — says 3, and the housemate's page says 40.
        $reader = User::factory()->create();
        $housemate = User::factory()->create();
        $track = Track::factory()->create();

        Play::factory()->count(3)->create(['track_id' => $track->id, 'user_id' => $reader->id]);
        Play::factory()->count(40)->create(['track_id' => $track->id, 'user_id' => $housemate->id]);

        foreach ([[$reader, 3], [$housemate, 40]] as [$viewer, $expected]) {
            $this->actingAs($viewer)
                ->get('/music')
                ->assertInertia(fn (Assert $page) => $page
                    ->where('songs.latest.0.plays', $expected)
                    ->where('albums.latest.0.plays', $expected)
                    ->where('artists.latest.0.plays', $expected)
                    ->where('genres.latest.0.plays', $expected)
                );
        }
    }

    public function test_a_widget_entry_nobody_has_played_reports_zero(): void
    {
        // Zero is what the CARD turns into no pip at all; the server just counts.
        Track::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->where('songs.latest.0.plays', 0)
                ->where('artists.latest.0.plays', 0)
            );
    }

    public function test_the_songs_card_ranks_and_counts_the_readers_own_listening(): void
    {
        // BOTH HALVES ARE THE READER'S — the ranking and the pip beside it. That is the whole
        // point: a household ranking would put a card showing "1×" above one showing "5×", with
        // nothing on screen to explain the order, and an order that contradicts the number
        // printed on it reads as a broken sort.
        //
        // The fixture is built so the two readings DISAGREE: the housemate has played `other`
        // twice and `loved` twenty times, so a household ranking returns both songs. Only a
        // reader-scoped one returns the single song this reader has actually played.
        $reader = User::factory()->create();
        $housemate = User::factory()->create();
        $loved = Track::factory()->create();
        $other = Track::factory()->create();

        Play::factory()->count(1)->create(['track_id' => $loved->id, 'user_id' => $reader->id]);
        Play::factory()->count(20)->create(['track_id' => $loved->id, 'user_id' => $housemate->id]);
        Play::factory()->count(2)->create(['track_id' => $other->id, 'user_id' => $housemate->id]);

        $this->actingAs($reader)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                // ONE row, not two — the assertion that tells the two readings apart.
                ->has('songs.popular', 1)
                ->where('songs.popular.0.id', $loved->id)
                ->where('songs.popular.0.plays', 1)
            );
    }

    /**
     * The card's year RANGE, and the two things about it that are not a count.
     *
     * It is one fact drawn from two aggregates, and both are nullable: SQL's MIN/MAX skip rows with
     * no year, so a library of untagged albums has no range at all and the client drops the tile
     * rather than drawing a dash between two blanks.
     */
    public function test_the_stats_carry_the_oldest_and_newest_album_year(): void
    {
        Collection::factory()->create(['year' => 1994]);
        Collection::factory()->create(['year' => 2024]);
        Collection::factory()->create(['year' => 1965]);
        // Untagged, and an audiobook of its own — neither belongs in a MUSIC card's range.
        Collection::factory()->create(['year' => null]);
        Collection::factory()->audiobook()->create(['year' => 1901]);

        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.firstYear', 1965)
                ->where('stats.lastYear', 2024)
            );
    }

    /** No year anywhere is null rather than zero — the tile then draws nothing at all. */
    public function test_a_library_with_no_album_years_reports_no_range(): void
    {
        Collection::factory()->create(['year' => null]);

        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.firstYear', null)
                ->where('stats.lastYear', null)
            );
    }
}
