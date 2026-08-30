<?php

namespace Tests\Feature\Music;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Play;
use App\Models\Playlist;
use App\Models\Share;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * WHAT "THIS ARTIST'S SONGS" MEANS, asked of every reader of the question at once.
 *
 * ITS OWN FILE BECAUSE THE INVARIANT IS THE AGREEMENT, not any one of the answers. Eight
 * features narrow to an artist — the detail page's hero and its songs tab, the hero's Play,
 * the artists listing, the Music page's widget, "add this artist to a playlist", a listing's
 * ticked rows, a share link, and the play counts printed beside all of them — and each one
 * has its own feature test that would stay green while the definition drifted underneath it.
 * The way that shows up to a reader is a number contradicting the table beneath it, or a share
 * link whose player stops silently on one song out of ninety.
 *
 * THE DEFINITION (App\Services\Music\ArtistCredits) is the union of two credits: what the
 * artist PERFORMS (`tracks.artist_id`) and what sits on a record CREDITED to them
 * (`collections.album_artist_id`). Neither contains the other, which is why it has to be
 * both — a compilation holds their track without being their album, and their own album holds
 * a "feat." credit that tags as a separate artist. On the live library that second arm is 570
 * tracks across 29 artists, and for some of them it is their entire catalogue: "Motorhead"
 * owns a record whose files all tag "Motörhead", and read as one album and no songs.
 *
 * ONE FIXTURE, FOUR TRACKS, and every one of them is a case:
 *
 *   - `own` — theirs both ways, and the only one the narrow rule found. It is also what makes
 *     the union's DISTINCT load-bearing: both arms reach it, and counted twice every ordinary
 *     artist's totals would double.
 *   - `feature` — on their record, credited to "… feat. …". The fault this rule exists for.
 *   - `guest` — their performance on somebody else's compilation.
 *   - `strangers` — neither, and what stops "the union" from quietly becoming "everything".
 */
class ArtistCreditsTest extends TestCase
{
    use RefreshDatabase;

    private Artist $artist;

    private Track $own;

    private Track $feature;

    private Track $guest;

    private Track $strangers;

    /**
     * The four-track fixture described in the class docblock.
     *
     * Durations are distinct and fractional so a total names which tracks it summed rather
     * than only how many — a whole-number float also crosses the wire as an int, which hides
     * whether the sum went over unrounded.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->artist = Artist::factory()->create(['name' => 'Bring Me The Horizon']);
        $featured = Artist::factory()->create(['name' => 'Bring Me The Horizon feat. BABYMETAL']);
        $stranger = Artist::factory()->create(['name' => 'Somebody Else']);

        $ownAlbum = Collection::factory()->create(['album_artist_id' => $this->artist->id, 'name' => 'Post Human']);
        $compilation = Collection::factory()->create(['album_artist_id' => null, 'name' => 'A Compilation']);
        $strangersAlbum = Collection::factory()->create(['album_artist_id' => $stranger->id, 'name' => 'Not Theirs']);

        $this->own = Track::factory()->create([
            'artist_id' => $this->artist->id, 'collection_id' => $ownAlbum->id,
            'name' => 'DArkSide', 'duration' => 100.5, 'size' => 1_000_000,
        ]);
        $this->feature = Track::factory()->create([
            'artist_id' => $featured->id, 'collection_id' => $ownAlbum->id,
            'name' => 'Kingslayer', 'duration' => 200.25, 'size' => 2_000_000,
        ]);
        $this->guest = Track::factory()->create([
            'artist_id' => $this->artist->id, 'collection_id' => $compilation->id,
            'name' => 'Drown', 'duration' => 400.125, 'size' => 4_000_000,
        ]);
        $this->strangers = Track::factory()->create([
            'artist_id' => $stranger->id, 'collection_id' => $strangersAlbum->id,
            'name' => 'Nothing To Do With Them', 'duration' => 800.0, 'size' => 8_000_000,
        ]);
    }

    /** The three tracks the artist is credited with, as ids, for comparing a set against. */
    private function credited(): array
    {
        return collect([$this->own->id, $this->feature->id, $this->guest->id])->sort()->values()->all();
    }

    /** Ids out of a list of things that carry one, sorted so only the SET is compared. */
    private function ids(array $rows): array
    {
        return collect($rows)->pluck('id')->sort()->values()->all();
    }

