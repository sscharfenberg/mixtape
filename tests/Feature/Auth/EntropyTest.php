<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * The password-strength endpoint (App\Http\Controllers\Auth\EntropyController,
 * route name `password.entropy`) that backs the live registration meter. Returns
 * the zxcvbn score (0–4) so the meter matches the PasswordEntropy gate exactly.
 */
class EntropyTest extends TestCase
{
    public function test_it_scores_a_weak_password_low(): void
    {
        $this->postJson('/password/entropy', ['p' => 'password'])
            ->assertOk()
            ->assertJson(['score' => 0]);
    }

    public function test_it_scores_a_strong_password_high(): void
    {
        $this->postJson('/password/entropy', ['p' => '7xQ!va9RmZ2wLpKt'])
            ->assertOk()
            ->assertJsonPath('score', fn ($score) => $score >= 3);
    }

    public function test_it_rejects_an_empty_password(): void
    {
        /*
         * ASSERTED AS "422, AND NO SCORE" rather than against a particular body. The refusal is
         * ScorePasswordRequest's, so the payload is the framework's `{message, errors}` — and
         * `usePasswordEntropy` throws on any non-2xx before it reads a body, so what the meter
         * actually depends on is the STATUS. Pinning the shape would pin something no caller reads.
         */
        $this->postJson('/password/entropy', ['p' => ''])
            ->assertStatus(422)
            ->assertJsonMissingPath('score');
    }

    public function test_it_refuses_a_password_too_long_to_be_one(): void
    {
        /*
         * THE CEILING IS A DENIAL-OF-SERVICE GUARD, not tidiness. This route is unauthenticated
         * and allows 60/min/IP, and zxcvbn's matching is super-linear in the length of its input —
         * so without a bound the only limit on the work is nginx's body size. 255 is the same
         * bound the registration rules carry, so nothing scorable here could fail to be storable.
         */
        $this->postJson('/password/entropy', ['p' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('p');
    }
}
