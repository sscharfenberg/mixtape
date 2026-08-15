<?php

namespace Tests\Feature\Player;

use App\Models\Genre;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Now Playing page (`GET /now-playing`) — which is almost entirely a client-side page, so
 * there is exactly one server contract to pin and it is `facts`.
 *
 * WHAT IS WORTH TESTING HERE, and why each is a way the page could quietly go wrong:
 *
 *   - AN ORDINARY VISIT NAMES NOTHING. The page cannot know which tracks it is showing until it
 *     has read the queue out of the browser, so a visit with no ids answers with an empty map and
 *     runs no query — rather than with every genre in the library.
 *   - THE IDS ARE VALIDATED. They go into a `whereIn` against a uuid column, and Postgres answers
 *     a malformed one with `invalid input syntax for type uuid` — a 500 from a query string.
 *     THIS SUITE CANNOT SEE THAT, because sqlite compares uuids as strings and finds nothing;
 *     what it can see is that the rule rejects the request before the query is ever built, which
 *     is the thing that has to hold on the real server.
 *   - A MISSING GENRE IS AN ANSWER. Plenty of rips carry no genre frame, and the card drops the
 *     line rather than showing an empty one.
 */
class NowPlayingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/now-playing')->assertRedirect('/login');
    }

    public function test_an_ordinary_visit_answers_with_an_empty_map(): void
    {
        Track::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get('/now-playing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('NowPlaying/NowPlayingPage')
                ->where('facts', [])
                ->etc());
    }

    public function test_it_answers_with_the_facts_of_the_tracks_it_is_asked_about(): void
    {
        $rock = Genre::factory()->create(['name' => 'Alternative Rock']);
        $jazz = Genre::factory()->create(['name' => 'Jazz']);

        $one = Track::factory()->create(['genre_id' => $rock->id]);
        $two = Track::factory()->create(['genre_id' => $jazz->id]);
        $unasked = Track::factory()->create(['genre_id' => $rock->id]);

        $this->actingAs(User::factory()->create())
            ->get('/now-playing?tracks[]='.$one->id.'&tracks[]='.$two->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('facts.'.$one->id.'.genre', 'Alternative Rock')
                ->where('facts.'.$two->id.'.genre', 'Jazz')
                // THE THIRD TRACK IS ABSENT, which is the point of asking for exactly what is
                // drawn: the endpoint answers about the ids it was given, never about the library.
                ->missing('facts.'.$unasked->id)
                ->etc());
    }

    public function test_a_track_with_no_genre_comes_back_as_null_rather_than_missing(): void
    {
        $untagged = Track::factory()->create(['genre_id' => null, 'collection_id' => null]);

        $this->actingAs(User::factory()->create())
            ->get('/now-playing?tracks[]='.$untagged->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('facts.'.$untagged->id.'.genre', null)->etc());
    }

    public function test_it_carries_the_links_that_the_queue_cannot(): void
    {
        // The queue holds artist and album as plain STRINGS; which pages exist is the server's to
        // know. Without these the hero's tiles would be text where they should lead somewhere.
        $track = Track::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get('/now-playing?tracks[]='.$track->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('facts.'.$track->id.'.artistUrl', route('music.artists.show', $track->artist_id, absolute: false))
                ->where('facts.'.$track->id.'.albumUrl', route('music.albums.show', $track->collection_id, absolute: false))
                ->where('facts.'.$track->id.'.genreUrl', route('music.genres.show', $track->genre_id, absolute: false))
                ->etc());
    }

    public function test_a_track_with_nothing_to_link_to_gets_no_dead_links(): void
    {
        $loose = Track::factory()->create(['artist_id' => null, 'collection_id' => null, 'genre_id' => null]);

        $this->actingAs(User::factory()->create())
            ->get('/now-playing?tracks[]='.$loose->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('facts.'.$loose->id.'.artistUrl', null)
                ->where('facts.'.$loose->id.'.albumUrl', null)
                ->where('facts.'.$loose->id.'.genreUrl', null)
                // And no year: a year is a property of the RELEASE, so a file filed under no
                // album has none to give.
                ->where('facts.'.$loose->id.'.year', null)
                ->etc());
    }

    public function test_it_carries_the_play_counts_the_hero_shows(): void
    {
        // Not a property of the track at all but of the listening, which is why it cannot live on
        // a queue entry however much the rest of the row does.
        $track = Track::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get('/now-playing?tracks[]='.$track->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('facts.'.$track->id.'.plays.own', 0)
                ->where('facts.'.$track->id.'.plays.others', 0)
                ->etc());
    }

    public function test_an_id_the_library_no_longer_holds_is_simply_absent(): void
    {
        // A queue restored from localStorage can name a file the scanner has since removed. The
        // card reads an absent id the same way it reads a null: no genre line.
        $this->actingAs(User::factory()->create())
            ->get('/now-playing?tracks[]=3f0f1a1e-0000-4000-8000-000000000000')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('facts', [])->etc());
    }

    public function test_it_refuses_an_id_that_is_not_a_uuid(): void
    {
        // The rule is what stands between a query param and a Postgres error. See the class note.
        $this->actingAs(User::factory()->create())
            ->get('/now-playing?tracks[]=not-a-uuid')
            ->assertSessionHasErrors('tracks.0');
    }

    public function test_it_refuses_more_ids_than_the_page_draws(): void
    {
        $ids = Track::factory()->count(4)->create()->pluck('id')
            ->map(fn (string $id): string => 'tracks[]='.$id)
            ->implode('&');

        $this->actingAs(User::factory()->create())
            ->get('/now-playing?'.$ids)
            ->assertSessionHasErrors('tracks');
    }
}
