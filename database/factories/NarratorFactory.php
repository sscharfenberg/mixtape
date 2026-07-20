<?php

namespace Database\Factories;

use App\Models\Narrator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Narrator>
 */
class NarratorFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->name(),
        ];
    }
}
