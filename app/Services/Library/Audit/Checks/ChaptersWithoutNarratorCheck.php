<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Enums\TrackType;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;

/** Audiobook chapters with no narrator. */
final class ChaptersWithoutNarratorCheck extends TrackCheck
{
    /** Structure, beside the author check, because both describe an incomplete recording. */
    public function group(): AuditGroup
    {
        return AuditGroup::Structure;
    }

    /** Audiobooks only — narrator is not a music tag. */
    public function areas(): array
    {
        return [TrackType::Audiobook];
    }

    /** Chapters again: the narrator is read per file, exactly as the author is. */
    public function title(): string
    {
        return 'Chapters with no narrator';
    }

    /** Why it sits beside the author check rather than in tag hygiene. */
    public function blurb(): string
    {
        return 'The narrator is half of what makes one recording of a book different from another, and it is read '
            .'per chapter for the same reason the author is. A book with some chapters narrated and some not reads '
            .'as an incomplete recording rather than an untagged one.';
    }

    /** @param Builder<Track> $tracks */
    protected function constrain(Builder $tracks): Builder
    {
        return $tracks->whereNull('tracks.narrator_id');
    }
}
