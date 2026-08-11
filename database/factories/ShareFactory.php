<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Share;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Share>
 *
 * NO SUBJECT BY DEFAULT, which is the one thing worth knowing about this factory: the table
 * takes exactly one of four FKs (a CHECK enforces it on Postgres), so a default would have
 * to pick a kind — and every caller then has to remember to unset it before choosing its
 * own. The three states below are the honest way to say "a share OF something", and a bare
 * `Share::factory()->create()` is deliberately a row Postgres refuses.
 */
class ShareFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'valid_until' => now()->addDays(Share::LIFETIME_DAYS),
        ];
    }

    /** A share of one song. */
    public function ofSong(?Track $track = null): static
    {
        return $this->state(fn (): array => ['track_id' => $track?->id ?? Track::factory()]);
    }

    /** A share of one album — or, when the collection is an audiobook, of that. */
    public function ofAlbum(?Collection $album = null): static
    {
        return $this->state(fn (): array => ['collection_id' => $album?->id ?? Collection::factory()]);
    }

    /** A share of one artist: everything credited to them, including whatever a rescan adds. */
    public function ofArtist(?Artist $artist = null): static
    {
        return $this->state(fn (): array => ['artist_id' => $artist?->id ?? Artist::factory()]);
    }

    /**
     * A link whose week is up.
     *
     * An hour past rather than a moment past, so the state cannot be defeated by a test that
     * travels a little — and so a debugger stopping mid-test does not un-expire it.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => ['valid_until' => now()->subHour()]);
    }
}
