<?php

declare(strict_types=1);

namespace App\Services\Library\Audit\Checks;

use App\Enums\AuditGroup;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;

/** Files carrying no embedded picture, which is the only cover a SONG can have of its own. */
final class NoEmbeddedCoverCheck extends TrackCheck
{
    /** Hygiene: a tag that is absent, and often harmless — the blurb says when it is not. */
    public function group(): AuditGroup
    {
        return AuditGroup::Hygiene;
    }

    /** EMBEDDED, to keep it distinct from the album-level folder image check. */
    public function title(): string
    {
        return 'Files with no embedded cover';
    }

    /** Why this is not the same finding as an album with no folder image. */
    public function blurb(): string
    {
        return 'A SONG prefers its own embedded picture and falls back to the folder image; an ALBUM prefers the '
            .'folder image and falls back to a file\'s. So this is only a real gap where the folder has no image '
            .'either — read it beside "Albums with no folder image", and fix whichever of the two is cheaper.';
    }

    /** @param Builder<Track> $tracks */
    protected function constrain(Builder $tracks): Builder
    {
        return $tracks->where('tracks.cover', false);
    }
}
