<?php

declare(strict_types=1);

namespace App\Services\Library\Audit;

use App\Enums\TrackType;

/**
 * What one audit run is looking at: the areas asked for, and the disk walk behind them.
 *
 * IT EXISTS TO MAKE THE WALK LAZY. A check declares whether it reads the disk, and the index is
 * built on the first check that actually asks for it — so `--check=incomplete-albums` never
 * touches `/var/media`, and a run of every check walks it exactly once. Passing a
 * {@see LibraryFileIndex} into the checks directly would have forced the walk before the first
 * query, which is the cost the `--check=` option exists to avoid.
 */
final class AuditScope
{
    private ?LibraryFileIndex $files = null;

    private ?TrackNumberCollisions $collisions = null;

    /** @param TrackType[] $areas the areas this run was asked for, in registry order */
    public function __construct(public readonly array $areas) {}

    /** The disk walk, built on the first ask and reused by every check after it. */
    public function files(): LibraryFileIndex
    {
        return $this->files ??= LibraryFileIndex::for($this->areas);
    }

    /**
     * The track-number collisions, computed on the first ask and reused by the second.
     *
     * HERE FOR THE SAME REASON THE WALK IS. Two checks read the same detection from opposite
     * sides — inside one folder it is duplicate numbering, across two it is two albums in one row
     * — and each resolving its own instance from the container would run the detection's two
     * full-table queries twice per audit, with the memo inside it never firing. The scope is the
     * one thing both checks are handed, so it is where work shared between checks belongs; a
     * container singleton would do it too, at the cost of caching a library's collisions for the
     * lifetime of any process that ever asked.
     */
    public function collisions(): TrackNumberCollisions
    {
        return $this->collisions ??= new TrackNumberCollisions;
    }

    /**
     * How many audio files the walk examined, or 0 if nothing ever asked for it.
     *
     * Deliberately does NOT build the index: it is read once at the end of a run to put a
     * denominator in the report ("3 of 9,861"), and a database-only run has no denominator to
     * give rather than a walk waiting to happen.
     */
    public function scanned(): int
    {
        return $this->files?->scanned() ?? 0;
    }

    /**
     * The areas this run and a given check have in common.
     *
     * `in_array` with strict comparison rather than `array_intersect`, which stringifies its
     * arguments and cannot take an enum at all.
     *
     * @param  TrackType[]  $supported  the areas a check knows how to answer for
     * @return TrackType[]
     */
    public function overlap(array $supported): array
    {
        return array_values(array_filter(
            $this->areas,
            fn (TrackType $area) => in_array($area, $supported, true),
        ));
    }
}
