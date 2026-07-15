<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Email verification via the signed link from the verification email
 * (App\Http\Controllers\Auth\VerifyEmailController, route name `verify-email`).
 * Registration creates unverified users; this is how they become verified so
 * they can log in. Runs on the sqlite :memory: connection.
 */
class VerifyEmailTest extends TestCase
{
    use RefreshDatabase;

    /** Build the signed verification URL the app would email to $user. */
    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verify-email',
            Carbon::now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
        );
    }

    public function test_a_valid_signed_link_verifies_the_email_and_redirects_to_login(): void
    {
        Event::fake();
        $user = User::factory()->unverified()->create();

        $response = $this->get($this->verificationUrl($user));

        $response->assertRedirect('/login');
        $response->assertSessionHas('type', 'success');
        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatched(Verified::class);
    }

    public function test_an_invalid_hash_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verify-email',
            Carbon::now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1('wrong-email@example.com')]
        );

        $this->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_unsigned_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        // Correct route params, but no valid signature → the `signed` middleware rejects it.
        $this->get('/verify-email/'.$user->getKey().'/'.sha1($user->getEmailForVerification()))
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_already_verified_is_idempotent(): void
    {
        Event::fake();
        $user = User::factory()->create(); // verified by the factory

        $response = $this->get($this->verificationUrl($user));

        $response->assertRedirect('/login');
        Event::assertNotDispatched(Verified::class); // not re-fired for an already-verified user
    }
}
