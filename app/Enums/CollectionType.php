<?php

namespace App\Enums;

/**
 * The *container kind* of a collection — `collections` is the merged
 * albums+audiobooks table (data-model.md → (a), "collections half-step"), so a
 * single table holds music albums, audiobooks, and (future) podcast shows.
 *
 * Parallel to TrackType (the *playable* kind): album↔music, audiobook↔audiobook,
 * podcast_show↔podcast. Adding a new container kind here is what keeps a new
 * media type cheap — a new enum value, not a new nullable FK column on every row.
 */
enum CollectionType: string
{
    case Album = 'album';
    case Audiobook = 'audiobook';
    case PodcastShow = 'podcast_show';
}
