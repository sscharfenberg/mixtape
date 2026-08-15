<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Session login flow (Fortify, ignoreRoutes + explicit routes/web.auth.php).
 *
 * MixTape authenticates by the account `name`, not email (config/fortify.php
 * → 'username' => 'name'), so these tests drive the `name` credential. They run
 * on the isolated sqlite :memory: connection (phpunit.xml) and never reach the
 * real Postgres. Redirect targets that are config-backed ('/' = fortify.home)
 * are asserted exactly; framework-default guest/logout redirects are only
 * asserted to *be* redirects, to avoid coupling to their target.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_the_inertia_component(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/LoginPage'));
    }

    public function test_user_can_authenticate_by_name(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada Lovelace',
            'password' => Hash::make('s3cret-pass'),
        ]);

        $response = $this->post('/login', [
            'name' => 'Ada Lovelace',
            'password' => 's3cret-pass',
        ]);

        $response->assertRedirect('/dashboard'); // config('fortify.home')
        $this->assertAuthenticatedAs($user);

        // a fast (3000ms) success toast is flashed for the login (see LoginResponse).
        $response->assertSessionHas('message');
        $response->assertSessionHas('type', 'success');
        $response->assertSessionHas('duration', 3000);
    }

    public function test_login_flashes_a_fast_toast_onto_the_next_page(): void
    {
        User::factory()->create([
            'name' => 'Grace Hopper',
            'password' => Hash::make('correct-horse'),
        ]);

        // Pin the request locale: at POST time the user isn't authenticated yet,
        // so ConfigureLocale resolves the guest/browser locale (the test client
        // defaults to Accept-Language: en). Send `de` so the localized welcome
        // flash is resolved in the same locale the assertion's __() uses.
        $response = $this->withHeader('Accept-Language', 'de')
            ->followingRedirects()->post('/login', [
                'name' => 'Grace Hopper',
                'password' => 'correct-horse',
            ]);

        // The flash set by LoginResponse is shared by HandleInertiaRequests and
        // reaches the (dashboard) page's Inertia props, where ToastContainer
        // renders it. Duration is the fast 3000ms; nonce is a fresh string.
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('flash.message', __('flash.login.welcome', ['name' => 'Grace Hopper']))
            ->where('flash.type', 'success')
            ->where('flash.duration', 3000)
            ->where('flash.nonce', fn ($nonce) => is_string($nonce))
        );
    }

    public function test_login_is_rejected_with_the_wrong_password(): void
    {
        User::factory()->create([
            'name' => 'Grace Hopper',
            'password' => Hash::make('correct-horse'),
        ]);

        $response = $this->from('/login')->post('/login', [
            'name' => 'Grace Hopper',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('name'); // failed-credential error lands on the username field
        $this->assertGuest();
    }

    public function test_login_is_rejected_when_the_email_is_not_verified(): void
    {
        User::factory()->unverified()->create([
            'name' => 'Unverified User',
            'password' => Hash::make('s3cret-pass'),
        ]);

        $response = $this->from('/login')->post('/login', [
            'name' => 'Unverified User',
            'password' => 's3cret-pass',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('name'); // EnsureEmailIsVerified error lands on the username field
        $this->assertGuest();
    }

    public function test_a_wrong_password_never_reveals_that_an_account_is_unverified(): void
    {
        /*
         * THE LOGIN FORM MUST NOT BE AN ACCOUNT ORACLE. This instance logs in BY NAME, so a
         * response that distinguishes "unverified" from "credentials incorrect" hands a
         * stranger half a credential pair for any name they care to try — the same disclosure
         * ForgotController goes to deliberate lengths to avoid on its own form.
         *
         * Asserted as "the two failures are INDISTINGUISHABLE", not merely as "it is refused":
         * a refusal carrying a different message is exactly the leak.
         */
        User::factory()->unverified()->create([
            'name' => 'Unverified User',
            'password' => Hash::make('s3cret-pass'),
        ]);

        // Both must answer the GENERIC failure. Asserted against the literal message rather
        // than against each other, so the test also fails if the shared answer were ever the
        // verification one.
        $this->from('/login')
            ->post('/login', ['name' => 'Unverified User', 'password' => 'not-the-password'])
            ->assertSessionHasErrors(['name' => __('auth.failed')]);

        $this->from('/login')
            ->post('/login', ['name' => 'Nobody At All', 'password' => 'not-the-password'])
            ->assertSessionHasErrors(['name' => __('auth.failed')]);

        $this->assertGuest();
    }

    public function test_the_unverified_gate_also_covers_an_account_holding_two_factor(): void
    {
        /*
         * THE GATE HAS TO SIT IN FRONT OF BOTH LOGIN PATHS. `RedirectIfTwoFactorAuthenticatable`
         * runs before `AttemptToAuthenticate` and short-circuits into the challenge, so a step
         * placed after authentication would never see a user with 2FA — and that state is
         * reachable, because changing an e-mail address clears `email_verified_at` while the
         * second factor stays enabled. Such a reader must not be handed the challenge.
         */
        User::factory()->unverified()->create([
            'name' => 'Unverified With 2FA',
            'password' => Hash::make('s3cret-pass'),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);

        $response = $this->from('/login')->post('/login', [
            'name' => 'Unverified With 2FA',
            'password' => 's3cret-pass',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('name');
        // Not sent to the challenge, and not signed in.
        $this->assertGuest();
        $this->assertNull(session('login.id'));
    }

    public function test_the_sixth_attempt_in_a_minute_is_refused(): void
    {
        /*
         * THE BRUTE-FORCE GATE ON THE ONE INTERNET-FACING FORM THAT CARRIES A PASSWORD, and
         * until now it was only ever mentioned as an E2E hazard ("keep real logins under five
         * per run") rather than proven. Deleting `->middleware('throttle:login')` would leave
         * every other test in this file green.
         *
         * CACHE_STORE=array (phpunit.xml), so each test starts with an empty limiter and this
         * cannot leak into its neighbours.
         */
        User::factory()->create(['name' => 'Ashaltiriak', 'password' => Hash::make('s3cret-pass')]);

        foreach (range(1, 5) as $ignored) {
            $this->post('/login', ['name' => 'Ashaltiriak', 'password' => 'wrong'])
                ->assertSessionHasErrors('name');
        }

        $this->post('/login', ['name' => 'Ashaltiriak', 'password' => 'wrong'])
            ->assertStatus(429);

        // ...and the ceiling is not a free pass afterwards: the RIGHT password is refused too,
        // which is what makes it a lockout rather than a speed bump.
        $this->post('/login', ['name' => 'Ashaltiriak', 'password' => 's3cret-pass'])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_the_login_lockout_is_keyed_on_the_name_as_well_as_the_ip(): void
    {
        /*
         * TWO HOUSEMATES BEHIND ONE NAT MUST NOT LOCK EACH OTHER OUT. The limiter keys on
         * `lower(username)|ip` (FortifyServiceProvider), so exhausting one account's five
         * attempts leaves the other's untouched — from the same address, which is the whole
         * point on a box a family shares.
         */
        User::factory()->create(['name' => 'Ashaltiriak', 'password' => Hash::make('s3cret-pass')]);
        User::factory()->create(['name' => 'Housemate', 'password' => Hash::make('other-pass')]);

        foreach (range(1, 6) as $ignored) {
            $this->post('/login', ['name' => 'Ashaltiriak', 'password' => 'wrong']);
        }
        $this->post('/login', ['name' => 'Ashaltiriak', 'password' => 'wrong'])->assertStatus(429);

        // Same IP, different name: still has its full allowance, and a correct password works.
        $this->post('/login', ['name' => 'Housemate', 'password' => 'other-pass'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_login_requires_a_name_and_password(): void
    {
        $response = $this->from('/login')->post('/login', [
            'name' => '',
            'password' => '',
        ]);

        $response->assertSessionHasErrors(['name', 'password']);
        $this->assertGuest();
    }

    public function test_authenticated_user_is_redirected_away_from_the_login_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/login')
            ->assertRedirect();
    }

    public function test_user_can_log_out(): void
    {
        $response = $this->actingAs(User::factory()->create())->post('/logout');

        $response->assertRedirect();
        $this->assertGuest();

        // a fast (3000ms) success toast is flashed for the logout (see LogoutResponse).
        $response->assertSessionHas('message');
        $response->assertSessionHas('type', 'success');
        $response->assertSessionHas('duration', 3000);
    }
}
