<?php

namespace App\Http\Controllers\Playlists;

use App\Http\Controllers\Controller;
use App\Http\Requests\Playlists\ShowPlaylistRequest;
use App\Models\Playlist;
use App\Services\Music\FannedCovers;
use App\Services\Music\QueuePayload;
use App\Services\Player\PlayCounts;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One playlist's detail page (`GET /playlists/{playlist}`, route `playlists.show`, behind
 * auth) — where a row of the listing leads. Singular name beside the plural
 * PlaylistsController, the same pairing the four Music areas use.
 *
 * Single action, so it is invokable. It validates and authorizes nothing itself:
 * ShowPlaylistRequest carries the ownership rule, and answers 404 rather than 403 so that
 * a wrong guess at a UUID cannot confirm the playlist exists on a box that is deliberately
 * reachable from the internet.
 *
 * THE WHOLE PLAYLIST GOES OVER WITH THE PAGE, which is the one decision here worth
 * defending, because the four Music detail pages do the opposite: they declare their tracks
 * as `Inertia::optional` and fetch them only when somebody presses play. Their songs tables
 * are PAGINATED, so the rows on screen are never the subject and "play this artist" can mean
 * a thousand tracks nobody asked to download. A playlist is neither: it is a hand-made list,
 * every entry of it is on screen, and each row offers its own play and enqueue button — so
 * the queue payload IS the page's content rather than an extra. Sending it once means the
 * hero menu and every row act with no round trip at all.
 *
 * It follows that the rows are shaped as queue entries plus the two things a row shows and
 * a queue entry has no use for: the album's year, and the entry's own id. It also follows
 * that the hero's four numbers are counted over those entries rather than aggregated in SQL
 * the way the listing does it — see {@see facts}.
 *
 * NO TYPE FILTER, unlike every Music page: a playlist may deliberately mix music with
 * audiobook chapters (that is what the unified `tracks` table is for), so restricting to
 * music here would silently drop entries the reader put in themselves.
 */
class PlaylistController extends Controller
{
    /**
     * Render one playlist. `{playlist}` resolves through implicit binding on the UUID, so
     * an unknown id is a 404 before this runs — and a stranger's is one after
     * ShowPlaylistRequest has looked at it.
     */
    public function __invoke(ShowPlaylistRequest $request, Playlist $playlist): Response
    {
        $entries = $this->entries($playlist);

        return Inertia::render('Playlists/Playlist/PlaylistPage', [
            'playlist' => [
                'id' => $playlist->id,
                'name' => $playlist->name,
                // Null when the owner left it empty (the form stores "" as null), which the
                // hero renders as no paragraph at all rather than an empty one.
                'description' => $playlist->description,
            ] + $this->facts($playlist, $entries),
            'tracks' => $entries->pluck('track')->all(),
            'covers' => $this->fannedCovers($entries),
            // How much of this playlist has been listened to — the reader's own listens and
            // everybody else's, as listening EVENTS (App\Services\Player\PlayCounts). Its own
            // prop rather than a member of `playlist`, so the player can refresh just this
            // figure when a track finishes without dragging the whole hero back with it — the
            // same split the four Music detail pages make.
            'plays' => PlayCounts::forPlaylist($playlist, $request->user()),
            // The default the export modal's prefix field opens with. Config rather than a
            // literal in the page, because it describes the machine that will PLAY the file
            // rather than anything about this playlist (config/mixtape.php says more).
            'exportPrefix' => (string) config('mixtape.playlists.export.path_prefix'),
        ]);
    }

