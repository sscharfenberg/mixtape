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
                // "popular" ships only for the three widgets that support it…
                ->has('songs.popular')
                ->has('artists.popular')
                ->has('genres.popular')
                // …never for albums (the owner scoped it out).
                ->missing('albums.popular')
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
        // Songs' "popular" only ranks songs that have been played, so give every track a
        // listen or that set would be empty rather than capped.
        $tracks->each(fn (Track $t) => Play::factory()->create(['track_id' => $t->id, 'user_id' => $user->id]));

        $this->actingAs($user)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->has('albums.latest', 4)->has('albums.random', 4)
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

        $entry = collect($this->actingAs(User::factory()->create())->get('/music')
            ->viewData('page')['props']['artists']['latest'])
            ->firstWhere('id', $artist->id);

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

        $entry = collect($this->actingAs(User::factory()->create())->get('/music')
            ->viewData('page')['props']['genres']['latest'])
            ->firstWhere('id', $genre->id);

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

        $entry = collect($this->actingAs(User::factory()->create())->get('/music')
            ->viewData('page')['props']['genres']['latest'])
            ->firstWhere('id', $genre->id);

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

        // Ranked by play count, and the SINGLE-play track is in it: the set was gated at >1
        // until 2026-08-08, which hid the answer on a library with three played songs. What
        // stays out is the song nobody has played — there is no ranking to put it in.
        $this->actingAs($user)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->has('songs.popular', 3)
                ->where('songs.popular.0.name', 'Hot Track')
                ->where('songs.popular.1.name', 'Warm Track')
                ->where('songs.popular.2.name', 'Cold Track')
            );
    }

    public function test_songs_popular_is_empty_only_when_nothing_has_been_played(): void
    {
        // The one case where there is genuinely nothing to rank, and the only one the
        // widget's "not enough data" note now covers.
        Track::factory()->count(3)->create();

        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page->has('songs.popular', 0));
    }

    public function test_artists_popular_falls_back_to_file_duration_when_nothing_is_played(): void
    {
        $long = Artist::factory()->create(['name' => 'Long Artist']);
        $short = Artist::factory()->create(['name' => 'Short Artist']);
        Track::factory()->create(['artist_id' => $long->id, 'duration' => 500]);
        Track::factory()->create(['artist_id' => $short->id, 'duration' => 100]);

        // The second sort key, doing all the work: with every play count COALESCEd to 0 the
        // order is the "most audio" one this widget had before plays existed. That fallback is
        // what keeps the card's default view populated on a library nobody has listened to.
        $this->actingAs(User::factory()->create())
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page->where('artists.popular.0.name', 'Long Artist'));
    }

    public function test_a_played_artist_outranks_a_bigger_unplayed_one(): void
    {
        // The bug this order exists to fix, reported 2026-08-08: the widget showed unplayed
        // entries above played ones, which beside a visible play pip reads as a broken sort.
        $reader = User::factory()->create();
        $played = Artist::factory()->create(['name' => 'Played Artist']);
        $bigger = Artist::factory()->create(['name' => 'Bigger Artist']);

        $track = Track::factory()->create(['artist_id' => $played->id, 'duration' => 100]);
        Track::factory()->create(['artist_id' => $bigger->id, 'duration' => 5000]);
        Play::factory()->create(['track_id' => $track->id, 'user_id' => $reader->id]);

        $this->actingAs($reader)
            ->get('/music')
            ->assertInertia(fn (Assert $page) => $page
                ->where('artists.popular.0.name', 'Played Artist')
                ->where('artists.popular.1.name', 'Bigger Artist')
            );
    }

    public function test_a_played_genre_outranks_a_bigger_unplayed_one(): void
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
                ->where('genres.popular.0.name', 'Played Genre')
                ->where('genres.popular.1.name', 'Bigger Genre')
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
        // Same rule for the songs card, which ranked the household until 2026-08-08. A song
        // only the housemate has played is not in the reader's popular set at all.
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

    public function test_the_songs_pip_counts_the_reader_where_popular_ranks_the_household(): void
    {
        // Two counts in one query, and they legitimately disagree on screen: `popular` ranks
        // by everybody's listens — that is what makes it a shared "what gets played here"
        // set — while the pip is the reader's own, like every play figure the app shows a
        // viewer. So the song leading the popular set can carry a pip of 1.
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