    /** Ask for one optional Inertia prop the way the frontend's partial reload does. */
    private function reloadOnly(string $url, string $component, string $prop): TestResponse
    {
        return $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(Request::create($url)),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $prop,
        ])->get($url);
    }

    public function test_the_artist_pages_hero_and_songs_tab_agree_on_the_same_three_tracks(): void
    {
        // The pair that has to hold on ONE SCREEN: a hero reading "1 Song" over a table paging
        // three of them is a wrong number rather than a different question.
        $response = $this->actingAs(User::factory()->create())->get("/music/artists/{$this->artist->id}");

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('artist.songs', 3)
            ->where('artist.duration', 100.5 + 200.25 + 400.125)
            ->where('artist.size', 7_000_000)
            ->has('table.rows', 3)
        );

        $this->assertSame($this->credited(), $this->ids($this->inertiaProp($response, 'table.rows')));
    }

    public function test_the_hero_play_button_queues_exactly_what_the_songs_tab_lists(): void
    {
        // Play is what a reader presses AFTER reading the table, so a queue that is a different
        // set is the same contradiction one interaction later.
        $response = $this->actingAs(User::factory()->create())->reloadOnly(
            "/music/artists/{$this->artist->id}", 'Music/Artists/Artist/ArtistPage', 'queueTracks'
        );

        $response->assertOk();
        $this->assertSame($this->credited(), $this->ids($response->json('props.queueTracks')));
    }

    public function test_the_artists_listing_counts_what_the_artists_own_page_prints(): void
    {
        // The row a reader clicks THROUGH, so the two are read seconds apart. Found by id
        // rather than asserted at a position: the fixture's "… feat. …" artist is a row of this
        // listing too, and a search for the band's name legitimately matches both.
        $row = $this->listingRow($this->actingAs(User::factory()->create())->get('/music/artists'));

        $this->assertSame(3, $row['songs']);
        $this->assertSame(100.5 + 200.25 + 400.125, $row['duration']);
        $this->assertSame(7_000_000, $row['size']);
    }

    /** This fixture's artist as the artists listing renders them. */
    private function listingRow(TestResponse $response): array
    {
        $response->assertOk();
        $row = collect($this->inertiaProp($response, 'table.rows'))->firstWhere('id', $this->artist->id);

        $this->assertNotNull($row, 'the artist is missing from the listing');

        return $row;
    }

    public function test_the_music_pages_artist_widget_counts_the_same_three(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/music');
        $entry = collect($this->inertiaProp($response, 'artists.latest'))->firstWhere('id', $this->artist->id);

        $this->assertNotNull($entry);
        $this->assertSame(3, $entry['songs']);
        $this->assertSame(100.5 + 200.25 + 400.125, $entry['duration']);
    }

    public function test_adding_the_artist_to_a_playlist_adds_all_three(): void
    {
        // The browser sends "artist X" and never a list of tracks (the page paginates), so what
        // lands is decided entirely by this definition.
        $reader = User::factory()->create();
        $playlist = Playlist::factory()->for($reader)->create();

        $this->actingAs($reader)
            ->post("/playlists/{$playlist->id}/tracks", ['subject' => 'artist', 'ids' => [$this->artist->id]])
            ->assertRedirect();

        $this->assertSame(
            $this->credited(),
            $playlist->tracks()->pluck('tracks.id')->sort()->values()->all()
        );
    }

    public function test_a_ticked_artist_row_queues_all_three(): void
    {
        // The other way a subject reaches the player — a listing's checkboxes rather than a
        // detail page's hero. Same definition, different entry point.
        $response = $this->actingAs(User::factory()->create())
            ->postJson('/queue/tracks', ['subject' => 'artist', 'ids' => [$this->artist->id]]);

        $response->assertOk();
        $this->assertSame($this->credited(), $this->ids($response->json()));
    }

    public function test_a_share_link_grants_all_three_and_nothing_else(): void
    {
        // The one reader whose disagreement reaches somebody with no account: a page listing a
        // track the stream then refuses looks like a player stopping for no reason.
        $share = Share::factory()->ofArtist($this->artist)->create();
        $response = $this->get("/s/{$share->id}");

        $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('subject.songs', 3));
        $this->assertSame($this->credited(), $this->ids($this->inertiaProp($response, 'tracks')));
    }

    public function test_the_play_counts_beside_those_numbers_count_the_same_tracks(): void
    {
        // A tile counting listens the "songs" tile beside it does not count is arithmetic a
        // reader cannot reproduce (PlayCounts states the rule).
        $reader = User::factory()->create();
        $housemate = User::factory()->create();

        Play::factory()->count(2)->create(['track_id' => $this->feature->id, 'user_id' => $reader->id]);
        Play::factory()->create(['track_id' => $this->guest->id, 'user_id' => $housemate->id]);
        // On nobody's record of theirs, so it must not reach any of the three numbers below.
        Play::factory()->count(9)->create(['track_id' => $this->strangers->id, 'user_id' => $reader->id]);

        $this->actingAs($reader)
            ->get("/music/artists/{$this->artist->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('plays.own', 2)
                ->where('plays.others', 1)
            );

        // The listing's `plays` column is the reader's OWN listens, and it is a grouped join
        // where the detail page's is a pair of counts — two shapes that must agree.
        $this->assertSame(2, $this->listingRow($this->actingAs($reader)->get('/music/artists'))['plays']);

        // …and the widget's pip, which is the third shape (correlated, for four rows).
        $response = $this->actingAs($reader)->get('/music');
        $entry = collect($this->inertiaProp($response, 'artists.latest'))->firstWhere('id', $this->artist->id);

        $this->assertSame(2, $entry['plays']);
    }

    public function test_a_track_credited_both_ways_is_never_counted_twice(): void
    {
        // The union is DISTINCT. An artist who performs their whole own record is the normal
        // case — both arms reach every track — and double counting there would be wrong for
        // almost every artist rather than only for the collaborations.
        $solo = Artist::factory()->create(['name' => 'Entirely Their Own']);
        $album = Collection::factory()->create(['album_artist_id' => $solo->id]);

        Track::factory()->count(3)->create([
            'artist_id' => $solo->id, 'collection_id' => $album->id, 'duration' => 60.5,
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$solo->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('artist.songs', 3)
                ->where('artist.duration', 181.5)
                ->has('table.rows', 3)
                // Nothing on their record is by anybody else, so the songs tab draws no artist
                // column — the branch that is right for most of the library.
                ->where('artist.hasGuestCredits', false)
            );
    }
}
