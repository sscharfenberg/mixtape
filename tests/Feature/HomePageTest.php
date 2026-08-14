<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The public landing page (`GET /`, no auth) — a claim over the two collections' totals,
 * drawn by the same stats cards the Music and Audiobooks pages use (HomeController).
 *
 * THE POINT OF EVERY CASE BELOW is that this route is outside the auth group on an
 * internet-facing instance, so its props are world-readable. Two things therefore have to
 * hold, and both are asserted rather than assumed: a guest gets the numbers at all, and the
 * payload carries NOTHING BUT numbers — no title, artist, book or file name. `hasAll` fails
 * on extras, which is what makes the second half a real guard: a field cannot quietly appear
 * here without someone deciding a stranger may read it.
 */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    /** Every key the music card draws, and no other. */
    private const MUSIC_KEYS = [
        'songs', 'sizeBytes', 'playtimeSeconds', 'albums', 'artists', 'genres', 'firstYear', 'lastYear',
    ];

    /** Every key the audiobook card draws, and no other. */
    private const AUDIOBOOK_KEYS = [
        'books', 'chapters', 'sizeBytes', 'playtimeSeconds', 'authors', 'narrators', 'firstYear', 'lastYear',
    ];

    public function test_a_guest_sees_the_welcome_page_with_both_collections_totals(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Guest/WelcomePage')
                ->has('musicStats', fn (Assert $stats) => $stats->hasAll(self::MUSIC_KEYS))
                ->has('audiobookStats', fn (Assert $stats) => $stats->hasAll(self::AUDIOBOOK_KEYS))
            );
    }

    public function test_a_signed_in_reader_sees_the_same_page(): void
    {
        // Nothing here is per-viewer — no reader argument reaches LibraryStats and no `plays`
        // are counted — so the page must not start differing once there is a session.
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Guest/WelcomePage')
                ->has('musicStats')
                ->has('audiobookStats')
            );
    }

    public function test_the_totals_count_the_library(): void
    {
        // Track's factory pulls an album and its taxonomy in through the FKs, so three music
        // tracks are three songs across up to three albums — the counts below assert the ones
        // that are exact whatever the factory invents.
        Track::factory()->count(3)->create();

        $this->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('musicStats.songs', 3)
                // No audiobooks were created, so the other card must read zero rather than
                // count the music: both sets come from one service and the two are separated
                // only by their `type` filters.
                ->where('audiobookStats.books', 0)
                ->where('audiobookStats.chapters', 0)
                ->where('audiobookStats.sizeBytes', 0)
                // `0`, not `0.0`, though the service casts it to a float: the assertion reads
                // the JSON the page was rendered to, and a whole-numbered float comes back an
                // int. `where` compares strictly, so the expressive spelling fails here.
                ->where('audiobookStats.playtimeSeconds', 0)
            );
    }

    public function test_the_two_cards_report_their_own_years_and_not_each_other_s(): void
    {
        // The one page that shows both ranges at once, which makes it the place a missing
        // `type` filter would be visible: `collections` is one table, so an album and a book
        // a century apart must still produce two ranges rather than one shared span.
        Collection::factory()->create(['year' => 1970]);
        Collection::factory()->create(['year' => 1985]);
        Collection::factory()->audiobook()->create(['year' => 2019]);
        Collection::factory()->audiobook()->create(['year' => 2024]);

        $this->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('musicStats.firstYear', 1970)
                ->where('musicStats.lastYear', 1985)
                ->where('audiobookStats.firstYear', 2019)
                ->where('audiobookStats.lastYear', 2024)
            );
    }

    public function test_no_name_from_the_collection_reaches_an_anonymous_visitor(): void
    {
        // A library with distinctive names in it. If any query on this page ever grows a title
        // or a credit, this is the case that says so — the response body is searched whole,
        // so it catches a name arriving anywhere, not only where a key was expected.
        $album = Collection::factory()->create(['name' => 'Zzyzx Road Sessions']);
        Track::factory()->create(['name' => 'Unmistakable Song Title', 'collection_id' => $album->id]);

        $response = $this->get('/')->assertOk();

        $response->assertDontSee('Zzyzx Road Sessions', escape: false);
        $response->assertDontSee('Unmistakable Song Title', escape: false);
    }
}
