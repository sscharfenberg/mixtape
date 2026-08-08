<?php

namespace Tests\Feature;

use App\Enums\TrackType;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What the header is allowed to offer: the `library` shared prop, which says which media
 * kinds this instance holds anything of.
 *
 * The DECISION is the client's — useSiteAreas turns these booleans into links, and its own
 * spec covers that — but only the server can answer the question, and only a server test
 * can prove it answers correctly for a library that has one kind and not the other. An
 * empty area is a link to a page that says nothing.
 */
class NavigationAreasTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_library_offers_no_area_at_all(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('library.music', false)
                ->where('library.audiobook', false)
            );
    }

    public function test_it_reports_only_the_kinds_that_have_tracks(): void
    {
        Track::factory()->create(['type' => TrackType::Music]);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('library.music', true)
                ->where('library.audiobook', false)
            );
    }

    public function test_it_reports_audiobooks_on_their_own(): void
    {
        // A collection can legitimately be audiobooks only — the header must offer that
        // area and not the empty one beside it.
        Track::factory()->create([
            'type' => TrackType::Audiobook,
            'artist_id' => null,
            'genre_id' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('library.music', false)
                ->where('library.audiobook', true)
            );
    }

    public function test_a_guest_page_asks_the_database_nothing(): void
    {
        /*
         * The login page has to render with no database at all, and that is not a
         * hypothetical: the E2E harness waits for the server to answer BEFORE it migrates,
         * so a shared prop touching `tracks` deadlocks the whole suite behind a table that
         * does not exist yet (CI, 2026-08-08). SiteMenu renders nothing without a user
         * anyway, so there is no question to answer here.
         */
        DB::statement('DROP TABLE tracks');

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('library.music', false)
                ->where('library.audiobook', false)
            );
    }

    public function test_the_now_playing_page_renders_for_anyone_signed_in(): void
    {
        // Reachable regardless of what is queued: the queue is client state, so the server
        // cannot know whether this page has anything to show — and a URL that 404s
        // depending on a browser's localStorage would be a worse answer than a page saying
        // the queue is empty.
        $this->actingAs(User::factory()->create())
            ->get('/now-playing')
            ->assertInertia(fn (Assert $page) => $page->component('NowPlaying/NowPlayingPage'));
    }

    public function test_the_now_playing_page_is_behind_auth(): void
    {
        $this->get('/now-playing')->assertRedirect('/login');
    }

    public function test_podcasts_are_gone(): void
    {
        // Dropped whole on 2026-08-08 — a podcast is something you listen to on the
        // service that publishes it, not a folder of mp3s. The route is the half a reader
        // could still have bookmarked.
        $this->actingAs(User::factory()->create())
            ->get('/podcasts')
            ->assertNotFound();
    }
}
