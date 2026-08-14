<?php

namespace Tests\Feature;

use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A form's live validation must not spend the allowance its own save needs.
 *
 * THE PROBLEM THIS PINS: a Precognition form validates against THE ROUTE IT SUBMITS TO with
 * the same verb, so a `throttle:` in front of that route counts the typing and the write in
 * one counter — and the reader's own tabbing is then what refuses their save. Measured:
 * fifteen scripted saves through the playlist form (thirty validate-on-blur requests, fifteen
 * writes) hit a hard 429 on `throttle:30,1,playlist-create`.
 * App\Http\Middleware\ThrottleRequests separates them, and this file is what says it works.
 *
 * IT READS THE RATE-LIMIT HEADERS rather than exhausting a ceiling, wherever it can. Two
 * requests and an `X-RateLimit-Remaining` answer the question exactly — is this write the
 * FIRST thing in its bucket, or the fourth? — where proving the same thing by collecting a
 * 429 would cost 150 requests on a route with a ceiling of 30. The one ceiling actually
 * exhausted here is `auth-mail`'s, which is small enough to be affordable.
 *
 * A validate-only response is a 204 and carries NO such headers: `abort(204)` leaves the
 * throttle middleware by exception, so the header-adding pass never runs. That is why every
 * assertion below reads the headers off the REAL request that follows.
 *
 * @see RateLimitBucketsTest for the other half of this rule — that each route names its own
 *      bucket in the first place.
 */
class PrecognitionThrottleTest extends TestCase
{
    use RefreshDatabase;

    /** What a Precognition client sends when one field has just been left. */
    private const VALIDATING = ['Precognition' => 'true', 'Precognition-Validate-Only' => 'description'];

    public function test_a_forms_live_validation_does_not_spend_the_writes_allowance(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->create(['user_id' => $user->id, 'description' => 'Erste Fassung.']);
        $payload = ['name' => $playlist->name, 'description' => 'Zweite Fassung.'];

        // Three validate-on-blur requests — about what tabbing through this form costs, and
        // each one answered 204 with the action never reached.
        foreach (range(1, 3) as $ignored) {
            $this->actingAs($user)
                ->putJson("/playlists/{$playlist->id}", $payload, self::VALIDATING)
                ->assertNoContent();
        }

        $write = $this->actingAs($user)->put("/playlists/{$playlist->id}", $payload);

        // THE WHOLE CLAIM, in one number: the save is the FIRST thing in its own counter.
        // Shared, this would read 26 — and at thirty saves' worth of typing it would read
        // nothing at all, because the save would have been refused.
        $this->assertSame('30', $write->headers->get('X-RateLimit-Limit'));
        $this->assertSame('29', $write->headers->get('X-RateLimit-Remaining'));
        $this->assertSame('Zweite Fassung.', $playlist->fresh()->description);
    }

    public function test_it_reaches_a_route_that_inherits_precognition_from_its_group(): void
    {
        /*
         * The two dashboard forms take `HandleControllerPrecognitiveRequest` from the GROUP
         * they sit in rather than from a `middleware()` call of their own, and the separation
         * asks the route which middleware it carries. `gatherMiddleware()` is what makes an
         * inherited one visible; were it not, these two would go on spending their write
         * budget on typing while every other form stopped.
         */
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        $payload = ['name' => 'Ada L', 'email' => 'ada@example.com'];

        $this->actingAs($user)
            ->putJson('/user/profile-information', $payload, [
                'Precognition' => 'true',
                'Precognition-Validate-Only' => 'name',
            ])
            ->assertNoContent();

        $write = $this->actingAs($user)->put('/user/profile-information', $payload);

        $this->assertSame('29', $write->headers->get('X-RateLimit-Remaining'));
    }

    public function test_a_named_limiters_send_budget_survives_the_form_being_typed_into(): void
    {
        /*
         * `auth-mail` allows six sends a minute per IP, and that number is an anti-abuse gate
         * on somebody else's inbox — it must not move. What must move is whose requests count
         * against it: six validations of the email field fill it exactly, and the honest send
         * that follows is refused.
         *
         * THIS IS THE CASE A HAND-ROLLED FIX MISSED TWICE (see FortifyServiceProvider's
         * comment): a named limiter's counter is keyed from the limiter's own `by()`, so two
         * arms passing the same key are one bucket with two ceilings — and the `isPrecognitive()`
         * it branched on is not even set yet when a limiter runs.
         */
        Notification::fake();
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        $payload = ['type' => 'password', 'email' => $user->email, 'name' => $user->name];

        foreach (range(1, 6) as $ignored) {
            $this->postJson('/forgot', $payload, [
                'Precognition' => 'true',
                'Precognition-Validate-Only' => 'email',
            ])->assertNoContent();
        }

        $this->post('/forgot', $payload)->assertRedirect(route('home'));
    }

    public function test_live_validation_has_a_ceiling_of_its_own(): void
    {
        // Five times the route's own — thirty against `auth-mail`'s six — because a form
        // spends several validations per save and a bucket that ran out would be the thing
        // refusing the save, which is what separating them was for. Exhausted rather than
        // read off a header only because this is the one ceiling small enough to afford: the
        // same proof on a numeric route would cost 150 requests.
        $payload = ['type' => 'name', 'email' => 'ada@example.com'];
        $headers = ['Precognition' => 'true', 'Precognition-Validate-Only' => 'email'];

        foreach (range(1, 30) as $ignored) {
            $this->postJson('/forgot', $payload, $headers)->assertNoContent();
        }

        $this->postJson('/forgot', $payload, $headers)->assertStatus(429);
    }

    public function test_a_route_that_does_not_enforce_precognition_ignores_the_claim(): void
    {
        /*
         * THE BYPASS THE ROUTE CHECK CLOSES. `PUT /playlists/order` carries no precognition
         * middleware, so nothing there answers a precognitive request with a 204 — it simply
         * performs the reorder. If the separation trusted the header alone, any client could
         * label a write "validate-only", write anyway, and do it out of a counter five times
         * the size. So both of these land in the one ordinary bucket.
         */
        $user = User::factory()->create();
        $ids = Playlist::factory()->count(2)->create(['user_id' => $user->id])->pluck('id')->all();
        $headers = ['Precognition' => 'true', 'Precognition-Validate-Only' => 'ids'];

        $claimed = $this->actingAs($user)->putJson('/playlists/order', ['ids' => $ids], $headers);
        $write = $this->actingAs($user)->putJson('/playlists/order', ['ids' => $ids]);

        // The ceiling was not multiplied…
        $this->assertSame('60', $claimed->headers->get('X-RateLimit-Limit'));
        // …and the write is the SECOND thing in the same counter, not the first in one of its own.
        $this->assertSame('58', $write->headers->get('X-RateLimit-Remaining'));
    }
}
