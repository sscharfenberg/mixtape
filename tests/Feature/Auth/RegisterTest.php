<?php

namespace Tests\Feature\Auth;

use App\Models\Invite;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Invite-only registration (Fortify registration feature + explicit routes in
 * routes/web.auth.php). Onboarding requires a valid one-time invite: the GET
 * view rejects bad codes, and POST /register (App\Actions\Fortify\CreateNewUser)
 * validates + consumes the invite atomically. Tests run on the isolated sqlite
 * :memory: connection and never reach the debbie Postgres, so they avoid
 * asserting the Postgres-only case-insensitive `name` collation.
 */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mint an invite and return its plaintext code plus the model.
     *
     * @return array{0: string, 1: \App\Models\Invite}
     */
    private function invite(array $overrides = []): array
    {
        $code = Str::random(40);

        $invite = Invite::create(array_merge([
            'token' => Invite::hashCode($code),
            'valid_until' => now()->addDays(7),
        ], $overrides));

        return [$code, $invite];
    }

    /** A valid credentials payload for POST /register, minus the invite code. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Friend',
            'email' => 'friend@example.com',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ], $overrides);
    }

    public function test_register_page_renders_with_a_valid_code(): void
    {
        [$code] = $this->invite();

        $this->get('/register?code='.$code)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/RegisterPage')
                ->where('code', $code)
            );
    }

    public function test_register_page_rejects_a_missing_code(): void
    {
        $this->get('/register')->assertRedirect('/login');
    }

    public function test_register_page_rejects_an_unknown_code(): void
    {
        $this->get('/register?code='.Str::random(40))->assertRedirect('/login');
    }

    public function test_register_page_rejects_an_expired_code(): void
    {
        [$code] = $this->invite(['valid_until' => now()->subDay()]);

        $this->get('/register?code='.$code)->assertRedirect('/login');
    }

    public function test_a_valid_invite_creates_an_unverified_user_and_sends_verification(): void
    {
        Notification::fake();
        [$code, $invite] = $this->invite();

        $response = $this->post('/register', $this->payload([
            'email' => 'Friend@Example.COM', // stored lower-cased
            'code' => $code,
        ]));

        // Email verification is on, so the new (unverified) user is logged
        // straight back out and sent to the landing page with a success toast.
        $response->assertRedirect('/');
        $response->assertSessionHas('type', 'success');
        $this->assertGuest();

        $user = User::where('name', 'New Friend')->first();
        $this->assertNotNull($user);
        $this->assertSame('friend@example.com', $user->email);
        $this->assertNull($user->email_verified_at); // unverified until they click the link

        Notification::assertSentTo($user, VerifyEmailNotification::class);

        // single-use: the invite row is gone.
        $this->assertDatabaseMissing('invites', ['id' => $invite->id]);
    }

    public function test_registration_fails_with_an_expired_invite(): void
    {
        [$code, $invite] = $this->invite(['valid_until' => now()->subDay()]);

        $response = $this->from('/login')->post('/register', $this->payload(['code' => $code]));

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertDatabaseHas('invites', ['id' => $invite->id]); // not consumed
        $this->assertDatabaseMissing('users', ['name' => 'New Friend']);
    }

    public function test_registration_fails_without_a_code(): void
    {
        $response = $this->post('/register', $this->payload());

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_registration_validates_required_fields(): void
    {
        [$code] = $this->invite();

        $response = $this->post('/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'x',
            'password_confirmation' => 'y',
            'code' => $code,
        ]);

        $response->assertSessionHasErrors(['name', 'email', 'password']);
        $this->assertGuest();
    }

    public function test_registration_rejects_a_duplicate_name(): void
    {
        User::factory()->create(['name' => 'Ada Lovelace']);
        [$code] = $this->invite();

        $response = $this->post('/register', $this->payload([
            'name' => 'Ada Lovelace',
            'email' => 'ada2@example.com',
            'code' => $code,
        ]));

        $response->assertSessionHasErrors('name');
        $this->assertGuest();
    }
}
