<?php

namespace App\Enums;

/**
 * What a share link is ABOUT — the kinds of subject a detail page can hand out to
 * someone without an account (docs/sharing.md).
 *
 * A DIFFERENT LIST FROM {@see PlaylistSubject} — shorter in one direction and longer
 * in the other, and every difference is a decision:
 *
 *   - No `genre`. The mapping would be free, but "listen to this genre" is a
 *     different kind of act from "listen to this", and the owner ruled it out
 *     (2026-08-11). The genre page therefore offers play and enqueue, but no share.
 *   - `playlist`, which that enum deliberately lacks (2026-08-13, the owner). It is
 *     the one subject here whose tracks are NOT named by a column on `tracks` — a
 *     playlist's order is its content — so it is also the one whose grant
 *     {@see self::grant()} cannot answer; ShareGrant resolves it through the pivot
 *     instead. It is likewise the only subject with an OWNER: a playlist belongs to
 *     one user where the library belongs to everybody, so the mint request checks
 *     that the reader is sharing their own (StoreShareRequest).
 *   - No `audiobook` yet. Blocked on there being an audiobook page to share FROM;
 *     it costs one case and no migration when that arrives, since `collections`
 *     already discriminates album from audiobook.
 *
 * THE GRANT IS NOT DEFINED HERE for the three library subjects. A share must play
 * exactly the tracks the app already considers to BE its subject, so
 * {@see self::grant()} delegates to `PlaylistSubject`, whose `column()` is the same
 * narrowing each detail controller applies to build its `queueTracks` prop. Restating
 * those columns here would be one edit away from "share this artist" and "play this
 * artist" meaning different songs — and the artist case is where that bites, because
 * `tracks.artist_id` is NOT `collections.album_artist_id` (docs/sharing.md → "the
 * artist trap").
 */
enum ShareSubject: string
{
    case Song = 'song';
    case Album = 'album';
    case Artist = 'artist';
    case Playlist = 'playlist';
    /**
     * A whole audiobook. Added 2026-08-13, when the area got a page to share FROM — which is
     * all it was ever waiting on. It cost one case and three arms and NO migration: `shares`
     * already stores it in `collection_id`, and the table's CHECK counts non-null FKs without
     * caring which kind of collection this one is.
     */
    case Audiobook = 'audiobook';

    /**
     * The `shares` column this subject's id is stored in — which of the four FKs the
     * table's CHECK will find set.
     */
    public function foreignKey(): string
    {
        return match ($this) {
            self::Song => 'track_id',
            self::Album, self::Audiobook => 'collection_id',
            self::Artist => 'artist_id',
            self::Playlist => 'playlist_id',
        };
    }

    /**
     * The same subject as the playlist enum knows it, so the tracks a share grants are
     * resolved by the one mapping this app has for the question. See the class note:
     * the delegation is the point, not an indirection to be inlined later.
     *
     * NULL FOR A PLAYLIST, and that is the same statement `PlaylistSubject` makes by
     * having no case for one: there is no id that names a playlist's tracks, because
     * its ORDER is part of what it holds and no column on `tracks` carries that. The
     * caller that needs those tracks is ShareGrant, which branches on this being null
     * and joins `playlist_tracks` — a nullable return rather than a fourth
     * `PlaylistSubject` case, so the two enums cannot come to disagree about whether a
     * playlist is nameable by one column.
     */
    public function grant(): ?PlaylistSubject
    {
        return match ($this) {
            self::Song => PlaylistSubject::Song,
            // An audiobook grants its collection's tracks, exactly as an album does — the
            // column is the same one, and `collections` is what tells the two apart.
            self::Album, self::Audiobook => PlaylistSubject::Album,
            self::Artist => PlaylistSubject::Artist,
            self::Playlist => null,
        };
    }

    /**
     * The table the subject id must be found in, for the mint request's `exists` rule.
     *
     * `collections` holds audiobooks as well as albums, which is why the rule that uses
     * this narrows on `type` too (StoreShareRequest) — otherwise an audiobook's id
     * passed as `subject: "album"` would mint a share the album page could never have
     * offered. `playlists` is narrowed by OWNER there, for the same class of reason.
     */
    public function table(): string
    {
        return match ($this) {
            self::Song => 'tracks',
            self::Album, self::Audiobook => 'collections',
            self::Artist => 'artists',
            self::Playlist => 'playlists',
        };
    }
}
