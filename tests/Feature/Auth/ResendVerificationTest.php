<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Resend verification email" (App\Http\Controllers\Auth\ResendVerification-
 * Controller, routes `verification.resend` / `verification.resend.store`).
 * Lets a user whose signed verification link expired request a fresh one
 * without logging in (login is blocked until verified). Requires name AND
 * email to match the same user, and always flashes the same generic success
 * message regardless of outcome, so the form can't be used to enumerate
 * registered accounts.
 */
class ResendVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resend_verification_page_renders(): void
    {
        $this->get('/resend-verification')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ResendVerificationPage'));
    }

    public function test_it_resends_the_verification_email_for_a_matching_unverified_user(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $response = $this->post('/resend-verification', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('type', 'success');
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_it_does_not_resend_for_an_already_verified_user(): void
    {
        Notification::fake();
        User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']); // verified by the factory

        $response = $this->post('/resend-verification', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        // Same success flash either way — the form must not reveal that the account is already verified.
        $response->assertRedirect('/');
        $response->assertSessionHas('type', 'success');
        Notification::assertNothingSent();
    }

    public function test_it_does_not_reveal_whether_the_account_exists(): void
    {
        Notification::fake();

        $response = $this->post('/resend-verification', [
            'name' => 'Nobody Here',
            'email' => 'nobody@example.com',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('type', 'success');
        Notification::assertNothingSent();
    }

    public function test_it_does_not_resend_when_name_and_email_belong_to_different_users(): void
    {
        Notification::fake();
        User::factory()->unverified()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        User::factory()->unverified()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);

        // Name from one account, email from another — must not match either user.
        $response = $this->post('/resend-verification', [
            'name' => 'Ada Lovelace',
            'email' => 'grace@example.com',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('type', 'success');
        Notification::assertNothingSent();
    }

    public function test_it_validates_required_fields(): void
    {
        $response = $this->post('/resend-verification', [
            'name' => '',
            'email' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors(['name', 'email']);
    }

    public function test_precognition_validates_a_single_field_without_sending_anything(): void
    {
        Notification::fake();
        User::factory()->unverified()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $this->withHeaders([
            'Precognition' => 'true',
            'Precognition-Validate-Only' => 'name',
        ])->postJson('/resend-verification', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ])->assertNoContent();

        Notification::assertNothingSent();
    }
}
