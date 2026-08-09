<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\Track;
use App\Models\User;

/**
 * Everything the Now Playing page shows that the play queue does not carry.
 *
 * WHY ANY OF THIS IS A ROUND TRIP. `QueueTrack` holds the title, artist name, album name, cover
 * and duration — and stops there deliberately: trimming a stored track from ~374 characters to
 * ~164 is what moved the tightest browser's storage ceiling from roughly 7,000 queued tracks to
 * roughly 16,000 (docs/play-queue.md → Storage). The page needs six more things, and every one of
 * them would be paid for twelve thousand times over to label three cards:
 *
 *   - the GENRE, which the queue has no field for at all;
 *   - the three URLs that make artist, album and genre LINKS — the queue holds those names as
 *     plain strings, and the server is what owns which pages exist;
 *   - the YEAR;
 *   - the PLAY COUNTS, which are not a property of the track at all but of the listening.
 *
 * So the page asks for exactly the tracks it is drawing — the loaded one and its two neighbours —
 * and re-asks when they change.
 *
 * A NULL IS AN ANSWER throughout: plenty of rips carry no genre frame and plenty of files are
 * filed under no album, and a card simply drops the line. An id with no row at all — a queue
 * restored from localStorage naming a file the scanner has since removed — is absent from the
 * map, which the page reads the same way. Neither is worth distinguishing on a card that has one
 * line to give it.
 */
final class NowPlayingFacts
{
    /**
     * How many ids one request may name.
     *
     * Three, because three tracks are drawn. It is a cap on the page's own contract rather than a
     * performance bound, and it exists so the endpoint cannot be borrowed as a bulk dump of the
     * library — which, with the play counts below, would be a genuinely expensive one.
     */
    public const MAX_TRACKS = 3;

    /**
     * The facts, keyed by track id, for the ids that exist.
     *
     * ONE QUERY FOR THE TRACKS, plus two counts per track for the plays. The page asks for all
     * three ids at once precisely so the first part is a single round trip; the plays cannot join
     * that, because "mine" and "everybody else's" are counted over the `plays` table against a
     * content hash rather than a track id (PlayCounts explains why a re-rip keeps its history).
     * Three tracks is a fixed, small bound on that, which is what {@see MAX_TRACKS} is protecting.
     *
     * @param  list<string>  $trackIds
     * @return array<string, array<string, mixed>>
     */
    public static function forTracks(array $trackIds, ?User $user): array
    {
        if ($trackIds === []) {
            return [];
        }

        return Track::query()
            ->whereIn('id', $trackIds)
            ->with(['genre:id,name', 'collection:id,year'])
            ->get(['id', 'content_hash', 'genre_id', 'artist_id', 'collection_id'])
            ->mapWithKeys(fn (Track $track): array => [
                $track->id => [
                    'genre' => $track->genre?->name,
                    // Where each name LEADS — the same shape a DataTable row's `href` takes, and
                    // here for the same reason: the server owns which links exist, so the page
                    // renders a link when it is handed one and plain text when it is not. Null
                    // for a file crediting nobody, filed under no album, or carrying no genre.
                    'artistUrl' => $track->artist_id === null
                        ? null
                        : route('music.artists.show', $track->artist_id, absolute: false),
                    'albumUrl' => $track->collection_id === null
                        ? null
                        : route('music.albums.show', $track->collection_id, absolute: false),
                    'genreUrl' => $track->genre_id === null
                        ? null
                        : route('music.genres.show', $track->genre_id, absolute: false),
                    // Off the album rather than the track — a year is a property of the release,
                    // which is why an untagged rip filed under no album has none.
                    'year' => $track->collection?->year,
                    // Raw counts, as everywhere else: a zero is something the page decides to
                    // leave unsaid rather than a "0" the server insists on.
                    'plays' => PlayCounts::forTrack($track, $user),
                ],
            ])
            ->all();
    }
}
