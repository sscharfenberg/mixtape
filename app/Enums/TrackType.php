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

    /**
     * The container kind this playable kind lives in: music↔album,
     * audiobook↔audiobook, podcast↔podcast_show. The scanner keeps a track's
     * `type` in step with its collection's `type` through this mapping.
     */
    public function collectionType(): CollectionType
    {
        return match ($this) {
            self::Music => CollectionType::Album,
            self::Audiobook => CollectionType::Audiobook,
            self::Podcast => CollectionType::PodcastShow,
        };
    }

    /**
     * The `config('mixtape.library.paths.*')` key for this area — also the name
     * accepted by `app:update --area=…`. Note it is NOT always the enum value:
     * `audiobook` → `audiobooks`, `podcast` → `podcast_shows`.
     */
    public function libraryPathKey(): string
    {
        return match ($this) {
            self::Music => 'music',
            self::Audiobook => 'audiobooks',
            self::Podcast => 'podcast_shows',
        };
    }
}
