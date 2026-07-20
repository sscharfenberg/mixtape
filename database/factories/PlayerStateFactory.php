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
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // The queue shape the client persists: ordered track ids + cursor.
            'queue' => [
                'items' => [],
                'current_index' => 0,
                'position_ms' => 0,
            ],
        ];
    }
}
