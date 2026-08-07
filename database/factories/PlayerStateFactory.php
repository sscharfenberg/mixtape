<?php

namespace Database\Factories;

use App\Models\PlayerState;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerState>
 */
class PlayerStateFactory extends Factory
{
    /**
     * An empty stored queue, in the shape PlayerStatePayload reads.
     *
     * The `version` is not decoration: that service refuses a blob carrying any other
     * value, so a factory writing a shape of its own would produce rows the app treats as
     * absent — which reads in a test as the sync being broken. Tests that care about
     * contents pass their own `queue`.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'queue' => [
                'version' => 1,
                'tracks' => [],
                'currentIndex' => -1,
                'repeat' => false,
                'shuffle' => false,
            ],
        ];
    }
}
