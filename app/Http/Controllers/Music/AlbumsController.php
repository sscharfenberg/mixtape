<?php

namespace App\Http\Controllers\Music;

use App\Enums\CollectionType;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Track;
use App\Services\DataTableService;
use App\Services\Player\PlayCounts;
use App\Services\Search\FoldedSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Music → Albums sub-section (`GET /music/albums`, route `music.albums`, behind
 * auth) — the full album listing as a server-driven DataTable (sort / search /
 * paginate all in the URL). Linked from the AlbumsWidget footer, and built on the
 * same DataTableService as the Songs listing.
 *
 * An album row is mostly AGGREGATE: a collection row itself stores only name, year
 * and its album-artist, while the four numbers a listener actually browses by — how
 * many songs, how many discs, how long, how recently the files changed — all live in
 * its tracks. They are computed as correlated subqueries rather than by joining and
 * grouping, so the query stays one row per album and every one of them is sortable
 * through DataTableService's plain `orderBy` on the alias.
 *
 * Every row also carries an `href` to the album's own page (AlbumController), which
 * is what makes the table's rows clickable — the frontend only follows what the
 * server puts there.
 */
class AlbumsController extends Controller
{
    /**
     * Render the Albums listing.
     *
     * The album-artist is a left join (so it is sortable and searchable as a column),
     * everything track-derived is a subquery. `embedded_cover_id` is not shown
     * anywhere: together with the recorded `cover_path` it is what lets the row mapper
     * decide whether a thumbnail exists — from this one query, with no filesystem
     * access at all.
     */
    public function __invoke(Request $request): Response
    {
        // One reusable correlated base: "the tracks of the album in the current row".
        $tracksOfAlbum = fn (): \Illuminate\Database\Query\Builder => Track::query()
            ->whereColumn('tracks.collection_id', 'collections.id')
            ->toBase();

        // The album's own running order, so "first track" means disc 1 track 1 and not
        // whatever the storage engine hands back. `name` breaks the tie for rips whose
        // files carry no track numbers at all.
        $inAlbumOrder = fn (\Illuminate\Database\Query\Builder $q): \Illuminate\Database\Query\Builder => $q
            ->orderBy('disc')
            ->orderBy('track')
            ->orderBy('name');

        $query = Collection::query()
            ->where('collections.type', CollectionType::Album)
            ->leftJoin('artists', 'collections.album_artist_id', '=', 'artists.id')
            ->select([
                'collections.id',
                'collections.name',
                'collections.year',
                // The album's directory image, as the scanner recorded it. Selected —
                // not read off disk — which is the whole point of the column: a page
                // of 50 albums used to cost 50 directory reads to answer "is there
                // artwork?", and now costs none.
                'collections.cover_path',
                'artists.name as artist_name',
            ])
            // How many songs, and how long the album plays. `withSum` gives raw
            // seconds, exactly as a single track's duration goes over — the page
            // clocks it (Utils/formatting.ts → formatClock, which grows an hours
            // part on its own, which an album total regularly needs).
            ->withCount('tracks')
            ->withSum('tracks', 'duration')
            // "Modified at" for a container is its newest file's mtime: a collection
            // row has no file date of its own, and after a bulk import an album's
            // mtime is the truest "when did this last change" (the same choice the
            // Music page's `latest` album mode makes).
            ->withMax('tracks', 'modified_at')
            // THE READER'S OWN listens — the only column here that differs per viewer, and
            // deliberately not the instance-wide total (this box is shared; what makes a
            // browse list useful is what YOU have played). Counted as listening EVENTS, so
            // playing a record twice through is 24 rather than 12.
            //
            // A grouped join rather than a correlated count, because the column is SORTABLE
            // and a sortable column is computed for every album before the sort can run — the
            // measurement that settled it is in PlayCounts::ownPerArtist. LEFT, and COALESCEd
            // below, so an album nobody has played still lists with a 0.
            ->leftJoinSub(PlayCounts::ownPerAlbum($request->user()), 'own_plays', 'own_plays.subject_id', '=', 'collections.id')
            ->selectRaw('coalesce(own_plays.plays, 0) as plays_count')
            ->addSelect([
                // COUNT(DISTINCT disc) — the same count the song page's "1/2" comes
                // from, so the two pages can never disagree about how many discs an
                // album has. It counts 0 when no file carries a disc tag (SQL skips
                // NULLs); the row mapper floors that to 1 rather than the SQL doing
                // it, because the two dialects spell that function differently
                // (Postgres GREATEST, SQLite MAX) and this query has to run on both.
                'discs_count' => $tracksOfAlbum()->selectRaw('count(distinct disc)'),
                // Whether any file carries embedded art, which is the cover route's
                // fallback when the directory has no image. A boolean would do; the id
                // costs the same and says which file answered.
                'embedded_cover_id' => $inAlbumOrder(
                    $tracksOfAlbum()->select('id')->where('cover', true)
                )->limit(1),
            ]);

        $table = DataTableService::buildResponse(
            query: $query,
            request: $request,
            sortable: ['name', 'year', 'artist', 'songs', 'discs', 'modifiedAt', 'duration', 'plays'],
            // Sort keys → real columns. The aggregates sort by their SELECT alias,
            // which both Postgres and SQLite resolve in ORDER BY; the two name
            // columns sort on the raw (ICU-collated) name, which is fine for ORDER BY
            // — only LIKE is not (see FoldedSearch).
            sortColumnMap: [
                'name' => 'collections.name',
                'year' => 'collections.year',
                'artist' => 'artists.name',
                'songs' => 'tracks_count',
                'discs' => 'discs_count',
                'modifiedAt' => 'tracks_max_modified_at',
                'duration' => 'tracks_sum_duration',
                'plays' => 'plays_count',
            ],
            // Newest first: what a listener wants from a 1200-album listing is "what
            // has changed lately", not the top of the alphabet — and since an album's
            // mtime is its newest file's, a fresh import or a re-tag surfaces itself
            // without anyone sorting for it. Alphabetical is one header click away.
            defaultSort: 'modifiedAt',
            defaultDirection: 'desc',
            // Which makes a tiebreak necessary rather than merely tidy: a bulk copy
            // stamps a whole batch of files with the same second, so hundreds of albums
            // can share the sorted value, and SQL orders tied rows arbitrarily — the
            // same album could appear on page 1 and page 2 of one browse. `name` is
            // near-unique and reads as the natural second key ("newest, then A–Z"),
            // which is also what the header advertises while the table is on this sort.
            tiebreakers: ['name'],
            // The two text columns the table shows, matched through their `name_fold`
            // companions so the search is accent- and case-insensitive on one code
            // path for Postgres and SQLite alike.
            searchCallback: fn (Builder $q, string $search) => FoldedSearch::apply($q, $search, [
                'collections.name', 'artists.name',
            ]),
            rowMapper: fn (Collection $album): array => [
                'id' => $album->id,
                'name' => $album->name,
                'artist' => $album->artist_name,
                'year' => $album->year,
                'songs' => $album->tracks_count,
                // Floored to 1: an album whose files carry no disc tag counts 0
                // discs, and "0" in a listing reads as missing data rather than as
                // "nobody tagged the disc number". It is still one disc.
                'discs' => max(1, (int) $album->discs_count),
                // Raw seconds and a raw ISO-8601 instant — every formatter lives on
                // the page (Utils/formatting.ts), against the viewer's locale and
                // timezone.
                'duration' => $album->tracks_sum_duration === null ? null : (float) $album->tracks_sum_duration,
                // The reader's own listens, raw count and all — a zero prints as a dash on
                // the page, which is a display decision and belongs there.
                'plays' => (int) $album->plays_count,
                // Parsed explicitly: an aggregate attribute is NOT the related
                // model's own attribute, so Track's `modified_at` datetime cast does
                // not reach it and `withMax` hands back whatever string the driver
                // formats a timestamp as. (Read as a Carbon it fails outright — which
                // is how this was found.)
                'modifiedAt' => $album->tracks_max_modified_at === null
                    ? null
                    : Carbon::parse($album->tracks_max_modified_at)->toIso8601String(),
                // The thumbnail, or null so the table draws its placeholder instead of
                // pointing an <img> at a 404. Answered entirely from this query: an
                // album has art if the scanner recorded a directory image for it, or if
                // any of its files carries an embedded picture. No stat, no second
                // query — which is what the `cover_path` column bought.
                'coverUrl' => $album->cover_path !== null || $album->embedded_cover_id !== null
                    ? route('music.albums.cover', $album->id, absolute: false)
                    : null,
                // Makes the row clickable in the frontend DataTable, which visits this
                // on a row click / card tap (and the name cell renders it as a real
                // link). Relative so it works whatever host serves the app.
                'href' => route('music.albums.show', $album->id, absolute: false),
            ],
        );

        return Inertia::render('Music/Albums/AlbumsPage', [
            'table' => $table,
        ]);
    }
}
