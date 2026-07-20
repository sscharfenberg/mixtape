<?php

namespace Database\Factories;

use App\Models\Genre;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Genre>
 */
class GenreFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        // A pool of real-ish genres; unique() keeps them distinct (name is a
        // case-insensitive unique column). The pool is large enough for the
        // handful a seeder creates before unique() would exhaust it.
        return [
            'name' => fake()->unique()->randomElement([
                'Rock', 'Pop', 'Jazz', 'Classical', 'Electronic', 'Hip-Hop',
                'Metal', 'Folk', 'Blues', 'Reggae', 'Soul', 'Funk', 'Ambient',
                'Punk', 'Country', 'R&B', 'Indie', 'House', 'Techno', 'Soundtrack',
            ]),
        ];
    }
}
