<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Enums\Channel;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;

/** Mono files, which on a modern rip means something went wrong in the encode. */
final class MonoCheck extends TrackCheck
{
    /** Hygiene: a property of the encode rather than of the library's shape. */
    public function group(): AuditGroup
    {
        return AuditGroup::Hygiene;
    }

    /** Plain, because the finding is plain — the blurb carries the judgement. */
    public function title(): string
    {
        return 'Mono files';
    }

    /** Why the predicate is written positively, which is the whole trap here. */
    public function blurb(): string
    {
        return 'A stereo master encoded to one channel is a permanent loss no tagger can undo — the fix is to rip '
            .'the file again. Genuinely mono sources (a pre-1960s recording, a spoken-word transfer) are fine as '
            .'they are, so this is a short list to glance at rather than a batch to act on.';
    }

    /**
     * Mono, and mono only.
     *
     * NEVER `<> 'stereo'`: MP3 encodes most stereo material as JOINT stereo, so the loose form
     * reads 5,708 faults on a library measured to have none. The predicate names the fault.
     *
     * @param  Builder<Track>  $tracks
     */
    protected function constrain(Builder $tracks): Builder
    {
        return $tracks->where('tracks.channel', Channel::Mono);
    }
}
