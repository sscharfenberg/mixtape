<?php

namespace App\Enums;

/**
 * The *playable kind* of a track — the unified `tracks` table holds every kind
 * of playable in one row (data-model.md → (a), "B + the collections half-step").
 *
 * This is parallel to, but NOT identical with, CollectionType (the *container*
 * kind): music↔album, audiobook↔audiobook, podcast↔podcast_show. It is stored on
 * `tracks` (rather than derived through the collection join) because the
 * type-guard CHECK constraint needs the value locally — a Postgres CHECK can't
 * reference another table.
 */
enum TrackType: string
{
    case Music = 'music';
    case Audiobook = 'audiobook';
    case Podcast = 'podcast';
}