    /**
     * The four numbers under the hero's title — the same four the listing's row carries, so
     * a playlist reads identically in both places.
     *
     * COUNTED AND SUMMED IN PHP over the entries already fetched, where the listing does
     * both in SQL (`withCount` / `withSum`). Not an inconsistency but the consequence of the
     * one difference between the pages: this one has already loaded every entry, so a second
     * query would ask the database to recount rows that are in hand — and, worse, could
     * disagree with the list rendered directly below it. The listing loads no tracks at all,
     * so for it the aggregate IS the cheap answer.
     *
     * `duration` is null rather than 0 for a playlist that plays for no time, matching what
     * SQL's SUM sends from the listing: null over no rows, and null again when not one entry
     * carries a duration. The page hangs a tile on that distinction — "0 Sekunden" beside a
     * track count of 0 says nothing twice.
     *
     * @param  SupportCollection<int, array{track: array<string, mixed>, albumKey: string}>  $entries
     * @return array{tracks: int, duration: float|null, createdAt: string|null, updatedAt: string|null}
     */
    private function facts(Playlist $playlist, SupportCollection $entries): array
    {
        $durations = $entries->pluck('track.duration')->filter(fn (?float $seconds): bool => $seconds !== null);

        return [
            'tracks' => $entries->count(),
            'duration' => $durations->isEmpty() ? null : (float) $durations->sum(),
            // Raw ISO-8601, formatted on the page against the viewer's locale and timezone —
            // the server knows neither (Utils/formatting.ts).
            'createdAt' => $playlist->created_at?->toIso8601String(),
            // NULL for a playlist nothing has happened to since, so the page asks one question
            // ("is there an updatedAt?") instead of comparing two timestamps. Both columns are
            // written from one instant on insert, so they are exactly equal until something
            // moves one of them — PlaylistTrack::$touches is what makes adding, reordering or
            // removing a track count as a change. The identical rule to PlaylistsController's,
            // and it has to be: the same playlist must not claim to be untouched on one page
            // and edited on the other.
            'updatedAt' => $playlist->updated_at === null || $playlist->updated_at->equalTo($playlist->created_at)
                ? null
                : $playlist->updated_at->toIso8601String(),
        ];
    }

    /**
     * The playlist's entries in the reader's own order, each as a queue entry plus what the
     * row needs on top of one.
     *
     * `QueuePayload::selectFrom` rather than `fromQuery`, and that is the whole reason that
     * split exists: `fromQuery` imposes an album-then-disc-then-track order, which is right
     * for "play this artist" and wrong for a list whose order is the point of it. So the
     * shape comes from the service and the ordering stays here.
     *
     * `entry_id` is the pivot row's own id, and it is not decoration: the same track may sit
     * in a playlist twice, so `track.id` is not unique down the list and cannot key it.
     * Ordering falls back to it as well, since `position` is deliberately non-unique (the
     * migration says why) and two entries sharing one would otherwise be free to swap places
     * between two loads of the same page.
     *
     * The `albumKey` each row is paired with never reaches the client — it exists only so
     * the cover fan below can tell two songs off one record from two records.
     *
     * @return SupportCollection<int, array{track: array<string, mixed>, albumKey: string}>
     */
    private function entries(Playlist $playlist): SupportCollection
    {
        return QueuePayload::selectFrom(
            DB::table('playlist_tracks')
                ->join('tracks', 'tracks.id', '=', 'playlist_tracks.track_id')
                ->where('playlist_tracks.playlist_id', $playlist->id),
            only: null,
        )
            ->addSelect([
                'playlist_tracks.id as entry_id',
                'tracks.collection_id',
                'collections.year as album_year',
                'tracks.path',
            ])
            ->orderBy('playlist_tracks.position')
            ->orderBy('playlist_tracks.id')
            ->get()
            ->map(fn (object $row): array => [
                'track' => QueuePayload::entry($row) + [
                    'entryId' => $row->entry_id,
                    // Raw, and null for a track filed under no album or an untagged rip —
                    // the row drops the chip rather than printing a zero.
                    'year' => $row->album_year === null ? null : (int) $row->album_year,
                    // THE FILE'S OWN PATH, area-relative, and the only reason it goes to the
                    // client: "sort by path" is then something the page can do to the list in
                    // front of it, in the frame the click happened in, instead of asking the
                    // server what the new order is and waiting to be told. See
                    // usePlaylistSort — the round trip still happens, it just stops being
                    // something the reader waits through.
                    //
                    // It reveals nothing the reader cannot already have: the export writes
                    // these very paths into a file they download, which is the point of it.
                    'path' => $row->path,
                ],
                'albumKey' => $row->collection_id ?? $row->id,
            ]);
    }

    /**
     * Up to three of the playlist's covers, for the hero's fanned sleeves.
     *
     * THE KEY IS THE ALBUM, and this page is why FannedCovers takes one at all: a cover URL
     * here is per TRACK, so ten songs off one record are ten different URLs pointing at the
     * same picture, and three identical sleeves read as a rendering fault rather than as a
     * stack of records. Keying by album collapses them before the pick; a track filed under
     * no album keys on its own id, so a loose file still counts as a record of its own
     * (`albumKey` carries that fallback — see {@see entries}).
     *
     * Everything else — three, at random, artless dropped — belongs to the service.
     *
     * @param  SupportCollection<int, array{track: array<string, mixed>, albumKey: string}>  $entries
     * @return array<int, string>
     */
    private function fannedCovers(SupportCollection $entries): array
    {
        return FannedCovers::pick(
            $entries->map(fn (array $entry): array => [$entry['albumKey'], $entry['track']['coverUrl']])
        );
    }
}
