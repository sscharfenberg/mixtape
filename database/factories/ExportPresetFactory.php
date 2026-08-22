<?php

namespace Database\Factories;

use App\Models\ExportPreset;
use App\Models\User;
use App\Services\Playlists\PlaylistExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExportPreset>
 */
class ExportPresetFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // unique() guards the (user_id, name) composite in tests that make several
            // presets at once, as PlaylistFactory does for the same reason.
            'name' => ucfirst(fake()->unique()->word()),
            // Drawn from the service's own lists rather than from literals, so a factory row
            // is always a shape the renderer can actually produce.
            'format' => fake()->randomElement(PlaylistExport::FORMATS),
            'encoding' => fake()->randomElement(PlaylistExport::ENCODINGS),
            'path_prefix' => '/Volumes/media/music',
            // Not the default: a test that wants one says so, and the flag is unique per user
            // on production — a factory handing it out freely would make `count(3)` unusable.
            'is_default' => false,
        ];
    }

    /** The preset the export modal opens on. At most one per user — see the model. */
    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    /** A preset whose paths are relative — the car-USB-stick case the empty prefix exists for. */
    public function withoutPrefix(): static
    {
        return $this->state(fn (): array => ['path_prefix' => '']);
    }
}
