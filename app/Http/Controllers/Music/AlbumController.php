<?php

namespace App\Http\Controllers\Music;

use App\Enums\CollectionType;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Track;
use App\Services\DataTableService;
use App\Services\Media\CoverService;
use App\Services\Music\DominantGenre;
use App\Services\Music\QueuePayload;
use App\Services\Player\PlayCounts;
use App\Services\Search\FoldedSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One album's detail page (`GET /music/albums/{album}`, route
 * `music.albums.show`, behind auth) — the row-click target of the Albums listing.
 *
 * Two blocks: the hero identifies the album (art, title, and the handful of facts that
 * describe the whole container), then its TRACK LISTING as a server-driven DataTable —
 * the thing the page is for. Still to come are the play/queue controls the hero will
 * grow once there is a player at all (docs/app-rewrite.md).
 *
 * Sends RAW values like every other controller here: seconds for the playing time,
 * an ISO-8601 instant for the file date, counts as counts. Formatting happens on the
 * page against the viewer's locale and timezone (Utils/formatting.ts).
 */
class AlbumController extends Controller
{
    /**
     * Render one album.
     *
     * `{album}` resolves through implicit binding on the collection UUID, so an
     * unknown id is a 404 before this runs; the type check is what keeps an audiobook
     * — same table — from being served as an album, exactly as
     * SongController guards the track table's other types.
     */
    public function __invoke(Request $request, Collection $album, CoverService $covers): Response
    {
        abort_unless($album->type === CollectionType::Album, 404);

        $album->load('albumArtist:id,name');

        $totals = $this->trackTotals($album);
        // Restricted to this one album at the INNERMOST level, which is safe here and is
        // not on the genre page: narrowing to a single album cannot change which genre wins
        // it, where narrowing to a single GENRE would (see GenreController). One row or none.
        $dominantGenre = DominantGenre::albumWinners($album->id)->first();

        return Inertia::render('Music/Albums/Album/AlbumPage', [
            // The whole subject as queue entries, for the hero menu's Play / Enqueue.
            // OPTIONAL: never sent with the page, only when the menu asks for it by name
            // (`router.reload({ only: ["queueTracks"] })`). The songs table here is
            // paginated, so "play this" means every track and not the 25 on screen — which
            // is a payload worth a few hundred kilobytes on a big subject and worth nothing
            // at all to a visit that is just browsing. See App\Services\Music\QueuePayload.
            'queueTracks' => Inertia::optional(
                fn (): array => QueuePayload::fromQuery(QueuePayload::query()->where('tracks.collection_id', $album->id))
            ),
            'table' => $this->trackTable($request, $album),
            // How much of this record has been listened to — the reader's own listens and
            // everybody else's, as listening EVENTS (see App\Services\Player\PlayCounts, which
            // also explains why this will not equal the sum of its songs' own figures).
            // Its own prop rather than a member of `album`, so the player can refresh just
            // this figure when a track finishes without dragging the whole hero back with it.
            'plays' => PlayCounts::forAlbum($album, $request->user()),
            'album' => [
                'id' => $album->id,
                'name' => $album->name,
                'artist' => $album->albumArtist?->name,
                // Where that name leads — the same server-decided shape SongController
                // uses for `albumUrl`, so the page links the artist when it is handed a
                // URL and prints the name plainly when it is not. Null for a compilation
                // filed under no album-artist.
                'artistUrl' => $album->album_artist_id === null
                    ? null
                    : route('music.artists.show', $album->album_artist_id, absolute: false),
                'year' => $album->year,

                // The album's MAIN genre and its page — the same rule the genre page's own
                // album tab files it under (DominantGenre), so a reader following this tile
                // arrives at a genre that really does claim this record. Null only for an
                // album whose tracks carry no genre at all.
                'genre' => $dominantGenre?->genre_name,
                'genreUrl' => $dominantGenre === null
                    ? null
                    : route('music.genres.show', $dominantGenre->genre_id, absolute: false),

                'songs' => $totals['songs'],
                // Floored to 1 for the same reason the listing floors it: a rip whose
                // files carry no disc tag counts 0 distinct discs, and it is still one
                // disc. Same COUNT(DISTINCT disc) the song page's "1/2" comes from.
                'discs' => max(1, $totals['discs']),
                'duration' => $totals['duration'],
                'modifiedAt' => $totals['modifiedAt'],

                // The hero's <img> source, or null when the album has art from
                // neither source — decided here (no extraction) so the page renders
                // its placeholder instead of pointing an <img> at a 404.
                'coverUrl' => $covers->existsForAlbum($album)
                    ? route('music.albums.cover', $album->id, absolute: false)
                    : null,
            ],
        ]);
    }

