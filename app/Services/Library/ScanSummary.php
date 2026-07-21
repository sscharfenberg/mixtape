<?php

namespace App\Services\Library;

/**
 * Aggregate of every area's ScanResult from one `app:update` run — what the
 * command narrates as the closing totals line.
 */
final class ScanSummary
{
    /** @param  array<string, ScanResult>  $results  keyed by TrackType value */
    public function __construct(public readonly array $results) {}

    public function discovered(): int
    {
        return $this->sum('discovered');
    }

    public function inserted(): int
    {
        return $this->sum('inserted');
    }

    public function updated(): int
    {
        return $this->sum('updated');
    }

    public function renamed(): int
    {
        return $this->sum('renamed');
    }

    public function deleted(): int
    {
        return $this->sum('deleted');
    }

    public function errors(): int
    {
        return $this->sum('errors');
    }

    private function sum(string $field): int
    {
        return array_sum(array_map(fn (ScanResult $r) => $r->{$field}, $this->results));
    }
}
