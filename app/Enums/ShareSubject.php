<?php

namespace App\Enums;

/**
 * What a share link is ABOUT — the kinds of subject a detail page can hand out to
 * someone without an account (docs/sharing.md).
 *
 * A SHORTER LIST THAN {@see PlaylistSubject}, and each omission is a decision:
 *
 *   - No `genre`. The mapping would be free, but "listen to this genre" is a
 *     different kind of act from "listen to this", and the owner ruled it out
 *     (2026-08-11). The genre page therefore offers play and enqueue, but no share.
 *   - No `playlist` yet. `shares.playlist_id` exists in the schema, but the owner
 *     deferred it — and unlike the three below it would need an ownership check in
 *     `authorize()`, since a playlist belongs to one user where the library belongs
 *     to everybody.
 *   - No `audiobook` yet. Blocked on there being an audiobook page to share FROM;
 *     it costs one case and no migration when that arrives, since `collections`
 *     already discriminates album from audiobook.
 *
 * THE GRANT IS NOT DEFINED HERE. A share must play exactly the tracks the app
 * already considers to BE its subject, so {@see self::grant()} delegates to
 * `PlaylistSubject`, whose `column()` is the same narrowing each detail controller
 * applies to build its `queueTracks` prop. Restating those columns here would be one
 * edit away from "share this artist" and "play this artist" meaning different songs —
 * and the artist case is where that bites, because `tracks.artist_id` is NOT
 * `collections.album_artist_id` (docs/sharing.md → "the artist trap").
 */
enum ShareSubject: string
{
    case Song = 'song';
    case Album = 'album';
    case Artist = 'artist';

    /**
     * The `shares` column this subject's id is stored in — which of the four FKs the
     * table's CHECK will find set.
     */
    public function foreignKey(): string
    {
        return match ($this) {
            self::Song => 'track_id',
            self::Album => 'collection_id',
            self::Artist => 'artist_id',
        };
    }

    /**
     * The same subject as the playlist enum knows it, so the tracks a share grants are
     * resolved by the one mapping this app has for the question. See the class note:
     * the delegation is the point, not an indirection to be inlined later.
     */
    public function grant(): PlaylistSubject
    {
        return match ($this) {
            self::Song => PlaylistSubject::Song,
            self::Album => PlaylistSubject::Album,
            self::Artist => PlaylistSubject::Artist,
        };
    }

    /**
     * The table the subject id must be found in, for the mint request's `exists` rule.
     *
     * `collections` holds audiobooks as well as albums, which is why the rule that uses
     * this narrows on `type` too (StoreShareRequest) — otherwise an audiobook's id
     * passed as `subject: "album"` would mint a share the album page could never have
     * offered.
     */
    public function table(): string
    {
        return match ($this) {
            self::Song => 'tracks',
            self::Album => 'collections',
            self::Artist => 'artists',
        };
    }
}
