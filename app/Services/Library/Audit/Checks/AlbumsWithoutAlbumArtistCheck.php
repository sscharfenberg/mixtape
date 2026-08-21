<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Enums\TrackType;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Builder;

/** Albums with no album artist, which is how a compilation loses its own identity. */
final class AlbumsWithoutAlbumArtistCheck extends CollectionCheck
{
    /** Hygiene: one tag is absent, and nothing about the files or folders is wrong. */
    public function group(): AuditGroup
    {
        return AuditGroup::Hygiene;
    }

    /** Music only — an audiobook has no owner column at all; its authors come from its chapters. */
    public function areas(): array
    {
        return [TrackType::Music];
    }

    /** The album is the subject, because the tag belongs to the record rather than to a file. */
    public function title(): string
    {
        return 'Albums with no album artist';
    }

    /** Why the per-track artist does not cover for this. */
    public function blurb(): string
    {
        return 'The ALBUM ARTIST tag is what holds a record together when its tracks credit different performers — '
            .'a soundtrack, a split, a tribute. Without it an album belongs to no artist, and the artist page whose '
            .'sleeve it is on cannot list it. It is a different tag from ARTIST and a per-track artist is not a '
            .'substitute.';
    }

    /** @param Builder<Collection> $collections */
    protected function constrain(Builder $collections): Builder
    {
        return $collections->whereNull('collections.album_artist_id');
    }
}
