<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Account deletion (DELETE /user/delete, App\Http\Controllers\User\
 * DeleteAccountController). A hard delete with no recovery path — validates
 * the current password, logs the user out, invalidates the session, then
 * deletes the record. JSON requests (the confirmation modal) get a
 * `{redirect}` payload instead of a full Inertia redirect.
 */
class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->delete('/user/delete', ['password' => 'password'])
            ->assertRedirect('/login');
    }

    public function test_json_request_deletes_the_account_and_returns_a_redirect_payload(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson('/user/delete', ['password' => 'password'])
            ->assertOk()
            ->assertJson(['redirect' => '/']);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_non_json_request_redirects_home(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/user/delete', ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_rejects_an_incorrect_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson('/user/delete', ['password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_validates_required_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson('/user/delete', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
