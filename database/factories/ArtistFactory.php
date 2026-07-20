<?php

namespace Database\Factories;

use App\Models\Artist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Artist>
 */
class ArtistFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // unique() because artists.name is a case-insensitive unique column.
            'name' => fake()->unique()->name(),
        ];
    }
}
