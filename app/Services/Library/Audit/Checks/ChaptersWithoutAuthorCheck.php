<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Enums\TrackType;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;

/** Audiobook chapters with no author — the tag a book's identity actually rests on. */
final class ChaptersWithoutAuthorCheck extends TrackCheck
{
    /** Structure, not hygiene: a missing author is how one book scans as several. */
    public function group(): AuditGroup
    {
        return AuditGroup::Structure;
    }

    /** Audiobooks only — a song has no author column to fill. */
    public function areas(): array
    {
        return [TrackType::Audiobook];
    }

    /** Says CHAPTERS, because that is the grain the tag lives at and where the fix goes. */
    public function title(): string
    {
        return 'Chapters with no author';
    }

    /** Why this is structural rather than cosmetic, which is not obvious from the column name. */
    public function blurb(): string
    {
        return 'An audiobook\'s author lives on the CHAPTER, beside the narrator, because COMPOSER (TCOM) is a '
            .'per-file tag and an anthology legitimately uses it per story — so a book has no owner column and '
            .'dedupes on its title alone. A chapter with no author therefore contributes nothing to who wrote the '
            .'book, and a book whose chapters disagree scans as several books. Fix the tag, then re-scan.';
    }

    /** @param Builder<Track> $tracks */
    protected function constrain(Builder $tracks): Builder
    {
        return $tracks->whereNull('tracks.author_id');
    }
}
