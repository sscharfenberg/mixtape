<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Enums\TrackType;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;

/** Songs with no artist — unreachable from the Artists section, and unfindable by performer. */
final class NoArtistCheck extends TrackCheck
{
    /** Hygiene: one tag missing, with everything else about the file in order. */
    public function group(): AuditGroup
    {
        return AuditGroup::Hygiene;
    }

    /** Music only: a chapter's people are its author and narrator, checked separately. */
    public function areas(): array
    {
        return [TrackType::Music];
    }

    /** SONGS, since an audiobook chapter legitimately has no artist. */
    public function title(): string
    {
        return 'Songs with no artist';
    }

    /** Why an unattributed song is worse off than an untitled one. */
    public function blurb(): string
    {
        return 'Search matches a row on its OWN name, so a song with no artist can only be found by its title — '
            .'and it appears under no artist anywhere in the app. Add an ARTIST (TPE1) tag; the album artist is a '
            .'separate tag and does not stand in for it.';
    }

    /** @param Builder<Track> $tracks */
    protected function constrain(Builder $tracks): Builder
    {
        return $tracks->whereNull('tracks.artist_id');
    }
}
