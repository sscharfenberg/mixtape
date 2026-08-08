<?php

namespace Tests\Feature\Playlists;

use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Editing a playlist's metadata: the form page (`GET /playlists/{playlist}/edit`) and the
 * save itself (`PUT /playlists/{playlist}`).
 *
 * The field rules are shared with the create and pinned there (CreatePlaylistTest), so
 * this file is about the three things editing adds, all of which can only be got wrong
 * here:
 *
 *   - OWNERSHIP. These are the first playlist routes that take an id from the URL, so they
 *     are the first that could be pointed at someone else's. The answer is 404, not 403 —
 *     on a box shared with family and reachable from the internet, "you may not edit that"
 *     confirms the playlist exists, which is enough to walk the id space and learn what
 *     other people keep.
 *   - THE UNIQUE RULE IGNORING ITSELF. Without `ignore()`, saving a playlist you did not
 *     rename fails against its own row and reports the name as taken by the very playlist
 *     wearing it — the most confusing possible answer to pressing Save twice.
 *   - WHAT AN EDIT MUST NOT TOUCH: the tracks and the ordering. A rename is not a reorder.
 */
class UpdatePlaylistTest extends TestCase
{
    use RefreshDatabase;

    /** A playlist belonging to a fresh user, returned with its owner. @return array{User, Playlist} */
    private function owned(array $attributes = []): array
    {
        $user = User::factory()->create();

        return [$user, Playlist::factory()->create(['user_id' => $user->id] + $attributes)];
    }

    public function test_guests_cannot_reach_the_form(): void
    {
        [, $playlist] = $this->owned();

        $this->get("/playlists/{$playlist->id}/edit")->assertRedirect('/login');
    }

    public function test_guests_cannot_save(): void
    {
        [, $playlist] = $this->owned(['name' => 'Untouched']);

        $this->put("/playlists/{$playlist->id}", ['name' => 'Smuggled'])->assertRedirect('/login');

        $this->assertSame('Untouched', $playlist->fresh()->name);
    }

    public function test_the_form_shows_the_playlists_current_metadata(): void
    {
        [$user, $playlist] = $this->owned(['name' => 'Sunday morning', 'description' => 'Quiet things.']);

        $this->actingAs($user)
            ->get("/playlists/{$playlist->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Playlists/Metadata/PlaylistMetadataPage')
                ->where('playlist.id', $playlist->id)
                ->where('playlist.name', 'Sunday morning')
                ->where('playlist.description', 'Quiet things.')
            );
    }

    public function test_the_create_form_carries_no_playlist_which_is_what_tells_the_page_it_is_creating(): void
    {
        // The page has exactly one signal for which direction it is running in, so a create
        // that shipped anything but null here would render as an edit of nothing.
        $this->actingAs(User::factory()->create())
            ->get('/playlists/create')
            ->assertInertia(fn (Assert $page) => $page->where('playlist', null));
    }

    public function test_a_stranger_gets_a_404_rather_than_a_403_on_the_form(): void
    {
        [, $playlist] = $this->owned();

        $this->actingAs(User::factory()->create())
            ->get("/playlists/{$playlist->id}/edit")
            ->assertNotFound();
    }

    public function test_a_stranger_cannot_save_over_someone_elses_playlist(): void
    {
        [, $playlist] = $this->owned(['name' => 'Mine', 'description' => 'Hands off.']);

        $this->actingAs(User::factory()->create())
            ->put("/playlists/{$playlist->id}", ['name' => 'Theirs now'])
            ->assertNotFound();

        $playlist->refresh();
        $this->assertSame('Mine', $playlist->name);
        $this->assertSame('Hands off.', $playlist->description);
    }

