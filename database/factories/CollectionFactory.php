<?php

namespace Database\Factories;

use App\Enums\CollectionType;
use App\Models\Artist;
use App\Models\Author;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Collection>
 *
 * container kinds. Each state keeps the owner FKs consistent with the DB CHECK
 * (album → album_artist only, audiobook → author only).
 */
class CollectionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => CollectionType::Album,
            'name' => fake()->unique()->sentence(3),
            'year' => fake()->numberBetween(1960, 2024),
            // No directory image by default: a factory cannot put a real file on disk,
            // and a path pointing at nothing would make every test that renders a
            // cover URL link an image that 404s. Tests that want one set it (and write
            // the file) themselves — see AlbumCoverTest.
            'cover_path' => null,
            'album_artist_id' => Artist::factory(),
            'author_id' => null,
        ];
    }

    public function audiobook(): static
    {
        return $this->state(fn () => [
            'type' => CollectionType::Audiobook,
            'album_artist_id' => null,
            'author_id' => Author::factory(),
        ]);
    }
}