    /**
     * The album's own track listing, as a server-driven DataTable — the thing an album
     * page is actually for.
     *
     * Sorted disc-then-track by default, which is the album's RUNNING ORDER and the one
     * ordering a listener expects to find; expressing it needs DataTableService's
     * tiebreakers, since the frontend can only ask for a single sort key. `name` trails
     * both as the last tiebreak, for rips whose files carry no numbers at all — without
     * it those rows would page non-deterministically.
     *
     * The artist is a left join (so it sorts and searches as a column) because on a
     * compilation it differs per track, which is exactly when this column earns its
     * place. Everything else is the track's own row.
     *
     * @return array<string, mixed>
     */
    private function trackTable(Request $request, Collection $album): array
    {
        // An explicit query rather than `$album->tracks()`, and the reason is the search
        // callback: a HasMany is not a Builder, so FoldedSearch — which takes one — throws
        // a TypeError the moment someone types in the box. DataTableService accepts either,
        // so the failure only shows up on the search path, i.e. not until a user searches.
        $query = Track::query()
            ->where('tracks.collection_id', $album->id)
            ->leftJoin('artists', 'tracks.artist_id', '=', 'artists.id')
            ->select([
                'tracks.id',
                'tracks.name',
                'tracks.disc',
                'tracks.track',
                'tracks.duration',
                'tracks.size',
                // Not shown as a column: it is what decides whether the artwork cell gets
                // a URL or the placeholder, without touching the filesystem.
                'tracks.cover',
                // Also not a column — it is where the artist CELL links to. Off `tracks`,
                // so the existing join pays for it.
                'tracks.artist_id',
                'artists.name as artist_name',
            ])
            // The denominators behind "1/2" and "3/12": how many discs this album has, and
            // how many tracks share the row's disc. The same two definitions SongController
            // computes for its facts card, so a track's own page and this listing cannot
            // disagree about how a position is written. Correlated subqueries rather than a
            // per-row lookup, since the table is paginated and an N+1 would be a round trip
            // per row. Aliased `sib` because the outer query is over `tracks` too.
            ->addSelect([
                'disc_total' => DB::table('tracks as sib')
                    ->selectRaw('count(distinct sib.disc)')
                    ->whereColumn('sib.collection_id', 'tracks.collection_id'),
                // NULL-safe: an untagged disc has to group with the other untagged ones, and
                // `sib.disc = tracks.disc` matches nothing when both are NULL — which would
                // report 0 tracks for a whole album's worth of files. Spelled as the explicit
                // OR rather than `IS NOT DISTINCT FROM`, which SQLite does not have.
                'track_total' => DB::table('tracks as sib')
                    ->selectRaw('count(*)')
                    ->whereColumn('sib.collection_id', 'tracks.collection_id')
                    ->whereRaw('(sib.disc = tracks.disc or (sib.disc is null and tracks.disc is null))'),
            ]);

        return DataTableService::buildResponse(
            query: $query,
            request: $request,
            sortable: ['disc', 'track', 'name', 'artist', 'duration', 'size'],
            sortColumnMap: [
                'disc' => 'tracks.disc',
                'track' => 'tracks.track',
                'name' => 'tracks.name',
                'artist' => 'artists.name',
                'duration' => 'tracks.duration',
                'size' => 'tracks.size',
            ],
            defaultSort: 'disc',
            // The two text columns on show, matched through their `name_fold` companions
            // so the search is accent- and case-insensitive (FoldedSearch). Worth having
            // even here: a 154-track soundtrack is a listing, not a glance.
            searchCallback: fn (Builder $q, string $search) => FoldedSearch::apply($q, $search, [
                'tracks.name', 'artists.name',
            ]),
            rowMapper: fn (Track $track): array => [
                'id' => $track->id,
                // Position and denominator apart, so the page renders "1/2" and "3/12" — or
                // the bare number where the total is not trustworthy (formatPosition). Both
                // totals are the album's own, since every row here belongs to it.
                'disc' => $track->disc,
                'discTotal' => (int) $track->disc_total,
                'track' => $track->track,
                'trackTotal' => (int) $track->track_total,
                'name' => $track->name,
                'artist' => $track->artist_name,
                // Where the artist cell leads. A SECOND destination inside a row whose own
                // click goes to the song, which the DataTable supports on purpose: its
                // `isRowNavigation()` guard stands down for a click that landed on an
                // anchor, so the link wins over the row rather than fighting it
                // (DataTable/README.md → Clickable rows).
                //
                // It earns that complication on a COMPILATION, which is the case this
                // column exists for at all: 20 tracks by 20 different performers, and the
                // performer is exactly what a listener wants to follow. Null for a file
                // crediting nobody, and then the cell is plain text.
                'artistUrl' => $track->artist_id === null
                    ? null
                    : route('music.artists.show', $track->artist_id, absolute: false),
                // Raw seconds and raw bytes; the page clocks and humanises them against
                // the viewer's locale (Utils/formatting.ts).
                'duration' => $track->duration,
                'size' => $track->size,
                // Offered only when the FILE claims a picture of its own (`tracks.cover`,
                // the scan-time flag) — so this costs no filesystem access at all, which a
                // 154-row soundtrack would otherwise pay per row. A file with no embedded
                // art gets no thumbnail and the page draws its placeholder, which on an
                // album whose art varies per song is the informative reading.
                //
                // Note what this decides and what it does not: it decides whether a
                // thumbnail is OFFERED. The bytes still come from the song cover route,
                // which resolves a track's own order — embedded picture first, the
                // directory image as its fallback — so a file whose tag turns out to be
                // unreadable can still answer with the album's image rather than 404.
                'coverUrl' => $track->cover
                    ? route('music.songs.cover', $track->id, absolute: false)
                    : null,
                // Makes the row clickable — the frontend visits this on a row click / card
                // tap, and the name cell renders it as a real link.
                'href' => route('music.songs.show', $track->id, absolute: false),
            ],
            // Sort KEYS, not columns — the service maps them like the primary, and hands
            // the surviving ones back so the table's header can mark CD *and* Track as
            // sorted rather than pretending only the first one is.
            tiebreakers: ['disc', 'track', 'name'],
        );
    }

