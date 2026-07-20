<?php

namespace Database\Factories;

use App\Models\Play;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Play>
 */
class PlayFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'track_id' => Track::factory(),
            'played_at' => fake()->dateTimeThisYear(),
        ];
    }
}
