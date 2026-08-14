<?php

namespace App\Enums;

/**
 * The *playable kind* of a track — the unified `tracks` table holds every kind
 * of playable in one row (data-model.md → "One tracks table, one collections table").
 *
 * This is parallel to, but NOT identical with, CollectionType (the *container*
 * kind): music↔album, audiobook↔audiobook. It is stored on
 * `tracks` (rather than derived through the collection join) because the
 * type-guard CHECK constraint needs the value locally — a Postgres CHECK can't
 * reference another table.
 */
enum TrackType: string
{
    case Music = 'music';
    case Audiobook = 'audiobook';

    /**
     * The container kind this playable kind lives in: music↔album,
     * audiobook↔audiobook. The scanner keeps a track's
     * `type` in step with its collection's `type` through this mapping.
     */
    public function collectionType(): CollectionType
    {
        return match ($this) {
            self::Music => CollectionType::Album,
            self::Audiobook => CollectionType::Audiobook,
        };
    }

    /**
     * The `config('mixtape.library.paths.*')` key for this area — also the name
     * accepted by `app:update --area=…`. Note it is NOT always the enum value:
     * `audiobook` → `audiobooks`.
     */
    public function libraryPathKey(): string
    {
        return match ($this) {
            self::Music => 'music',
            self::Audiobook => 'audiobooks',
        };
    }
}