    public function test_a_playlist_that_does_not_exist_is_a_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/playlists/019fe0c2-0000-7000-8000-000000000000/edit')
            ->assertNotFound();
    }

    public function test_a_non_uuid_id_404s_at_the_router(): void
    {
        // `whereUuid` on the route, so a stray segment never reaches model binding.
        $this->actingAs(User::factory()->create())
            ->get('/playlists/not-a-uuid/edit')
            ->assertNotFound();
    }

    public function test_it_saves_the_new_metadata_and_returns_to_the_listing(): void
    {
        [$user, $playlist] = $this->owned(['name' => 'Old name', 'description' => 'Old blurb.']);

        $this->actingAs($user)
            ->put("/playlists/{$playlist->id}", ['name' => 'New name', 'description' => 'New blurb.'])
            ->assertRedirect('/playlists')
            ->assertSessionHas('type', 'success');

        $playlist->refresh();
        $this->assertSame('New name', $playlist->name);
        $this->assertSame('New blurb.', $playlist->description);
    }

    public function test_saving_without_renaming_is_not_a_clash_with_itself(): void
    {
        // The whole reason the unique rule ignores the row being edited.
        [$user, $playlist] = $this->owned(['name' => 'Rock']);

        $this->actingAs($user)
            ->put("/playlists/{$playlist->id}", ['name' => 'Rock', 'description' => 'Now with a blurb.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Now with a blurb.', $playlist->fresh()->description);
    }

    public function test_it_still_refuses_a_name_another_of_the_readers_playlists_has(): void
    {
        [$user, $playlist] = $this->owned(['name' => 'Jazz']);
        Playlist::factory()->create(['user_id' => $user->id, 'name' => 'Rock']);

        $this->actingAs($user)
            ->put("/playlists/{$playlist->id}", ['name' => 'Rock'])
            ->assertSessionHasErrors(['name' => __('playlist.validation')['name.unique']]);

        $this->assertSame('Jazz', $playlist->fresh()->name);
    }

    public function test_it_allows_a_name_another_user_happens_to_use(): void
    {
        [$user, $playlist] = $this->owned(['name' => 'Jazz']);
        Playlist::factory()->create(['user_id' => User::factory()->create()->id, 'name' => 'Rock']);

        $this->actingAs($user)
            ->put("/playlists/{$playlist->id}", ['name' => 'Rock'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Rock', $playlist->fresh()->name);
    }

    public function test_clearing_the_description_stores_null(): void
    {
        [$user, $playlist] = $this->owned(['description' => 'Something.']);

        $this->actingAs($user)->put("/playlists/{$playlist->id}", ['name' => $playlist->name, 'description' => '']);

        $this->assertNull($playlist->fresh()->description);
    }

    public function test_whitespace_is_trimmed_on_the_way_in(): void
    {
        // The same cleaning the create does — both directions go through one `fields()`.
        [$user, $playlist] = $this->owned();

        $this->actingAs($user)
            ->put("/playlists/{$playlist->id}", ['name' => '  Rock  ', 'description' => '  Loud.  ']);

        $playlist->refresh();
        $this->assertSame('Rock', $playlist->name);
        $this->assertSame('Loud.', $playlist->description);
    }

    public function test_a_name_is_still_required(): void
    {
        [$user, $playlist] = $this->owned(['name' => 'Keep me']);

        $this->actingAs($user)
            ->put("/playlists/{$playlist->id}", ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame('Keep me', $playlist->fresh()->name);
    }

    public function test_an_edit_leaves_the_tracks_and_the_ordering_alone(): void
    {
        // A rename is not a reorder, and it is certainly not a change of contents.
        [$user, $playlist] = $this->owned(['name' => 'Old', 'position' => 7]);
        PlaylistTrack::factory()->count(3)->create(['playlist_id' => $playlist->id]);

        $this->actingAs($user)->put("/playlists/{$playlist->id}", ['name' => 'New']);

        $playlist->refresh();
        $this->assertSame(7, $playlist->position);
        $this->assertSame(3, $playlist->playlistTracks()->count());
    }

    public function test_it_validates_a_single_field_precognitively_without_saving_anything(): void
    {
        // What the form's validate-on-blur does: the name is checked against the reader's
        // OTHER playlists, nothing is written, and the missing description is not reported.
        [$user, $playlist] = $this->owned(['name' => 'Jazz']);
        Playlist::factory()->create(['user_id' => $user->id, 'name' => 'Rock']);

        $this->actingAs($user)
            ->putJson("/playlists/{$playlist->id}", ['name' => 'Rock'], [
                'Precognition' => 'true',
                'Precognition-Validate-Only' => 'name',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame('Jazz', $playlist->fresh()->name);
    }

    public function test_a_precognitive_check_of_the_playlists_own_name_passes(): void
    {
        // The `ignore()` has to hold on the live-validation path too, or the field would
        // light up red the moment a reader tabbed out of a name they had not touched.
        [$user, $playlist] = $this->owned(['name' => 'Rock']);

        $this->actingAs($user)
            ->putJson("/playlists/{$playlist->id}", ['name' => 'Rock'], [
                'Precognition' => 'true',
                'Precognition-Validate-Only' => 'name',
            ])
            ->assertNoContent();
    }

    public function test_a_stranger_cannot_use_the_precognitive_route_to_probe_a_playlist(): void
    {
        // The ownership check runs BEFORE validation, so the validate-only path cannot be
        // used to tell an existing playlist from a missing one.
        [, $playlist] = $this->owned();

        $this->actingAs(User::factory()->create())
            ->putJson("/playlists/{$playlist->id}", ['name' => 'Probe'], [
                'Precognition' => 'true',
                'Precognition-Validate-Only' => 'name',
            ])
            ->assertNotFound();
    }
}
