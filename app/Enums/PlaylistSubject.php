<?php

namespace App\Enums;

use App\Services\Music\ArtistCredits;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * What a detail page's "add to playlist" is ABOUT — the four things this app has a hero for
 * whose tracks can be named by one id.
 *
 * It exists so that the browser never has to send a list of tracks in order to add an artist
 * to a playlist. The client says "artist X"; {@see apply} is what turns that into a query
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
     * Narrow a query over `tracks` to the subject's own tracks.
     *
     * A METHOD RATHER THAN THE COLUMN NAME IT USED TO HAND BACK, because one of the four is
     * not a column. Three of them are a foreign key on `tracks` and read as one; an ARTIST is
     * credited two ways — as the performer of a track and as the artist of the record it sits
     * on — and a collaboration puts a different string in the first. That set is
     * App\Services\Music\ArtistCredits', which is where the rule and its measurements live;
     * this enum only says WHICH subject asks for it.
     *
     * Every column is qualified with the table name because every caller joins: an unqualified
     * `id` is ambiguous the moment `playlist_tracks` is in the same query, and that ambiguity
     * is an error on Postgres rather than a lucky guess.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $tracks  a query whose base table is `tracks`
     * @param  list<string>  $ids  subjects of this kind
     * @return TBuilder the same query, narrowed
     */
    public function apply(Builder $tracks, array $ids): Builder
    {
        return match ($this) {
            self::Song => $tracks->whereIn('tracks.id', $ids),
            self::Album => $tracks->whereIn('tracks.collection_id', $ids),
            self::Genre => $tracks->whereIn('tracks.genre_id', $ids),
            self::Artist => ArtistCredits::of($tracks, $ids),
        };
    }
}
