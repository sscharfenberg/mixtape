<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The dashboard's "update profile" section (PUT /user/profile-information,
 * Fortify's own ProfileInformationController deferring to App\Actions\Fortify\
 * UpdateUserProfileInformation). Changing the email address on a verified
 * account revokes verification and sends a fresh confirmation link — the
 * account can't skip re-confirming a new address just because the old one
 * was verified.
 */
class UpdateProfileInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->put('/user/profile-information', [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ])->assertRedirect('/login');
    }

    public function test_authenticated_user_can_update_their_name(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'same@example.com']);

        $this->actingAs($user)->put('/user/profile-information', [
            'name' => 'New Name',
            'email' => 'same@example.com',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertNotNull($user->email_verified_at); // unchanged email keeps verification
    }

    public function test_keeping_the_same_email_does_not_revoke_verification(): void
    {
        Notification::fake();
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $response = $this->actingAs($user)->put('/user/profile-information', [
            'name' => 'Ada L. Lovelace',
            'email' => 'ada@example.com',
        ]);

        $this->assertNotNull($user->fresh()->email_verified_at);
        Notification::assertNothingSent();

        // Stays on the dashboard, still logged in, with a success toast
        // (App\Http\Responses\ProfileInformationUpdatedResponse).
        $this->assertAuthenticatedAs($user);
        $response->assertSessionHas('type', 'success');
        $response->assertSessionHas('message');
    }

    public function test_changing_the_email_revokes_verification_and_resends_it(): void
    {
        Notification::fake();
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $response = $this->actingAs($user)->put('/user/profile-information', [
            'name' => 'Ada Lovelace',
            'email' => 'ada-new@example.com',
        ]);

        $user->refresh();
        $this->assertSame('ada-new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        // Regression: the dashboard route is `verified`-guarded, and this app
        // has no route named `verification.notice` (Laravel's default target
        // for that middleware) — redirecting back to /dashboard while
        // unverified would throw a RouteNotFoundException. The response must
        // log the user out and land on the guest landing page instead
        // (App\Http\Responses\ProfileInformationUpdatedResponse).
        $response->assertRedirect('/');
        $response->assertSessionHas('type', 'success');
        $response->assertSessionHas('message');
        $this->assertGuest();

        // Following through to /dashboard as a guest must redirect to login,
        // not blow up — this is the actual crash the user hit in production.
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/user/profile-information', [
            'name' => '',
            'email' => 'not-an-email',
        ])->assertSessionHasErrors(['name', 'email']);
    }

    public function test_rejects_a_name_already_taken_by_another_user(): void
    {
        User::factory()->create(['name' => 'Taken Name']);
        $user = User::factory()->create(['name' => 'My Name']);

        $this->actingAs($user)->put('/user/profile-information', [
            'name' => 'Taken Name',
            'email' => $user->email,
        ])->assertSessionHasErrors('name');
    }

    public function test_rejects_an_email_already_taken_by_another_user(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();

        $this->actingAs($user)->put('/user/profile-information', [
            'name' => $user->name,
            'email' => 'taken@example.com',
        ])->assertSessionHasErrors('email');
    }

    public function test_precognition_validates_only_the_field_being_checked(): void
    {
        $user = User::factory()->create();

        // Only 'name' is under test — 'email' is deliberately left blank/invalid
        // and must NOT be reported, or the live field-by-field validation would
        // flag every other field as the user tabs through the form.
        $this->actingAs($user)->withHeaders([
            'Precognition' => 'true',
            'Precognition-Validate-Only' => 'name',
        ])->putJson('/user/profile-information', [
            'name' => 'A Perfectly Fine Name',
            'email' => '',
        ])->assertNoContent();

        $this->assertSame($user->name, $user->fresh()->name); // no user was actually updated
    }

    public function test_precognition_reports_an_error_only_for_the_field_being_checked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withHeaders([
            'Precognition' => 'true',
            'Precognition-Validate-Only' => 'name',
        ])->putJson('/user/profile-information', [
            'name' => '', // invalid on its own
            'email' => '', // also invalid, but out of scope for this check
        ])->assertStatus(422)
            ->assertJsonValidationErrors('name')
            ->assertJsonMissingValidationErrors('email');
    }
}
