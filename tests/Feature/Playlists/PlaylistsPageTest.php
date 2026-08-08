<?php

namespace Tests\Feature\Playlists;

use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Playlists listing (`/playlists`, behind auth) — the props PlaylistsController
 * hands the page.
 *
 * The test that matters most here is the OWNERSHIP one. Playlists are private per
 * account on a box that is deliberately reachable from the internet and shared with
 * family and friends, so a listing that leaked another user's is not a cosmetic bug.
 * The ordering test is the second: `position` defaults to 0, so a brand-new account's
 * playlists all share it and only the `name` tiebreak stops the order changing between
 * two loads of the same page.
 */
class PlaylistsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/playlists')->assertRedirect('/login');
    }

    public function test_a_fresh_account_gets_an_empty_list(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/playlists')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Playlists/PlaylistsPage')
                ->has('playlists', 0)
            );
    }

    public function test_it_lists_only_the_readers_own_playlists(): void
    {
        $reader = User::factory()->create();
        $stranger = User::factory()->create();

        Playlist::factory()->create(['user_id' => $reader->id, 'name' => 'Mine']);
        Playlist::factory()->create(['user_id' => $stranger->id, 'name' => 'Theirs']);

        $this->actingAs($reader)
            ->get('/playlists')
            ->assertInertia(fn (Assert $page) => $page
                ->has('playlists', 1)
                ->where('playlists.0.name', 'Mine')
            );
    }

    public function test_a_row_carries_its_description_track_count_and_creation_date(): void
    {
        $reader = User::factory()->create();
        $playlist = Playlist::factory()->create([
            'user_id' => $reader->id,
            'name' => 'Sunday morning',
            'description' => 'Quiet things.',
        ]);

        // Through the pivot MODEL rather than `attach()`: `playlist_tracks` has a uuid
        // primary key of its own, which a plain attach() would never fill in.
        //
        // A fractional duration, so the sum is deliberately NOT a whole number — the only
        // way to see raw seconds went over rather than something already rounded.
        PlaylistTrack::factory()->count(3)->create([
            'playlist_id' => $playlist->id,
            'track_id' => fn (): string => Track::factory()->create(['duration' => 100.25])->id,
        ]);

        $this->actingAs($reader)
            ->get('/playlists')
            ->assertInertia(fn (Assert $page) => $page
                ->where('playlists.0.name', 'Sunday morning')
                ->where('playlists.0.description', 'Quiet things.')
                ->where('playlists.0.tracks', 3)
                ->where('playlists.0.duration', 300.75)
                // Raw ISO-8601: the page formats it against the viewer's locale and
                // timezone, neither of which the server knows.
                ->where('playlists.0.createdAt', $playlist->created_at->toIso8601String())
            );
    }

    public function test_an_empty_playlist_reports_no_duration_rather_than_zero(): void
    {
        // SUM over no rows is NULL, and the page needs that distinction: it prints no
        // playtime tile at all rather than "0 seconds" beside a track count of 0.
        Playlist::factory()->create(['user_id' => ($reader = User::factory()->create())->id]);

        $this->actingAs($reader)
            ->get('/playlists')
            ->assertInertia(fn (Assert $page) => $page
                ->where('playlists.0.tracks', 0)
                ->where('playlists.0.duration', null)
            );
    }

    public function test_a_playlist_nothing_has_happened_to_reports_no_update(): void
    {
        /*
         * `created_at` and `updated_at` are written from ONE instant on insert, so they are
         * exactly equal until something moves one of them — and the page hangs its "changed"
         * tile on this being null. Were the controller to ship `updated_at` unconditionally,
         * every brand-new playlist would claim it had been changed the moment it was made.
         */
        Playlist::factory()->create(['user_id' => ($reader = User::factory()->create())->id]);

        $this->actingAs($reader)
            ->get('/playlists')
            ->assertInertia(fn (Assert $page) => $page->where('playlists.0.updatedAt', null));
    }

    public function test_editing_a_playlist_reports_an_update(): void
    {
        $playlist = Playlist::factory()->create(['user_id' => ($reader = User::factory()->create())->id]);

        $this->travel(1)->minute();
        $playlist->update(['description' => 'Second thoughts.']);

        $this->actingAs($reader)
            ->get('/playlists')
            ->assertInertia(fn (Assert $page) => $page
                ->where('playlists.0.updatedAt', $playlist->fresh()->updated_at->toIso8601String())
            );
    }

    public function test_adding_a_track_counts_as_changing_the_playlist(): void
    {
        /*
         * The whole point of PlaylistTrack::$touches. Without it the "changed" fact would
         * only ever move on a rename — saying nothing about the thing a listener actually
         * changes, which is what is IN the playlist.
         */
        $playlist = Playlist::factory()->create(['user_id' => ($reader = User::factory()->create())->id]);

        $this->travel(1)->minute();
        PlaylistTrack::factory()->create(['playlist_id' => $playlist->id]);

        $this->actingAs($reader)
            ->get('/playlists')
            ->assertInertia(fn (Assert $page) => $page
                ->where('playlists.0.updatedAt', $playlist->fresh()->updated_at->toIso8601String())
            );
    }

    public function test_removing_a_track_counts_as_changing_the_playlist(): void
    {
        // Eloquent touches owners on DELETE as well as on save (Model::delete calls
        // touchOwners before the row goes), which is what makes a removal count too.
        $playlist = Playlist::factory()->create(['user_id' => ($reader = User::factory()->create())->id]);
        $entry = PlaylistTrack::factory()->create(['playlist_id' => $playlist->id]);
        $afterAdding = $playlist->fresh()->updated_at;

        $this->travel(1)->minute();
        $entry->delete();

        $this->assertTrue($playlist->fresh()->updated_at->greaterThan($afterAdding));

        $this->actingAs($reader)
            ->get('/playlists')
            ->assertInertia(fn (Assert $page) => $page
                ->where('playlists.0.tracks', 0)
                ->where('playlists.0.updatedAt', $playlist->fresh()->updated_at->toIso8601String())
            );
    }

    public function test_playlists_come_back_in_the_users_own_order(): void
    {
        $reader = User::factory()->create();
        Playlist::factory()->create(['user_id' => $reader->id, 'name' => 'Third', 'position' => 2]);
        Playlist::factory()->create(['user_id' => $reader->id, 'name' => 'First', 'position' => 0]);
        Playlist::factory()->create(['user_id' => $reader->id, 'name' => 'Second', 'position' => 1]);

        $this->actingAs($reader)
            ->get('/playlists')
            ->assertInertia(fn (Assert $page) => $page
                ->where('playlists.0.name', 'First')
                ->where('playlists.1.name', 'Second')
                ->where('playlists.2.name', 'Third')
            );
    }

    public function test_playlists_sharing_a_position_are_ordered_by_name(): void
    {
        /*
         * `position` defaults to 0, so every playlist made before anyone reorders
         * anything shares it. Without the name tiebreak SQL is free to return tied rows
         * in any order — which means the same page could list them differently on two
         * consecutive loads.
         */
        $reader = User::factory()->create();
        foreach (['Zydeco', 'Ambient', 'Metal'] as $name) {
            Playlist::factory()->create(['user_id' => $reader->id, 'name' => $name, 'position' => 0]);
        }

        $this->actingAs($reader)
            ->get('/playlists')
            ->assertInertia(fn (Assert $page) => $page
                ->where('playlists.0.name', 'Ambient')
                ->where('playlists.1.name', 'Metal')
                ->where('playlists.2.name', 'Zydeco')
            );
    }
}
