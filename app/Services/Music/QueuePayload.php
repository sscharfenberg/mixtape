<?php

declare(strict_types=1);

namespace App\Services\Music;

use App\Enums\TrackType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the payload the client play queue holds, for a whole subject at once.
 *
 * WHY A SERVICE AND NOT FOUR CONTROLLER METHODS: the artist, genre, album and song pages all
 * offer "play this" and "enqueue this", and a queue entry the panel can draw with no further
 * round trip has eight fields (usePlayerQueue's `QueueTrack`). Four copies of that mapping is
 * four chances for one page to hand the player a track shaped slightly differently from the
 * others — and the player would take it, then fail on the field that was missing.
 *
 * WHY IT IS FETCHED ON DEMAND: every one of those pages paginates its songs table, so the
 * rows on screen are never the whole subject — "play artist" means all 93, not the 25 in view.
 * Shipping them with the page would put a few hundred kilobytes on a visit that might only be
 * browsing, so the controllers declare this as an OPTIONAL Inertia prop and the menu asks for
 * it with a partial reload when a listener actually presses something. That is also why there
 * is no endpoint: this app has no REST API by design, and `Inertia::optional` is the
 * Inertia-native way to fetch more of a page.
 *
 * The cover flag is the `tracks.cover` column the scan wrote, never a filesystem check: a
 * genre can run to thousands of rows, and one stat per row would be thousands of them.
 */
final class QueuePayload
{
    /**
     * Map a scoped query over `tracks` into queue entries, in playing order.
     *
     * The caller narrows (one artist, one genre, one album, one song); the joins, the ordering
     * and the shape belong here. Music only — an audiobook chapter is not something the player
     * queue offers to shuffle through.
     *
     * ORDER IS ALBUM-THEN-DISC-THEN-TRACK, deliberately, whatever the subject: a listener who
     * presses "play artist" expects records to arrive as records, not as an alphabetical list
     * of titles. It lives in {@see inPlayingOrder} rather than here, because adding a subject
     * to a playlist needs the same sequence without the payload.
     *
     * THE TYPE FILTER IS THE CALLER'S, with music as the default because every caller here
     * is a music page. It became a parameter when the play queue learned to restore itself
     * from the server: that path maps ids the listener actually queued, and a filter baked
     * in here would silently drop an audiobook chapter from a restored queue — the row would
     * simply be missing, with nothing to explain it. A default keeps the four subject pages
     * honest (an artist's audiobook narration is not part of "play this artist") while
     * letting a caller that already knows what it has pass `null`.
     *
     * @param  Builder  $tracks  a query over the `tracks` table, already narrowed to the subject
     * @param  TrackType|null  $only  restrict to one kind of track, or null for any
     * @return list<array<string, mixed>> queue entries in the shape `QueueTrack` expects
     */
    public static function fromQuery(Builder $tracks, ?TrackType $only = TrackType::Music): array
    {
        return self::inPlayingOrder(self::selectFrom($tracks, $only))
            ->get()
            ->map(fn (object $track): array => self::entry($track))
            ->all();
    }

    /**
     * Put a query over `tracks` in PLAYING order — album, then disc, then track.
     *
     * Split out of {@see fromQuery} for the caller that wants the order without the payload:
     * App\Services\Playlists\PlaylistAdditions resolves a subject to bare ids, and a playlist
     * built from "add this artist" has to hold them in the sequence "play this artist" would
     * have played them. One definition, so the two cannot drift.
     *
     * The query must already join `collections` — {@see selectFrom} does, and a caller using
     * this on its own has to.
     *
     * `year_sort` folds a missing year to 0 so undated material lands last under the descending
     * sort rather than first (Postgres puts NULLs first under DESC). Both engines resolve a
     * SELECT alias in ORDER BY, which is why this can be sorted on rather than repeated.
     */
    public static function inPlayingOrder(Builder $tracks): Builder
    {
        return $tracks
            ->selectRaw('coalesce(collections.year, 0) as year_sort')
            ->orderByDesc('year_sort')
            ->orderBy('collections.name')
            ->orderBy('tracks.disc')
            ->orderBy('tracks.track')
            ->orderBy('tracks.name');
    }

    /**
     * The joins and columns a queue entry is built from, applied to a query over `tracks`
     * — everything {@see fromQuery} does EXCEPT choosing the order and running it.
     *
     * Public for the caller that cannot use `fromQuery`, and there is exactly one kind:
     * something whose order is its own data. A saved playlist is the reader's running
     * order (`playlist_tracks.position`), so the album-then-disc-then-track sort above
     * would silently rewrite it — while the eight fields the player needs must still be
     * the same eight, shaped the same way. Hence the split: order here, shape in
     * {@see entry}.
     *
     * It calls `select()`, not `addSelect()`, so anything the caller selected before this
     * is dropped; add extra columns AFTER calling it. Those extra columns are also why
     * this returns the builder rather than the rows — the playlist page reads the album
     * year and the entry's own id off the same query.
     *
     * @param  Builder  $tracks  a query over the `tracks` table, already narrowed to the subject
     * @param  TrackType|null  $only  restrict to one kind of track, or null for any
     */
    public static function selectFrom(Builder $tracks, ?TrackType $only = TrackType::Music): Builder
    {
        return $tracks
            ->when($only !== null, fn (Builder $query) => $query->where('tracks.type', $only->value))
            ->leftJoin('artists', 'tracks.artist_id', '=', 'artists.id')
            ->leftJoin('collections', 'tracks.collection_id', '=', 'collections.id')
            ->select([
                'tracks.id',
                'tracks.name',
                // The track's own kind, because {@see entry} routes its three URLs by it —
                // a chapter's bytes are not at /music/songs/…. `collection_id` rides along
                // for the same reason: a chapter's `href` is its BOOK's page.
                'tracks.type',
                'tracks.collection_id',
                'tracks.duration',
                'tracks.cover',
                'artists.name as artist_name',
                'collections.name as album_name',
            ]);
    }

    /**
     * One selected row as a queue entry — THE definition of the shape, and the reason this
     * class exists at all (see the class docblock: four pages hand the player tracks, and a
     * fifth one shaped slightly differently would be taken and then fail on the field that
     * was missing).
     *
     * Public alongside {@see selectFrom} so a caller that adds columns of its own can still
     * map through this rather than repeating the eight fields; the extra columns it selected
     * are simply ignored here and merged on by the caller.
     *
     * @param  object  $track  a row from a query {@see selectFrom} shaped
     * @return array<string, mixed> one entry in the shape `QueueTrack` expects
     */
    public static function entry(object $track): array
    {
        $audiobook = TrackType::tryFrom((string) $track->type) === TrackType::Audiobook;

        return [
            'id' => $track->id,
            'name' => $track->name,
            'artist' => $track->artist_name,
            'album' => $track->album_name,
            // Raw seconds; the panel formats them (the project's server-sends-raw rule).
            'duration' => $track->duration === null ? null : (float) $track->duration,
            'coverUrl' => $track->cover
                ? route($audiobook ? 'audiobooks.chapters.cover' : 'music.songs.cover', $track->id, absolute: false)
                : null,
            // A chapter has no page of its own, so it points at its BOOK — where a reader
            // clicking a queue row wants to end up anyway, and the only place the chapter is
            // listed. A chapter whose file carried no album tag has no book to point at and
            // falls back to the area, because `QueueTrack.href` is a non-nullable string the
            // client derives and compresses for storage.
            'href' => $audiobook
                ? ($track->collection_id === null
                    ? route('audiobooks', absolute: false)
                    : route('audiobooks.show', $track->collection_id, absolute: false))
                : route('music.songs.show', $track->id, absolute: false),
            'streamUrl' => route($audiobook ? 'audiobooks.chapters.stream' : 'music.songs.stream', $track->id, absolute: false),
            // Present only on a chapter, because its absence is what says "song" — the same
            // rule the client's stored form follows, and the reason it is a flag rather than
            // the `type` string: the player asks one question of it (may this row offer to
            // stop at the end of the chapter?), never which of several kinds it is.
            ...($audiobook ? ['isChapter' => true] : []),
        ];
    }

    /** A query over `tracks`, for a caller that only wants to add its own `where`. */
    public static function query(): Builder
    {
        return DB::table('tracks');
    }
}