    /**
     * The four numbers that describe the container rather than any one file: how many
     * songs, how many discs, how long it plays, and when its newest file changed.
     *
     * One aggregate query over the `(collection_id, disc, track)` index instead of
     * four, and instead of hydrating every track row to count it — the same reason
     * SongController computes its totals in SQL. `modified_at` stands in for a
     * "modified" date the collections table doesn't have: after a bulk import a
     * file's mtime is the truest thing about when an album last changed.
     *
     * @return array{songs: int, discs: int, duration: float|null, modifiedAt: string|null}
     */
    private function trackTotals(Collection $album): array
    {
        $totals = Track::query()
            ->where('collection_id', $album->id)
            ->selectRaw('count(*) as songs')
            ->selectRaw('count(distinct disc) as discs')
            ->selectRaw('sum(duration) as duration')
            ->selectRaw('max(modified_at) as modified_at')
            ->first();

        return [
            'songs' => (int) $totals?->songs,
            'discs' => (int) $totals?->discs,
            'duration' => $totals?->duration === null ? null : (float) $totals->duration,
            // Parsed explicitly rather than left to a model cast: `max(modified_at)`
            // is a raw aggregate on a select, not the model's own `modified_at`
            // attribute, so no cast applies to it and it arrives as whatever string
            // the driver formats a timestamp as.
            'modifiedAt' => $totals?->modified_at === null
                ? null
                : Carbon::parse($totals->modified_at)->toIso8601String(),
        ];
    }
}
