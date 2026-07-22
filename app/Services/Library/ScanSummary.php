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

    /** Total audio files found on disk across every scanned area. */
    public function discovered(): int
    {
        return $this->sum('discovered');
    }

    /** Total rows inserted (genuinely new audio) across every area. */
    public function inserted(): int
    {
        return $this->sum('inserted');
    }

    /** Total rows updated in place (same path, re-tagged) across every area. */
    public function updated(): int
    {
        return $this->sum('updated');
    }

    /** Total rows re-pointed to a new path (a move/rename, id kept) across every area. */
    public function renamed(): int
    {
        return $this->sum('renamed');
    }

    /** Total rows removed (their file vanished) across every area. */
    public function deleted(): int
    {
        return $this->sum('deleted');
    }

    /** Total files skipped as unreadable across every area. */
    public function errors(): int
    {
        return $this->sum('errors');
    }

    /** Sum one ScanResult counter over every area's result — the shared body of the totals above. */
    private function sum(string $field): int
    {
        return array_sum(array_map(fn (ScanResult $r) => $r->{$field}, $this->results));
    }
}
