<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Track;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaylistTrack>
 */
class PlaylistTrackFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'playlist_id' => Playlist::factory(),
            'track_id' => Track::factory(),
            'position' => fake()->numberBetween(0, 50),
        ];
    }
}
