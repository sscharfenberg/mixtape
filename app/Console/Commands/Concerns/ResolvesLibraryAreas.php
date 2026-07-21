<?php

namespace App\Console\Commands\Concerns;

use App\Enums\TrackType;
use Illuminate\Support\Facades\Log;

/**
 * Shared plumbing for the library commands (`app:update`, `app:clean`): parsing
 * the repeatable `--area` option into TrackTypes, describing the scanned scope,
 * and narrating headline lines to both the console and the `library` log channel.
 */
trait ResolvesLibraryAreas
{
    /**
     * Resolve `--area` values to TrackTypes (accepting either the area name or
     * the enum value). Returns all areas when none are given, or null on an
     * unknown value so the caller can exit INVALID.
     *
     * @return TrackType[]|null
     */
    protected function resolveAreas(): ?array
    {
        $requested = (array) $this->option('area');
        if ($requested === []) {
            return TrackType::cases();
        }

        $areas = [];
        foreach ($requested as $name) {
            $type = collect(TrackType::cases())
                ->first(fn (TrackType $t) => $t->libraryPathKey() === $name || $t->value === $name);

            if ($type === null) {
                $this->error("Unknown area '{$name}'. Valid areas: music, audiobooks, podcast_shows.");

                return null;
            }

            $areas[$type->value] = $type; // de-dupe by key
        }

        return array_values($areas);
    }

    /**
     * A human label for the scope: "all areas" or the comma-separated area names.
     *
     * @param  TrackType[]  $areas
     */
    protected function describeScope(array $areas): string
    {
        return count($areas) === count(TrackType::cases())
            ? 'all areas'
            : implode(', ', array_map(fn (TrackType $a) => $a->libraryPathKey(), $areas));
    }

    /** Write a headline line to both the console and the `library` log channel. */
    protected function narrate(string $line): void
    {
        $this->line($line);
        Log::channel('library')->info($line);
    }
}
