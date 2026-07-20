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
 * Defaults to a music album; use ->audiobook() / ->podcastShow() for the other
 * container kinds. Each state keeps the owner FKs consistent with the DB CHECK
 * (album → album_artist only, audiobook → author only, podcast_show → neither).
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
            'cover' => fake()->boolean(80),
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

    public function podcastShow(): static
    {
        return $this->state(fn () => [
            'type' => CollectionType::PodcastShow,
            'album_artist_id' => null,
            'author_id' => null,
        ]);
    }
}
