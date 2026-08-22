<?php

namespace Tests\Feature;

use App\Models\ExportPreset;
use App\Models\Invite;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A PRECOGNITIVE REQUEST MUST DO NOTHING, AND MUST STILL VALIDATE. Every route in the app that
 * speaks Precognition, both ways round.
 *
 * THE TWO FAILURES THIS GUARDS ARE SILENT, AND THEY ARE OPPOSITES. Which of the two precognition
 * middlewares a route needs depends on WHERE its rules live, and picking wrong breaks nothing
 * visibly:
 *
 *   - Rules in a FORM REQUEST + the app's HandleControllerPrecognitiveRequest → the action RUNS.
 *     Measured on five real routes: `Precognition: true` with no `Precognition-Validate-Only`
 *     creates playlists, writes metadata, sends password-reset and verification mail, and resets a
 *     password — consuming its single-use token and logging the session in.
 *   - Rules in the ACTION (Fortify's, wrapped in `precognitive()`) + the framework's
 *     HandlePrecognitiveRequests → nothing is validated. A value the rule cannot accept answers
 *     204 `Precognition-Success: true`, so a register form reports a taken username as free.
 *
 * So each subject below is asserted twice: a bare CLAIM leaves no trace, and a validate-only
 * request on a field that cannot pass comes back 422 naming it.
 *
 * IT WALKS THE ROUTE TABLE, like RateLimitBucketsTest, and that is the load-bearing half: a NEW
 * precognition route fails this file until someone adds it here, which means proving it is safe. A
 * test that only knew about today's eight would say nothing about tomorrow's ninth.
 *
 * The bare-claim shape is the one worth firing rather than validate-only: validate-only is stopped
 * by a mechanism the app shares with every route (the FormRequest hook, or the action's own
 * `precognitive()`), while the claim alone is stopped by the middleware CHOICE, which is the thing
 * that can be got wrong per route.
 */
class PrecognitionSideEffectsTest extends TestCase
{
    use RefreshDatabase;

    /** `Precognition: true` and nothing else — a request that promises to do nothing. */
    private const CLAIM = ['Precognition' => 'true'];

    /**
     * Every route that speaks Precognition, keyed by route name.
     *
     * Each entry says how to reach the route with data that would REALLY work, how to tell whether
     * it did the thing anyway, and one field whose value here cannot pass its own rule (so live
     * validation has something to catch).
     *
     * @return array<string, array{
     *     send: callable(): array{0: string, 1: string, 2: array<string, mixed>},
     *     traced: callable(): bool,
     *     invalid: array{0: string, 1: array<string, mixed>}
     * }>
     */
    private function subjects(): array
    {
        return [
            'playlists.store' => [
                'send' => function () {
                    $user = User::factory()->create();
                    $this->actingAs($user);

                    return ['post', '/playlists', ['name' => 'Nachtfahrt', 'description' => 'x']];
                },
                'traced' => fn () => Playlist::query()->where('name', 'Nachtfahrt')->exists(),
                // A name past the 255-character limit the request enforces.
                'invalid' => ['name', ['name' => str_repeat('a', 300), 'description' => '']],
            ],
            'playlists.update' => [
                'send' => function () {
                    $user = User::factory()->create();
                    $playlist = Playlist::factory()->create(['user_id' => $user->id, 'description' => 'before']);
                    $this->actingAs($user);

                    return ['put', "/playlists/{$playlist->id}", ['name' => $playlist->name, 'description' => 'after']];
                },
                'traced' => fn () => Playlist::query()->where('description', 'after')->exists(),
                'invalid' => ['name', ['name' => '', 'description' => '']],
            ],
            'dashboard.presets.store' => [
                'send' => function () {
                    $user = User::factory()->create();
                    $this->actingAs($user);

                    return ['post', '/dashboard/export-presets', [
                        'name' => 'Nachtzug',
                        'format' => 'simple',
                        'encoding' => 'UTF-8',
                        'path_prefix' => '/Volumes/media/music',
                    ]];
                },
                'traced' => fn () => ExportPreset::query()->where('name', 'Nachtzug')->exists(),
                // A name past the 60-character limit the request enforces.
                'invalid' => ['name', ['name' => str_repeat('a', 80), 'format' => 'simple', 'encoding' => 'UTF-8', 'path_prefix' => '']],
            ],
            'dashboard.presets.update' => [
                'send' => function () {
                    $user = User::factory()->create();
                    $preset = ExportPreset::factory()->for($user)->create(['path_prefix' => '/before']);
                    $this->actingAs($user);

                    return ['put', "/dashboard/export-presets/{$preset->id}", [
                        'name' => $preset->name,
                        'format' => $preset->format,
                        'encoding' => $preset->encoding,
                        'path_prefix' => '/after',
                    ]];
                },
                'traced' => fn () => ExportPreset::query()->where('path_prefix', '/after')->exists(),
                'invalid' => ['name', ['name' => '', 'format' => 'simple', 'encoding' => 'UTF-8', 'path_prefix' => '']],
            ],
            'register.store' => [
                'send' => function () {
                    $code = Str::random(32);
                    Invite::create([
                        'token' => Invite::hashCode($code),
                        'note' => 'precognition guard',
                        'valid_until' => now()->addDay(),
                    ]);

                    return ['post', '/register', [
                        'name' => 'Newcomer',
                        'email' => 'newcomer@example.com',
                        'password' => 'Str0ng-New-Passphrase!42',
                        'password_confirmation' => 'Str0ng-New-Passphrase!42',
                        'code' => $code,
                    ]];
                },
                'traced' => fn () => User::query()->where('email', 'newcomer@example.com')->exists(),
                // `unique:users` — the name is already taken by the user this creates first.
                'invalid' => ['name', ['name' => 'Taken Already', 'email' => 'other@example.com']],
            ],
            'forgot.store' => [
                'send' => function () {
                    $user = User::factory()->create(['name' => 'Ada Forgot', 'email' => 'forgot@example.com']);

                    return ['post', '/forgot', ['type' => 'password', 'email' => $user->email, 'name' => $user->name]];
                },
                'traced' => fn () => $this->anythingWasSent(),
                'invalid' => ['email', ['type' => 'password', 'email' => 'not-an-email', 'name' => 'Ada Forgot']],
            ],
            'password.reset.store' => [
                'send' => function () {
                    $user = User::factory()->create([
                        'email' => 'reset@example.com',
                        'password' => Hash::make('the-old-password'),
                    ]);

                    return ['post', '/reset-password', [
                        'token' => Password::broker(config('fortify.passwords'))->createToken($user),
                        'email' => $user->email,
                        'password' => 'Str0ng-New-Passphrase!42',
                        'password_confirmation' => 'Str0ng-New-Passphrase!42',
                    ]];
                },
                'traced' => fn () => ! Hash::check('the-old-password', User::query()->where('email', 'reset@example.com')->value('password')),
                'invalid' => ['email', ['token' => 'x', 'email' => 'not-an-email', 'password' => 'x', 'password_confirmation' => 'x']],
            ],
            'verification.resend.store' => [
                'send' => function () {
                    $user = User::factory()->unverified()->create(['name' => 'Ada Resend', 'email' => 'resend@example.com']);

                    return ['post', '/resend-verification', ['name' => $user->name, 'email' => $user->email]];
                },
                'traced' => fn () => $this->anythingWasSent(),
                'invalid' => ['email', ['name' => 'Ada Resend', 'email' => 'not-an-email']],
            ],
            'user-profile-information.update' => [
                'send' => function () {
                    $user = User::factory()->create(['name' => 'Old Name', 'email' => 'profile@example.com']);
                    $this->actingAs($user);

                    return ['put', '/user/profile-information', ['name' => 'New Name', 'email' => $user->email]];
                },
                'traced' => fn () => User::query()->where('name', 'New Name')->exists(),
                'invalid' => ['name', ['name' => 'no', 'email' => 'profile@example.com']],
            ],
            'user-password.update' => [
                'send' => function () {
                    $user = User::factory()->create(['password' => Hash::make('the-old-password')]);
                    $this->actingAs($user);

                    return ['put', '/user/password', [
                        'current_password' => 'the-old-password',
                        'password' => 'Str0ng-New-Passphrase!42',
                        'password_confirmation' => 'Str0ng-New-Passphrase!42',
                    ]];
                },
                'traced' => fn () => ! Hash::check('the-old-password', User::query()->latest('id')->value('password')),
                'invalid' => ['password', [
                    'current_password' => 'the-old-password',
                    'password' => 'short',
                    'password_confirmation' => 'short',
                ]],
            ],
        ];
    }

    /**
     * Forget who is signed in, between subjects.
     *
     * These loops walk routes with opposite requirements — `register.store` and the two mail routes
     * are GUEST-only, the playlist and dashboard ones are `auth` — and a subject that signed in
     * would leave the next one redirected to the dashboard rather than validated. `actingAs` sets a
     * user on a resolved guard, so both halves are needed: drop the guards and empty the session.
     */
    private function signOut(): void
    {
        $this->app->make('auth')->forgetGuards();
        $this->flushSession();
    }

    /** Whether the notification fake recorded anything at all — the trace a mail route leaves. */
    private function anythingWasSent(): bool
    {
        try {
            Notification::assertNothingSent();

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    public function test_every_precognition_route_is_covered_here(): void
    {
        $speaking = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && is_a(Str::before($middleware, ':'), HandlePrecognitiveRequests::class, true)) {
                    $speaking[] = $route->getName() ?? $route->uri();
                }
            }
        }

        sort($speaking);
        $covered = array_keys($this->subjects());
        sort($covered);

        $this->assertSame($covered, $speaking, implode("\n", [
            'A route speaks Precognition without being proven safe here (or one listed here is gone).',
            'Add it to subjects() with a payload, a way to tell whether it did the thing anyway, and',
            "a field that cannot pass its own rule — then this file will hold it to both halves:\n",
        ]));
    }

    public function test_a_claim_alone_leaves_no_trace(): void
    {
        foreach ($this->subjects() as $name => $subject) {
            $this->signOut();
            Notification::fake();
            [$method, $uri, $payload] = ($subject['send'])();

            /** @var TestResponse $response */
            $response = $this->{$method.'Json'}($uri, $payload, self::CLAIM);

            $this->assertFalse(
                ($subject['traced'])(),
                "{$name} DID THE THING for a request that only claimed precognition (status {$response->status()})."
            );
            $this->assertSame(204, $response->status(), "{$name} answered a bare precognitive claim with something other than 204.");
            $this->assertSame('true', $response->headers->get('Precognition-Success'), "{$name} did not answer as a precognition success.");
        }
    }

    public function test_live_validation_still_catches_a_bad_field(): void
    {
        foreach ($this->subjects() as $name => $subject) {
            $this->signOut();
            Notification::fake();
            [$method, $uri] = ($subject['send'])();
            [$field, $payload] = $subject['invalid'];

            // `register.store` validates `unique:users` against a name this makes taken first.
            if ($name === 'register.store') {
                User::factory()->create(['name' => 'Taken Already', 'email' => 'taken@example.com']);
            }

            /** @var TestResponse $response */
            $response = $this->{$method.'Json'}($uri, $payload, [
                'Precognition' => 'true',
                'Precognition-Validate-Only' => $field,
            ]);

            $response->assertStatus(422);
            $this->assertArrayHasKey(
                $field,
                $response->json('errors') ?? [],
                "{$name} did not report `{$field}` as invalid — the precognition middleware may be the wrong one for where its rules live."
            );
        }
    }
}
