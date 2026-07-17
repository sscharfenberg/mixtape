<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The dashboard's "change password" section (PUT /user/password, Fortify's
 * own PasswordController deferring to App\Actions\Fortify\UpdateUserPassword).
 * Same zxcvbn entropy gate as registration / reset (App\Rules\PasswordEntropy).
 */
class UpdatePasswordTest extends TestCase
{
    use RefreshDatabase;

    /** A valid payload for PUT /user/password, minus current_password. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'current_password' => 'password', // matches UserFactory's default hashed password
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ], $overrides);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->put('/user/password', $this->payload())->assertRedirect('/login');
    }

    public function test_authenticated_user_can_update_their_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put('/user/password', $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('super-secret-1', $user->fresh()->password));

        // Custom PasswordUpdateResponse (App\Http\Responses) — a toast, not
        // Fortify's default bare `status` session key.
        $response->assertSessionHas('type', 'success');
        $response->assertSessionHas('message');
    }

    public function test_rejects_an_incorrect_current_password(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;

        $this->actingAs($user)->put('/user/password', $this->payload([
            'current_password' => 'wrong-password',
        ]))->assertSessionHasErrors('current_password');

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_rejects_a_weak_new_password(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;

        $this->actingAs($user)->put('/user/password', $this->payload([
            'password' => 'password', // zxcvbn score 0
            'password_confirmation' => 'password',
        ]))->assertSessionHasErrors('password');

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_rejects_a_mismatched_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/user/password', $this->payload([
            'password_confirmation' => 'something-else-1',
        ]))->assertSessionHasErrors('password_confirmation');
    }

    public function test_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/user/password', [
            'current_password' => '',
            'password' => '',
            'password_confirmation' => '',
        ])->assertSessionHasErrors(['current_password', 'password']);
    }

    public function test_precognition_validates_only_the_field_being_checked(): void
    {
        $user = User::factory()->create();

        // Only 'current_password' is under test — 'password'/'password_confirmation'
        // are deliberately left blank and must NOT be reported, or the live
        // field-by-field validation would flag every field as the user types.
        $this->actingAs($user)->withHeaders([
            'Precognition' => 'true',
            'Precognition-Validate-Only' => 'current_password',
        ])->putJson('/user/password', [
            'current_password' => 'password', // matches UserFactory's default
            'password' => '',
            'password_confirmation' => '',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('password', $user->fresh()->password)); // unchanged
    }

    public function test_precognition_reports_an_error_only_for_the_field_being_checked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withHeaders([
            'Precognition' => 'true',
            'Precognition-Validate-Only' => 'current_password',
        ])->putJson('/user/password', [
            'current_password' => 'wrong-password',
            'password' => '',
            'password_confirmation' => '',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('current_password')
            ->assertJsonMissingValidationErrors(['password', 'password_confirmation']);
    }
}
