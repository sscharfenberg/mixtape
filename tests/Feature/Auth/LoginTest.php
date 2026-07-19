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
