<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;

/** Files sampled below CD rate — 44.1 kHz is the floor a rip should never be under. */
final class LowSampleRateCheck extends TrackCheck
{
    /** Hygiene: a property of the encode, and the only fix is a better file. */
    public function group(): AuditGroup
    {
        return AuditGroup::Hygiene;
    }

    /** Names the threshold's meaning (CD rate) rather than the number, which is in the blurb. */
    public function title(): string
    {
        return 'Files below CD sample rate';
    }

    /** Why the threshold is 44.1 kHz and why bit rate is deliberately not asked about here. */
    public function blurb(): string
    {
        return 'Below 44.1 kHz a file was downsampled somewhere in its history — usually an old download or a '
            .'transcode — and the top of the spectrum is gone for good. Bit rate is NOT audited: 128 kbps is a '
            .'taste judgement and the Songs listing already filters on it, while a 22 kHz file is a defect.';
    }

    /** @param Builder<Track> $tracks */
    protected function constrain(Builder $tracks): Builder
    {
        return $tracks->where('tracks.sample_rate', '<', 44_100);
    }
}
