<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;

/** Files with no track number, which is a third fault the "missing a track" check cannot express. */
final class NoTrackNumberCheck extends TrackCheck
{
    /** Hygiene: the tag is what is missing, even though a whole check goes blind without it. */
    public function group(): AuditGroup
    {
        return AuditGroup::Hygiene;
    }

    /** Files rather than songs: a chapter with no number is the same fault. */
    public function title(): string
    {
        return 'Files with no track number';
    }

    /** Why it gets its own check rather than being folded into the incomplete-album one. */
    public function blurb(): string
    {
        return 'An album\'s completeness is judged by comparing its highest track number against its file count, '
            .'and a rip with no numbers at all cannot be judged that way — it falls out of that check silently '
            .'rather than failing it. It also plays in whatever order the names sort in. Add TRACK (TRCK) tags.';
    }

    /** @param Builder<Track> $tracks */
    protected function constrain(Builder $tracks): Builder
    {
        return $tracks->whereNull('tracks.track');
    }
}
