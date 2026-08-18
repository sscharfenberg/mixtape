<?php

namespace Tests\Feature\Music;

use App\Enums\ArtistFilter;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Play;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Artists listing's stats strip and the `?filter=` it links to (`/music/artists`, behind auth).
 *
 * Its own file for the reason SongsStatsTest is: what is under test is the PAIR — a tile's count and
 * the table its link opens come from one predicate (ArtistFilter::apply), and asserting either alone
 * would let them drift while both files stayed green.
 *
 * Every fixture pins `modified_at`, because the factory's default lands anywhere this decade and one
 * of the four tiles counts by it — an unpinned fixture makes that tile a coin toss per run.
 */
class ArtistsStatsTest extends TestCase
{
    use RefreshDatabase;

    /** The one album every fixture track is filed under, so no test spawns artists it did not name. */
    private ?Collection $sampler = null;

    /**
     * An artist with `$songs` music tracks, old files by default so the date tile stays quiet.
     *
     * EVERY TRACK IS FILED UNDER ONE SHARED ALBUM, and that is not tidiness: `Track::factory()`
     * creates its own collection, and a collection creates its own album-artist, so a naive fixture
     * of three artists quietly becomes eight rows and every count in this file is wrong by a
     * different amount. The sampler's owner is named here for the same reason — a faker name is one
     * comma away from matching the lookalike pattern.
     */
    private function artist(string $name, int $songs = 2, array $trackAttributes = []): Artist
    {
        $this->sampler ??= Collection::factory()->create([
            'name' => 'Sampler',
            'album_artist_id' => Artist::factory()->create(['name' => 'Various'])->id,
        ]);

        $artist = Artist::factory()->create(['name' => $name]);

        for ($i = 0; $i < $songs; $i++) {
            Track::factory()->create([
                'artist_id' => $artist->id,
                'collection_id' => $this->sampler->id,
                'modified_at' => now()->subYear(),
                ...$trackAttributes,
            ]);
        }

        return $artist;
    }

    /** Give an artist an album of their own, which is what `compilations-only` looks for. */
    private function withOwnAlbum(Artist $artist): void
    {
        Collection::factory()->create(['album_artist_id' => $artist->id]);
    }

