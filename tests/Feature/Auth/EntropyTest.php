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
        $this->postJson('/password/entropy', ['p' => ''])
            ->assertStatus(422)
            ->assertJson(['score' => null]);
    }
}
