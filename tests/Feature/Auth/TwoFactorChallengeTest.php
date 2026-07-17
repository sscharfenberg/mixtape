<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The login-time two-factor challenge (Fortify's RedirectsIfTwoFactorAuthenticatable
 * in the login pipeline + TwoFactorAuthenticatedSessionController@store).
 *
 * The login form submits via fetch() with Accept: application/json (useLogin.ts),
 * so a 2FA-enabled user gets `{ two_factor: true }` instead of a redirect and the
 * challenge stays on the login page. These tests drive that JSON handshake: the
 * initial /login returns the flag without authenticating, and /two-factor-challenge
 * completes the session with either a TOTP code or a recovery code. Runs on the
 * isolated sqlite :memory: connection (phpunit.xml).
 */
class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    /** Fully enroll (confirmed) 2FA on a user; returns the plaintext secret. */
    private function enroll(User $user): string
    {
        $secret = (new Google2FA)->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(
                Collection::times(8, fn () => RecoveryCode::generate())->all()
            )),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $secret;
    }

    public function test_a_user_without_two_factor_logs_in_directly_over_json(): void
    {
        $user = User::factory()->create([
            'name' => 'Plain Jane',
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/login', ['name' => 'Plain Jane', 'password' => 'password'])
            ->assertOk()
            ->assertJson(['two_factor' => false])
            ->assertJsonPath('redirect', fn ($url) => str_contains((string) $url, '/dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_two_factor_user_is_challenged_before_authenticating(): void
    {
        $user = User::factory()->create([
            'name' => 'Secure Sam',
            'password' => Hash::make('password'),
        ]);
        $this->enroll($user);

        $this->postJson('/login', ['name' => 'Secure Sam', 'password' => 'password'])
            ->assertOk()
            ->assertJson(['two_factor' => true]);

        // Not authenticated yet — Fortify only holds the pending login id.
        $this->assertGuest();
    }

    public function test_the_challenge_completes_with_a_valid_code(): void
    {
        $user = User::factory()->create([
            'name' => 'Secure Sam',
            'password' => Hash::make('password'),
        ]);
        $secret = $this->enroll($user);

        $this->postJson('/login', ['name' => 'Secure Sam', 'password' => 'password'])
            ->assertJson(['two_factor' => true]);

        $this->postJson('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk()
            ->assertJsonPath('redirect', fn ($url) => str_contains((string) $url, '/dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_challenge_completes_with_a_valid_recovery_code(): void
    {
        $user = User::factory()->create([
            'name' => 'Secure Sam',
            'password' => Hash::make('password'),
        ]);
        $this->enroll($user);

        $this->postJson('/login', ['name' => 'Secure Sam', 'password' => 'password'])
            ->assertJson(['two_factor' => true]);

        $recoveryCode = $user->fresh()->recoveryCodes()[0];

        $this->postJson('/two-factor-challenge', ['recovery_code' => $recoveryCode])
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_challenge_rejects_an_invalid_code(): void
    {
        $user = User::factory()->create([
            'name' => 'Secure Sam',
            'password' => Hash::make('password'),
        ]);
        $this->enroll($user);

        $this->postJson('/login', ['name' => 'Secure Sam', 'password' => 'password'])
            ->assertJson(['two_factor' => true]);

        $this->postJson('/two-factor-challenge', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertGuest();
    }

    public function test_the_challenge_rejects_an_invalid_recovery_code(): void
    {
        $user = User::factory()->create([
            'name' => 'Secure Sam',
            'password' => Hash::make('password'),
        ]);
        $this->enroll($user);

        $this->postJson('/login', ['name' => 'Secure Sam', 'password' => 'password'])
            ->assertJson(['two_factor' => true]);

        $this->postJson('/two-factor-challenge', ['recovery_code' => 'invalid-recovery-code'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recovery_code');

        $this->assertGuest();
    }
}
