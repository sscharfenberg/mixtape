<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Two-factor authentication management (Fortify's built-in controllers, wired
 * explicitly in routes/web.auth.php; enable/disable/confirm/QR/secret-key/
 * recovery-codes). The feature is opt-in and configured with confirm => true
 * (enrollment needs a verified TOTP code) and confirmPassword => true (every
 * management route sits behind Fortify's `password.confirm` middleware, fed by
 * POST /confirm-password → App\Http\Controllers\Auth\ConfirmPasswordController).
 *
 * Tests run on the isolated sqlite :memory: connection (phpunit.xml). The
 * management routes are driven with a pre-confirmed password session
 * (`auth.password_confirmed_at`) so each case stays isolated; the gate itself
 * (and the confirm-password endpoint) are tested separately.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fully enroll (and by default confirm) 2FA on a user, mirroring what
     * Fortify's enable + confirm actions persist. Returns the plaintext secret
     * so a test can generate a valid current OTP.
     */
    private function enroll(User $user, bool $confirmed = true): string
    {
        $secret = (new Google2FA)->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(
                Collection::times(8, fn () => RecoveryCode::generate())->all()
            )),
            'two_factor_confirmed_at' => $confirmed ? now() : null,
        ])->save();

        return $secret;
    }

    /** A session with the password freshly confirmed (passes `password.confirm`). */
    private function confirmedSession(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    public function test_dashboard_exposes_the_two_factor_props_when_disabled(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/DashboardPage')
                ->where('twoFactorEnabled', false)
                ->where('requiresConfirmation', true)
                ->where('requiresPasswordConfirmation', true)
            );
    }

    public function test_dashboard_reports_two_factor_enabled_once_confirmed(): void
    {
        $user = User::factory()->create();
        $this->enroll($user);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('twoFactorEnabled', true));
    }

    public function test_management_routes_require_a_confirmed_password(): void
    {
        // No `auth.password_confirmed_at` in the session → the password.confirm
        // middleware answers 423 for a JSON request.
        $this->actingAs(User::factory()->create())
            ->postJson('/user/two-factor-authentication')
            ->assertStatus(423);
    }

    public function test_management_routes_reject_guests(): void
    {
        $this->postJson('/user/two-factor-authentication')->assertUnauthorized();
        $this->getJson('/user/two-factor-recovery-codes')->assertUnauthorized();
    }

    public function test_enabling_generates_a_secret_but_does_not_confirm_yet(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->confirmedSession())
            ->postJson('/user/two-factor-authentication')
            ->assertOk();

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        // confirm => true, so 2FA is not "enabled" until the TOTP code is verified.
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_confirming_with_a_valid_code_activates_two_factor(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->confirmedSession())
            ->postJson('/user/two-factor-authentication')
            ->assertOk();

        $secret = decrypt($user->fresh()->two_factor_secret);
        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->actingAs($user)
            ->withSession($this->confirmedSession())
            ->postJson('/user/confirmed-two-factor-authentication', ['code' => $code])
            ->assertOk();

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_confirming_with_an_invalid_code_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->confirmedSession())
            ->postJson('/user/two-factor-authentication')
            ->assertOk();

        $this->actingAs($user)
            ->withSession($this->confirmedSession())
            ->postJson('/user/confirmed-two-factor-authentication', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_qr_code_and_secret_key_are_available_during_setup(): void
    {
        $user = User::factory()->create();
        $this->enroll($user);

        $this->actingAs($user)
            ->withSession($this->confirmedSession())
            ->getJson('/user/two-factor-qr-code')
            ->assertOk()
            ->assertJsonStructure(['svg']);

        $this->actingAs($user)
            ->withSession($this->confirmedSession())
            ->getJson('/user/two-factor-secret-key')
            ->assertOk()
            ->assertJsonStructure(['secretKey']);
    }

    public function test_recovery_codes_can_be_listed(): void
    {
        $user = User::factory()->create();
        $this->enroll($user);

        $this->actingAs($user)
            ->withSession($this->confirmedSession())
            ->getJson('/user/two-factor-recovery-codes')
            ->assertOk()
            ->assertJsonCount(8);
    }

    public function test_recovery_codes_can_be_regenerated(): void
    {
        $user = User::factory()->create();
        $this->enroll($user);
        $original = $user->recoveryCodes();

        $this->actingAs($user)
            ->withSession($this->confirmedSession())
            ->postJson('/user/two-factor-recovery-codes')
            ->assertOk();

        $this->assertNotSame($original, $user->fresh()->recoveryCodes());
        $this->assertCount(8, $user->fresh()->recoveryCodes());
    }

    public function test_two_factor_can_be_disabled(): void
    {
        $user = User::factory()->create();
        $this->enroll($user);

        $this->actingAs($user)
            ->withSession($this->confirmedSession())
            ->deleteJson('/user/two-factor-authentication')
            ->assertOk();

        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_confirm_password_rejects_the_wrong_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $this->actingAs($user)
            ->postJson('/confirm-password', ['password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Das angegebene Passwort ist falsch.');
    }

    public function test_confirm_password_accepts_the_correct_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $this->actingAs($user)
            ->postJson('/confirm-password', ['password' => 'correct-horse'])
            ->assertOk()
            ->assertJson(['confirmed' => true]);
    }
}