    public function test_the_strip_carries_a_total_and_one_tile_per_filter(): void
    {
        $this->artist('One');
        $this->artist('Two');

        $this->actingAs(User::factory()->create())
            ->get('/music/artists')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Every artist the LISTING can show, which is not the same as every performing
                // artist: that table lists a credited-only artist too (ArtistsController says why),
                // so a strip counting only performers would disagree with the table under it.
                ->where('stats.total', Artist::query()->count())
                ->has('stats.filters', count(ArtistFilter::cases()))
                ->where('stats.filters.0.key', ArtistFilter::NeverPlayed->value)
                ->where('stats.filters.1.key', ArtistFilter::CompilationsOnly->value)
                ->where('stats.filters.2.key', ArtistFilter::AddedThisMonth->value)
                ->where('stats.filters.3.key', ArtistFilter::LookalikeName->value)
                ->has('stats.filters.0', fn (Assert $tile) => $tile->hasAll(['key', 'count', 'href', 'active']))
            );
    }

    public function test_never_played_counts_the_reader_and_not_the_household(): void
    {
        $reader = User::factory()->create();
        $housemate = User::factory()->create();

        $mine = $this->artist('Mine');
        $theirs = $this->artist('Theirs');
        $this->artist('Nobody');

        Play::factory()->create([
            'track_id' => Track::query()->where('artist_id', $mine->id)->value('id'),
            'user_id' => $reader->id,
        ]);
        Play::factory()->count(9)->create([
            'track_id' => Track::query()->where('artist_id', $theirs->id)->value('id'),
            'user_id' => $housemate->id,
        ]);

        $this->actingAs($reader)
            ->get('/music/artists')
            ->assertInertia(fn (Assert $page) => $page->where('stats.filters.0.count', 2));
    }

    public function test_compilations_only_reads_the_album_artist_relation_and_not_the_performer_one(): void
    {
        // THE TRAP THIS FILTER LIVES NEXT TO: `tracks.artist_id` is the performer,
        // `collections.album_artist_id` is whose album it is, and they are different columns
        // (sharing.md records the same trap). A guest performer on somebody else's album has songs
        // and no album; the album's owner has an album whether or not they perform on it.
        $guest = $this->artist('Guest');
        $owner = $this->artist('Owner');
        $this->withOwnAlbum($owner);

        // …and an artist credited on a sleeve with nothing of their own on it is NOT "compilations
        // only" either — they are the opposite oddity, and the `tracks` half keeps them out.
        $sleeveOnly = Artist::factory()->create(['name' => 'Sleeve Only']);
        $this->withOwnAlbum($sleeveOnly);

        $this->actingAs($reader = User::factory()->create())
            ->get('/music/artists?filter=compilations-only')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.filters.1.count', 1)
                ->has('table.rows', 1)
                ->where('table.rows.0.id', $guest->id)
            );

        $this->assertNotNull($owner->fresh());
        $this->assertNotNull($sleeveOnly->fresh());
    }

    public function test_added_this_month_is_a_thirty_day_window_over_the_files(): void
    {
        // A month rather than the songs strip's week, because artists arrive far less often than
        // files do — measured, 41 artists over seven days against 53 over thirty on the live
        // library. Read off the FILE's mtime, never a row timestamp (SongFilter carries why).
        $this->artist('Fresh', 1, ['modified_at' => now()->subDays(20)]);
        $this->artist('Stale', 1, ['modified_at' => now()->subDays(40)]);

        $this->actingAs(User::factory()->create())
            ->get('/music/artists')
            ->assertInertia(fn (Assert $page) => $page->where('stats.filters.2.count', 1));
    }

    public function test_lookalike_names_are_candidates_rather_than_faults(): void
    {
        // What the pattern finds, and what it deliberately also finds: two of these three are real
        // band names. The tile is a review queue — only the reader can say which is which — so the
        // test asserts the SET rather than pretending any of them is wrong.
        $this->artist('Massive Attack vs Mad Professor');
        $this->artist('Nick Cave & The Bad Seeds');
        $this->artist('Portishead');

        $this->actingAs(User::factory()->create())
            ->get('/music/artists?filter=lookalike-name')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.filters.3.count', 2)
                ->has('table.rows', 2)
            );
    }

    public function test_the_pattern_matches_on_the_folded_name(): void
    {
        // `name_fold` and not `name`, which is not a detail: the raw column carries a
        // nondeterministic ICU collation, and Postgres refuses both LIKE and regex against it —
        // measured, "nondeterministic collations are not supported". An accented, upper-case name
        // therefore has to match through its fold.
        $this->artist('SIGUR RÓS & FRIENDS');

        $this->actingAs(User::factory()->create())
            ->get('/music/artists')
            ->assertInertia(fn (Assert $page) => $page->where('stats.filters.3.count', 1));
    }

    public function test_a_count_of_zero_offers_no_link(): void
    {
        $artist = $this->artist('Played');
        $this->withOwnAlbum($artist);
        Play::factory()->create([
            'track_id' => Track::query()->where('artist_id', $artist->id)->value('id'),
            'user_id' => ($reader = User::factory()->create())->id,
        ]);

        $this->actingAs($reader)
            ->get('/music/artists')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.filters.0.count', 0)
                ->where('stats.filters.0.href', null)
                ->where('stats.filters.1.count', 0)
                ->where('stats.filters.1.href', null)
                ->where('stats.filters.2.count', 0)
                ->where('stats.filters.2.href', null)
                ->where('stats.filters.3.count', 0)
                ->where('stats.filters.3.href', null)
            );
    }

    public function test_the_active_filter_is_marked_and_its_tile_offers_the_way_out(): void
    {
        $this->artist('A & B');

        $this->actingAs(User::factory()->create())
            ->get('/music/artists?filter=lookalike-name')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.filters.3.active', true)
                ->where('stats.filters.3.href', '/music/artists')
                ->where('stats.filters.0.active', false)
                ->where('stats.filters.0.href', '/music/artists?filter=never-played')
                ->where('table.filters', ['filter' => 'lookalike-name'])
            );
    }

    public function test_an_unknown_or_array_filter_shows_the_whole_table_rather_than_refusing(): void
    {
        $this->artist('One');
        $this->artist('Two');

        foreach (['filter=bogus', 'filter[]=never-played'] as $query) {
            $this->actingAs(User::factory()->create())
                ->get("/music/artists?{$query}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('table.rows', Artist::query()->count())
                    ->where('table.filters', null)
                );
        }
    }
}
