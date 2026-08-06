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
     * of titles. `year_sort` folds a missing year to 0 so undated material lands last under the
     * descending sort rather than first — the same trap (and fix) the artist page's own songs
     * table documents, and the reason this cannot just `orderByDesc('collections.year')`.
     *
     * @param  Builder  $tracks  a query over the `tracks` table, already narrowed to the subject
     * @return list<array<string, mixed>> queue entries in the shape `QueueTrack` expects
     */
    public static function fromQuery(Builder $tracks): array
    {
        return $tracks
            ->where('tracks.type', TrackType::Music->value)
            ->leftJoin('artists', 'tracks.artist_id', '=', 'artists.id')
            ->leftJoin('collections', 'tracks.collection_id', '=', 'collections.id')
            ->select([
                'tracks.id',
                'tracks.name',
                'tracks.duration',
                'tracks.cover',
                'artists.name as artist_name',
                'collections.name as album_name',
            ])
            ->selectRaw('coalesce(collections.year, 0) as year_sort')
            ->orderByDesc('year_sort')
            ->orderBy('collections.name')
            ->orderBy('tracks.disc')
            ->orderBy('tracks.track')
            ->orderBy('tracks.name')
            ->get()
            ->map(fn (object $track): array => [
                'id' => $track->id,
                'name' => $track->name,
                'artist' => $track->artist_name,
                'album' => $track->album_name,
                // Raw seconds; the panel formats them (the project's server-sends-raw rule).
                'duration' => $track->duration === null ? null : (float) $track->duration,
                'coverUrl' => $track->cover
                    ? route('music.songs.cover', $track->id, absolute: false)
                    : null,
                'href' => route('music.songs.show', $track->id, absolute: false),
                'streamUrl' => route('music.songs.stream', $track->id, absolute: false),
            ])
            ->all();
    }

    /** A query over `tracks`, for a caller that only wants to add its own `where`. */
    public static function query(): Builder
    {
        return DB::table('tracks');
    }
}
