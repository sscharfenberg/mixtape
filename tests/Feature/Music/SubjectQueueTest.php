<?php

namespace Tests\Feature\Music;

use App\Enums\TrackType;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The `queueTracks` prop behind the hero menu's Play / Enqueue, on all four detail pages.
 *
 * Two things are worth a server test here, and neither is visible from the browser. First,
 * that the prop is genuinely OPTIONAL: it must not be computed on an ordinary page load, or
 * every artist visit would pay for a payload only a menu press needs. Second, that a partial
 * reload asking for it returns the WHOLE subject rather than the page of rows on screen —
 * which is the entire reason it exists, since every one of these pages paginates its table.
 *
 * ASSERTED AS JSON, NOT WITH `assertInertia`, and that is not a style choice: a partial reload
 * answers with JSON, while `AssertableInertia::fromTestResponse` begins with
 * `assertViewHas('page')` — it can only read an HTML page response. Point it at a partial and it
 * fails with "Not a valid Inertia response", which reads exactly like the prop being absent. The
 * ordinary-load test below is the one that CAN use it, because that response really is a page.
 */
class SubjectQueueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ask for one prop the way Inertia's partial reload does.
     *
     * THE VERSION IS ASKED OF THE MIDDLEWARE, and getting that wrong costs an hour. Inertia
     * answers a version mismatch with a 409 telling the client to hard-reload, which arrives in a
     * test as "not a valid Inertia response" — indistinguishable from the prop being missing, so
     * it sends you looking in the wrong place entirely. And `Inertia::getVersion()` is NOT the
     * value to send: the middleware sets it per request, so read in a test before any request has
     * run it is an empty string, which then mismatches. The middleware's own `version()` gives the
     * manifest hash without needing a request first.
     */
    private function reloadOnly(string $url, string $component, string $prop): TestResponse
    {
        return $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(Request::create($url)),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $prop,
        ])->get($url);
    }

    public function test_an_ordinary_page_load_does_not_carry_the_queue_payload(): void
    {
        $artist = Artist::factory()->create();
        Track::factory()->count(3)->create(['artist_id' => $artist->id]);

        $this->actingAs(User::factory()->create())
            ->get("/music/artists/{$artist->id}")
            ->assertInertia(fn (Assert $page) => $page->missing('queueTracks'));
    }

    public function test_a_partial_reload_returns_every_track_of_an_artist(): void
    {
        // More than one table page's worth, which is the point: the menu queues the artist,
        // not the 25 rows the reader happens to be looking at.
        //
        // The genre and album are made ONCE and shared, rather than letting the track factory
        // create one of each per row: GenreFactory picks from a fixed pool of names through
        // Faker's unique() (as its own comment warns), so thirty rows exhaust it outright.
        $artist = Artist::factory()->create();
        Track::factory()->count(30)->create([
            'artist_id' => $artist->id,
            'genre_id' => Genre::factory()->create()->id,
            'collection_id' => Collection::factory()->create()->id,
        ]);

        $this->actingAs(User::factory()->create());

        $this->reloadOnly("/music/artists/{$artist->id}", 'Music/Artists/Artist/ArtistPage', 'queueTracks')
            ->assertOk()
            ->assertJsonCount(30, 'props.queueTracks');
    }

    public function test_the_payload_carries_what_the_player_needs_and_nothing_else(): void
    {
        $artist = Artist::factory()->create(['name' => 'The Storm']);
        $album = Collection::factory()->create(['name' => 'Thunder Road', 'year' => 1994]);
        $song = Track::factory()->create([
            'name' => 'Lightning Strikes',
            'duration' => 185.5,
            'artist_id' => $artist->id,
            'collection_id' => $album->id,
            'cover' => false,
        ]);

        $this->actingAs(User::factory()->create());

        $this->reloadOnly("/music/songs/{$song->id}", 'Music/Songs/Song/SongPage', 'queueTracks')
            ->assertOk()
            ->assertJsonCount(1, 'props.queueTracks')
            ->assertJsonPath('props.queueTracks.0.id', $song->id)
            ->assertJsonPath('props.queueTracks.0.name', 'Lightning Strikes')
            ->assertJsonPath('props.queueTracks.0.artist', 'The Storm')
            ->assertJsonPath('props.queueTracks.0.album', 'Thunder Road')
                // Raw seconds, formatted client-side — the project's server-sends-raw rule.
            ->assertJsonPath('props.queueTracks.0.duration', 185.5)
                // Null rather than a URL: the scan found no picture in the file, and the panel
                // draws its placeholder instead of pointing an <img> at a 404.
            ->assertJsonPath('props.queueTracks.0.coverUrl', null)
            ->assertJsonPath('props.queueTracks.0.href', "/music/songs/{$song->id}")
            ->assertJsonPath('props.queueTracks.0.streamUrl', "/music/songs/{$song->id}/stream");
    }

    public function test_a_cover_the_scan_found_becomes_a_url(): void
    {
        $song = Track::factory()->create(['cover' => true]);

        $this->actingAs(User::factory()->create());

        $this->reloadOnly("/music/songs/{$song->id}", 'Music/Songs/Song/SongPage', 'queueTracks')
            ->assertJsonPath('props.queueTracks.0.coverUrl', "/music/songs/{$song->id}/cover");
    }

    public function test_an_album_is_queued_in_disc_and_track_order(): void
    {
        // Created out of order on purpose: playing an album means playing it in ITS order, not
        // in the order rows happened to be written.
        $album = Collection::factory()->create();
        Track::factory()->create(['collection_id' => $album->id, 'name' => 'Third', 'disc' => 1, 'track' => 3]);
        Track::factory()->create(['collection_id' => $album->id, 'name' => 'First', 'disc' => 1, 'track' => 1]);
        Track::factory()->create(['collection_id' => $album->id, 'name' => 'Fourth', 'disc' => 2, 'track' => 1]);
        Track::factory()->create(['collection_id' => $album->id, 'name' => 'Second', 'disc' => 1, 'track' => 2]);

        $this->actingAs(User::factory()->create());

        $this->reloadOnly("/music/albums/{$album->id}", 'Music/Albums/Album/AlbumPage', 'queueTracks')
            ->assertJsonPath('props.queueTracks.0.name', 'First')
            ->assertJsonPath('props.queueTracks.1.name', 'Second')
            ->assertJsonPath('props.queueTracks.2.name', 'Third')
            ->assertJsonPath('props.queueTracks.3.name', 'Fourth');
    }

    public function test_a_genre_is_queued_newest_record_first(): void
    {
        // The order the artist page's own songs table defaults to, and for the same reason:
        // records arrive as records, newest first, with undated material last rather than
        // first (which is where a plain descending sort on a nullable year would put it).
        $genre = Genre::factory()->create();
        $old = Collection::factory()->create(['name' => 'Early', 'year' => 1990]);
        $new = Collection::factory()->create(['name' => 'Late', 'year' => 2020]);
        $undated = Collection::factory()->create(['name' => 'Undated', 'year' => null]);

        Track::factory()->create(['genre_id' => $genre->id, 'collection_id' => $undated->id, 'name' => 'No year']);
        Track::factory()->create(['genre_id' => $genre->id, 'collection_id' => $old->id, 'name' => 'From 1990']);
        Track::factory()->create(['genre_id' => $genre->id, 'collection_id' => $new->id, 'name' => 'From 2020']);

        $this->actingAs(User::factory()->create());

        $this->reloadOnly("/music/genres/{$genre->id}", 'Music/Genres/Genre/GenrePage', 'queueTracks')
            ->assertJsonPath('props.queueTracks.0.name', 'From 2020')
            ->assertJsonPath('props.queueTracks.1.name', 'From 1990')
            ->assertJsonPath('props.queueTracks.2.name', 'No year');
    }

    public function test_audiobook_chapters_are_never_queued(): void
    {
        // The queue is the music player's. An audiobook collection is read elsewhere, and a
        // chapter arriving in a shuffle would be the bug this guards.
        $album = Collection::factory()->create();
        Track::factory()->create(['collection_id' => $album->id, 'type' => TrackType::Music]);
        Track::factory()->create(['collection_id' => $album->id, 'type' => TrackType::Audiobook]);

        $this->actingAs(User::factory()->create());

        $this->reloadOnly("/music/albums/{$album->id}", 'Music/Albums/Album/AlbumPage', 'queueTracks')
            ->assertOk()
            ->assertJsonCount(1, 'props.queueTracks');
    }
}
