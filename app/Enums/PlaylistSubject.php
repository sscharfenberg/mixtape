<?php

namespace App\Enums;

/**
 * What a detail page's "add to playlist" is ABOUT — the four things this app has a hero for
 * whose tracks can be named by one id.
 *
 * It exists so that the browser never has to send a list of tracks in order to add an artist
 * to a playlist. The client says "artist X"; the column below is what turns that into a query
 * over `tracks`, and it is deliberately the SAME narrowing each of those four controllers
 * already applies to build its optional `queueTracks` prop — so "add this artist" and "play
 * this artist" can never come to mean different sets of songs.
 *
 * A `playlist` case is absent on purpose: the queue and a playlist are lists whose ORDER is
 * their content, so there is no id that names their tracks — those go over as ids, in the
 * order the reader arranged (see AddTracksToPlaylistRequest, which takes either shape).
 */
enum PlaylistSubject: string
{
    case Song = 'song';
    case Album = 'album';
    case Artist = 'artist';
    case Genre = 'genre';

    /**
     * The `tracks` column whose value is this subject's id.
     *
     * Qualified with the table name because every caller joins: an unqualified `id` is
     * ambiguous the moment `playlist_tracks` is in the same query, and that ambiguity is an
     * error on Postgres rather than a lucky guess.
     */
    public function column(): string
    {
        return match ($this) {
            self::Song => 'tracks.id',
            self::Album => 'tracks.collection_id',
            self::Artist => 'tracks.artist_id',
            self::Genre => 'tracks.genre_id',
        };
    }
}
