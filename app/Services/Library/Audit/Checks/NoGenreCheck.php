<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Enums\TrackType;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;

/** Songs with no genre, which is how a track falls out of the genre section entirely. */
final class NoGenreCheck extends TrackCheck
{
    /** Hygiene: one tag missing, and the file is otherwise where it should be. */
    public function group(): AuditGroup
    {
        return AuditGroup::Hygiene;
    }

    /** Music only: an audiobook chapter has no genre to miss. */
    public function areas(): array
    {
        return [TrackType::Music];
    }

    /** SONGS, because a chapter has no genre by construction and would be a false finding. */
    public function title(): string
    {
        return 'Songs with no genre';
    }

    /** Why this one is invisible rather than merely untidy. */
    public function blurb(): string
    {
        return 'A song with no genre is reachable from search and from its album, and from nowhere else — the '
            .'Genres section cannot list it, and an artist\'s dominant genre is calculated without it. Nothing on '
            .'screen says a track is missing, which is what makes this worth a report.';
    }

    /** @param Builder<Track> $tracks */
    protected function constrain(Builder $tracks): Builder
    {
        return $tracks->whereNull('tracks.genre_id');
    }
}
