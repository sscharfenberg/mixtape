<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Track>
 *
 * Defaults to a music track (artist + genre, no narrator); ->audiobook() flips
 * to an audiobook chapter (narrator, no artist/genre) so the state always
 * satisfies the tracks type-guard CHECK. `path` is unique (one file ⇒ one row)
 * and `content_hash` is unique by default — pass an explicit hash (or ->cloneOf)
 * to model a clone.
 */
class TrackFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => TrackType::Music,
            'collection_id' => Collection::factory(),
            'artist_id' => Artist::factory(),
            'genre_id' => Genre::factory(),
            'narrator_id' => null,
            'composer' => fake()->optional()->name(),
            'publisher' => fake()->optional()->company(),
            'name' => fake()->sentence(3),
            'path' => '/music/'.fake()->unique()->uuid().'.mp3',
            'content_hash' => hash('sha256', fake()->unique()->uuid()),
            'size' => fake()->numberBetween(2_000_000, 12_000_000),
            'modified_at' => fake()->dateTimeThisDecade(),
            'codec' => 'mp3',
            'channel' => Channel::Stereo,
            'duration' => fake()->randomFloat(2, 90, 600),
            'sample_rate' => 44100,
            'bit_rate' => fake()->randomElement([128000, 192000, 256000, 320000]),
            'vbr' => fake()->boolean(),
            'cover' => fake()->boolean(70),
            'track' => fake()->numberBetween(1, 14),
            'disc' => 1,
        ];
    }

    public function audiobook(): static
    {
        return $this->state(fn () => [
            'type' => TrackType::Audiobook,
            'collection_id' => Collection::factory()->audiobook(),
            'artist_id' => null,
            'genre_id' => null,
            'narrator_id' => Narrator::factory(),
            'composer' => null,
            'publisher' => null,
            'path' => '/audiobooks/'.fake()->unique()->uuid().'.mp3',
        ]);
    }

    /**
     * A clone of an existing track: byte-identical audio (same content_hash) at a
     * different path — the "x clones" case (same recording on album + best-of).
     */
    public function cloneOf(Track $source): static
    {
        return $this->state(fn () => [
            'content_hash' => $source->content_hash,
            'name' => $source->name,
        ]);
    }
}
