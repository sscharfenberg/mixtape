<?php

namespace Tests\Feature\Music;

use App\Enums\GenreFilter;
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
 * The Genres listing's stats strip and the `?filter=` it links to (`/music/genres`, behind auth).
 *
 * Its own file for the reason SongsStatsTest is: a tile's count and the table its link opens come
 * from one predicate (GenreFilter::apply), and asserting either alone would let them drift.
 *
 * The interesting one is `one-artist`, which is NOT the listing's `artists` column with a filter on
 * it — that column counts artists whose MAIN genre this is. The two are asserted against each other
 * below, because the day they are conflated the tile still looks plausible.
 */
class GenresStatsTest extends TestCase
{
    use RefreshDatabase;

    /** The shared album, so no fixture spawns rows it did not name (a collection mints an artist). */
    private ?Collection $sampler = null;

    /**
     * A genre with one music track per artist named, old files by default so the date tile is quiet.
     *
     * @param  list<string>  $performers  one track each — the distinct-artist count is what
     *                                    `one-artist` measures
     */
    private function genre(string $name, array $performers = ['Solo'], array $trackAttributes = []): Genre
    {
        $this->sampler ??= Collection::factory()->create([
            'name' => 'Sampler',
            'album_artist_id' => Artist::factory()->create(['name' => 'Various'])->id,
        ]);

        $genre = Genre::factory()->create(['name' => $name]);

        foreach ($performers as $performer) {
            Track::factory()->create([
                'genre_id' => $genre->id,
                // firstOrCreate, because an artist name is unique in the schema and a performer
                // named twice is ONE artist — which is also the whole point of the distinct count
                // `one-artist` measures.
                'artist_id' => Artist::query()->firstOrCreate(['name' => $performer])->id,
                'collection_id' => $this->sampler->id,
                'modified_at' => now()->subYear(),
                ...$trackAttributes,
            ]);
        }

        return $genre;
    }

    public function test_the_strip_carries_a_total_and_one_tile_per_filter(): void
    {
        $this->genre('Rock');
        $this->genre('Jazz');

        $this->actingAs(User::factory()->create())
            ->get('/music/genres')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Every genre the LISTING can show, orphans included — that table is not filtered to
                // genres with tracks (GenresController says why), so a strip counting only tagged
                // ones would disagree with the table under it.
                ->where('stats.total', Genre::query()->count())
                ->has('stats.filters', count(GenreFilter::cases()))
                ->where('stats.filters.0.key', GenreFilter::NeverPlayed->value)
                ->where('stats.filters.1.key', GenreFilter::OneArtist->value)
                ->where('stats.filters.2.key', GenreFilter::AddedThisWeek->value)
                ->where('stats.filters.3.key', GenreFilter::OneSong->value)
                ->has('stats.filters.0', fn (Assert $tile) => $tile->hasAll(['key', 'count', 'href', 'active']))
            );
    }

    public function test_never_played_counts_the_reader_and_needs_something_playable(): void
    {
        $reader = User::factory()->create();
        $housemate = User::factory()->create();

        $mine = $this->genre('Mine');
        $theirs = $this->genre('Theirs');
        $this->genre('Nobody');
        // An orphaned genre holds no music at all, so it is not a genre anybody has "never played" —
        // and its tile would otherwise link to a row a reader cannot act on.
        Genre::factory()->create(['name' => 'Orphan']);

        Play::factory()->create([
            'track_id' => Track::query()->where('genre_id', $mine->id)->value('id'),
            'user_id' => $reader->id,
        ]);
        Play::factory()->count(4)->create([
            'track_id' => Track::query()->where('genre_id', $theirs->id)->value('id'),
            'user_id' => $housemate->id,
        ]);

        $this->actingAs($reader)
            ->get('/music/genres')
            ->assertInertia(fn (Assert $page) => $page->where('stats.filters.0.count', 2));
    }

    public function test_one_artist_counts_performers_and_not_the_dominant_genre_column(): void
    {
        // THE DISTINCTION THE TILE EXISTS FOR. "Solo Genre" holds three songs by one band; the
        // listing's `artists` column counts whose MAIN genre it is, which is a different question
        // with a different answer — so this test asserts the tile against that column rather than
        // only against itself.
        $solo = $this->genre('Solo Genre', ['One Band', 'One Band', 'One Band']);
        $this->genre('Shared Genre', ['First', 'Second']);

        $this->actingAs(User::factory()->create())
            ->get('/music/genres?filter=one-artist')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.filters.1.count', 1)
                ->has('table.rows', 1)
                ->where('table.rows.0.id', $solo->id)
                // Same row, two numbers: three songs by one performer, and the column beside it
                // counting main-genre artists on its own rule.
                ->where('table.rows.0.songs', 3)
            );
    }

    public function test_a_genre_whose_songs_credit_nobody_is_not_one_artists(): void
    {
        // `count(distinct)` skips NULLs, so an uncredited genre counts zero performers rather than
        // one — and "one artist only" is a claim about who that artist is.
        $genre = Genre::factory()->create(['name' => 'Uncredited']);
        Track::factory()->count(2)->create([
            'genre_id' => $genre->id,
            'artist_id' => null,
            'modified_at' => now()->subYear(),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/music/genres')
            ->assertInertia(fn (Assert $page) => $page->where('stats.filters.1.count', 0));
    }

    public function test_added_this_week_is_a_rolling_window_over_the_files(): void
    {
        $this->genre('Fresh', ['Someone'], ['modified_at' => now()->subDays(2)]);
        $this->genre('Stale', ['Someone Else'], ['modified_at' => now()->subDays(9)]);

        $this->actingAs(User::factory()->create())
            ->get('/music/genres')
            ->assertInertia(fn (Assert $page) => $page->where('stats.filters.2.count', 1));
    }

    public function test_one_song_counts_music_alone(): void
    {
        // A chapter may legally carry a genre — only audiobooks are barred by the tracks CHECK — so
        // a genre with one song and one chapter is still a one-song genre.
        $genre = $this->genre('Single', ['Someone']);
        Track::factory()->audiobook()->create(['genre_id' => $genre->id, 'modified_at' => now()->subYear()]);
        $this->genre('Double', ['A', 'B']);

        $this->actingAs(User::factory()->create())
            ->get('/music/genres?filter=one-song')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.filters.3.count', 1)
                ->has('table.rows', 1)
                ->where('table.rows.0.id', $genre->id)
            );
    }

    public function test_the_active_filter_is_marked_and_its_tile_offers_the_way_out(): void
    {
        $this->genre('Solo');

        $this->actingAs(User::factory()->create())
            ->get('/music/genres?filter=one-artist')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.filters.1.active', true)
                ->where('stats.filters.1.href', '/music/genres')
                ->where('stats.filters.0.active', false)
                ->where('stats.filters.0.href', '/music/genres?filter=never-played')
                ->where('table.filters', ['filter' => 'one-artist'])
            );
    }

    public function test_an_unknown_or_array_filter_shows_the_whole_table_rather_than_refusing(): void
    {
        $this->genre('Rock');
        $this->genre('Jazz');

        foreach (['filter=bogus', 'filter[]=one-song'] as $query) {
            $this->actingAs(User::factory()->create())
                ->get("/music/genres?{$query}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('table.rows', Genre::query()->count())
                    ->where('table.filters', null)
                );
        }
    }
}
