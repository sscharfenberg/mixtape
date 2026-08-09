<?php

namespace Tests\Feature\Dev;

use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The dev-only Web Audio probe (`/dev/audio-probe`).
 *
 * The page itself cannot be tested from here at all — what it measures is whether a browser
 * keeps an AudioContext running while the screen is off, which needs a real engine and a real
 * phone. What IS testable is the two things the server decides, and both are ways the probe
 * could quietly measure nothing:
 *
 *   - IT PICKS THE LONGEST TRACK. A track that ends while the screen is off stops legitimately,
 *     and the page would report that as a stall — a false negative on the one question being
 *     asked. Picking the longest is what removes that whole class of wrong answer.
 *   - IT IS BEHIND AUTH, unlike the icon gallery beside it. The stream route is authenticated,
 *     so a page rendered for a guest would offer a player that could only redirect to the login
 *     form — a probe that fails for a reason having nothing to do with Web Audio.
 */
class AudioProbePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_login_rather_than_shown_a_player_they_cannot_use(): void
    {
        $this->get('/dev/audio-probe')->assertRedirect('/login');
    }

    public function test_it_offers_the_longest_music_track(): void
    {
        $short = Track::factory()->create(['duration' => 90.0]);
        $longest = Track::factory()->create(['duration' => 4_800.0]);

        $this->actingAs(User::factory()->create())
            ->get('/dev/audio-probe')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dev/AudioProbePage')
                ->where('track.name', $longest->name)
                ->where('track.streamUrl', route('music.songs.stream', $longest, absolute: false))
                ->etc());

        $this->assertNotSame($short->name, $longest->name);
    }

    public function test_it_will_not_offer_an_audiobook_chapter(): void
    {
        // A chapter is routinely the longest thing in a library — an 83-minute file is normal —
        // so without the type filter the probe would almost always pick one, and the stream it
        // tested would not be the kind of file the player actually queues.
        Track::factory()->audiobook()->create(['duration' => 9_000.0]);
        $song = Track::factory()->create(['duration' => 200.0]);

        $this->actingAs(User::factory()->create())
            ->get('/dev/audio-probe')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('track.name', $song->name)->etc());
    }

    public function test_it_renders_with_nothing_to_play_on_an_empty_library(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dev/audio-probe')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('track', null)->etc());
    }
}
