<?php

namespace Tests\Feature\Playlists;

use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Making a playlist: the form page (`GET /playlists/create`) and the create itself
 * (`POST /playlists`).
 *
 * Two things carry the weight. The first is that `name` is unique PER OWNER, not
 * globally — "your Rock ≠ my Rock" is the whole reason the migration's unique is a
 * composite, and a rule that forgot the `where` would look identical until the second
 * user tried to name a playlist something obvious. The second is the ERROR MESSAGE for
 * that clash: `validation.custom.name.*` is written for the username field, so without
 * the inline messages this form would answer "This username is already taken" — which
 * renders, reads as plausible, and is nonsense.
 *
 * WHAT THIS FILE CANNOT SEE, recorded because it already bit once: SQL DIALECT. This suite
 * runs on sqlite and production is PostgreSQL, and the two disagree silently rather than
 * loudly. The create's `max(position)` read once carried `lockForUpdate()`; sqlite compiles
 * `for update` to an empty string, so every test below passed, while Postgres rejects that
 * statement outright ("FOR UPDATE is not allowed with aggregate functions") and would have
 * 500-ed every create on the real server. There is no honest guard for that here — a test
 * asserting on the compiled SQL passes on sqlite whether the lock is present or not — so
 * anything touching row locks, window functions or aggregate shapes wants a query against
 * the dev database before it is called done (see CreatePlaylistController::create).
 */
class CreatePlaylistTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_reach_the_form(): void
    {
        $this->get('/playlists/create')->assertRedirect('/login');
    }

    public function test_guests_cannot_create_a_playlist(): void
    {
        $this->post('/playlists', ['name' => 'Smuggled'])->assertRedirect('/login');

        $this->assertDatabaseCount('playlists', 0);
    }

    public function test_the_form_page_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/playlists/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Playlists/Create/CreatePlaylistPage'));
    }

    public function test_the_create_route_does_not_swallow_the_word_create(): void
    {
        /*
         * `/playlists/create` has to keep matching the form route once a
         * `/playlists/{playlist}` detail page is registered beside it. Pinning it here
         * means the day that route is added, this test fails rather than the form
         * quietly becoming a 404 for a playlist whose id is "create".
         */
        $this->assertSame('/playlists/create', route('playlists.create', absolute: false));
    }

    public function test_it_creates_a_playlist_and_returns_to_the_listing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/playlists', ['name' => 'Sunday morning', 'description' => 'Quiet things.'])
            ->assertRedirect('/playlists')
            ->assertSessionHas('type', 'success');

        $playlist = Playlist::query()->sole();

        $this->assertSame($user->id, $playlist->user_id);
        $this->assertSame('Sunday morning', $playlist->name);
        $this->assertSame('Quiet things.', $playlist->description);
        // A new playlist holds nothing — tracks are added later, from a song or album page.
        $this->assertSame(0, $playlist->playlistTracks()->count());
    }

    public function test_an_empty_description_is_stored_as_null(): void
    {
        // An untouched textarea posts "", which is not the same as "no description":
        // stored as null so the page asks one question rather than two.
        $this->actingAs(User::factory()->create())
            ->post('/playlists', ['name' => 'Bare', 'description' => '']);

        $this->assertNull(Playlist::query()->sole()->description);
    }

    public function test_surrounding_whitespace_is_trimmed_off_the_name(): void
    {
        // Otherwise " Rock" and "Rock" are two different playlists to the unique index
        // and the same one to a reader.
        $this->actingAs(User::factory()->create())
            ->post('/playlists', ['name' => '  Rock  ', 'description' => '  Loud.  ']);

        $playlist = Playlist::query()->sole();

        $this->assertSame('Rock', $playlist->name);
        $this->assertSame('Loud.', $playlist->description);
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/playlists', ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('playlists', 0);
    }

    public function test_a_name_may_not_exceed_the_column(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/playlists', ['name' => str_repeat('a', 256)])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('playlists', 0);
    }

    public function test_a_description_is_bounded(): void
    {
        // `description` is a `text` column with no length of its own, so the only thing
        // between a paste and a free megabyte in the database is this rule.
        $this->actingAs(User::factory()->create())
            ->post('/playlists', ['name' => 'Long', 'description' => str_repeat('a', 1001)])
            ->assertSessionHasErrors('description');

        $this->assertDatabaseCount('playlists', 0);
    }

    public function test_a_user_cannot_reuse_one_of_their_own_names(): void
    {
        $user = User::factory()->create();
        Playlist::factory()->create(['user_id' => $user->id, 'name' => 'Rock']);

        $this->actingAs($user)
            ->post('/playlists', ['name' => 'Rock'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Playlist::query()->count());
    }

    public function test_the_clash_message_is_about_the_playlist_not_the_username(): void
    {
        $user = User::factory()->create();
        Playlist::factory()->create(['user_id' => $user->id, 'name' => 'Rock']);

        $this->actingAs($user)
            ->post('/playlists', ['name' => 'Rock'])
            ->assertSessionHasErrors(['name' => __('playlist.validation')['name.unique']]);
    }

    public function test_two_users_may_each_have_a_playlist_of_the_same_name(): void
    {
        // The whole reason the unique is a composite: your Rock is not my Rock.
        Playlist::factory()->create(['user_id' => User::factory()->create()->id, 'name' => 'Rock']);

        $this->actingAs(User::factory()->create())
            ->post('/playlists', ['name' => 'Rock'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Playlist::query()->count());
    }

    public function test_each_new_playlist_lands_at_the_end_of_the_users_order(): void
    {
        $user = User::factory()->create();
        Playlist::factory()->create(['user_id' => $user->id, 'name' => 'Existing', 'position' => 7]);

        $this->actingAs($user)->post('/playlists', ['name' => 'Newest']);

        $this->assertSame(8, Playlist::query()->where('name', 'Newest')->sole()->position);
    }

    public function test_the_first_playlist_starts_at_position_zero(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/playlists', ['name' => 'First']);

        $this->assertSame(0, Playlist::query()->sole()->position);
    }

    public function test_another_users_positions_do_not_push_mine_along(): void
    {
        // `max(position)` has to be scoped to the owner, or one user with a long list
        // would start everybody else's numbering somewhere in the hundreds.
        Playlist::factory()->create(['user_id' => User::factory()->create()->id, 'position' => 99]);

        $this->actingAs($user = User::factory()->create())->post('/playlists', ['name' => 'Mine']);

        $this->assertSame(0, Playlist::query()->where('user_id', $user->id)->sole()->position);
    }

    public function test_it_validates_a_single_field_precognitively_without_creating_anything(): void
    {
        // What the form's validate-on-blur does: the name is checked, nothing is written,
        // and the missing description is NOT reported as a problem.
        $user = User::factory()->create();
        Playlist::factory()->create(['user_id' => $user->id, 'name' => 'Rock']);

        $this->actingAs($user)
            ->postJson('/playlists', ['name' => 'Rock'], [
                'Precognition' => 'true',
                'Precognition-Validate-Only' => 'name',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, Playlist::query()->count());
    }

    public function test_a_precognitive_check_of_a_valid_name_writes_nothing(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/playlists', ['name' => 'Brand new'], [
                'Precognition' => 'true',
                'Precognition-Validate-Only' => 'name',
            ])
            ->assertNoContent();

        $this->assertDatabaseCount('playlists', 0);
    }
}
