<?php

namespace App\Http\Controllers\Music;

use App\Enums\SongFilter;
use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Track;
use App\Models\User;
use App\Services\DataTableService;
use App\Services\Search\FoldedSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Music → Songs sub-section (`GET /music/songs`, route `music.songs`, behind
 * auth) — the full song listing as a server-driven DataTable (sort / search /
 * paginate all in the URL). Linked from the SongsWidget footer.
 *
 * Every row also carries an `href` to its own detail page (SongController), which
 * is what makes the table's rows clickable — the frontend only follows what the
 * server puts there, so this controller owns where a row leads.
 *
 * ABOVE THE TABLE SITS A STATS STRIP, and it is not decoration: each of its four counts is a
 * question worth acting on (songs never played, added this week, filed twice, travelling with no
 * artwork), and each tile LINKS to the table narrowed to exactly what it counted — `?filter=`,
 * one value per SongFilter case. A number a reader cannot follow is a poster; the link is the
 * feature.
 *
 * THE COUNTS DESCRIBE THE WHOLE LIBRARY, never the filtered view, so they hold still while a
 * reader works through one of them: a strip that re-counted inside its own filter would answer
 * "23 without artwork" with "23 without artwork" forever, and the tile a reader arrived by would
 * be the one tile that could never change.
 */
class SongsController extends Controller
{
    /**
     * Render the Songs listing. Left-joins the taxonomy tables so a song's
     * artist / album / genre names are one query (and sortable by those columns),
     * then hands the query to DataTableService which reads the URL state and
     * shapes the TableResponse the frontend DataTable expects.
     *
     * `?filter=` is applied to that query BEFORE it goes in, so the pager, the search and the
     * sort all work over the narrowed set rather than around it — and it is echoed back through
     * `filters` so the table knows what it is showing (DataTableService says what reads it).
     */
    public function __invoke(Request $request): Response
    {
        $reader = $request->user();
        $filter = SongFilter::fromInput($request->input('filter'));

        $query = Track::query()
            ->where('tracks.type', TrackType::Music)
            ->leftJoin('artists', 'tracks.artist_id', '=', 'artists.id')
            ->leftJoin('collections', 'tracks.collection_id', '=', 'collections.id')
            ->leftJoin('genres', 'tracks.genre_id', '=', 'genres.id')
            ->select([
                'tracks.id',
                'tracks.name',
                'tracks.duration',
                'artists.name as artist_name',
                'collections.name as album_name',
                'genres.name as genre_name',
            ]);

        // Before DataTableService sees it, so the filter is part of what gets counted, searched
        // and paged rather than something applied to one page of rows.
        $filter?->apply($query, $reader);

        $table = DataTableService::buildResponse(
            query: $query,
            request: $request,
            sortable: ['name', 'artist', 'album', 'genre', 'duration'],
            // Sort keys → real columns: the frontend sorts by `artist`, the DB
            // column is `artists.name` after the join. (ORDER BY is fine on the
            // name columns' nondeterministic ICU collation — only LIKE isn't.)
            sortColumnMap: [
                'name' => 'tracks.name',
                'artist' => 'artists.name',
                'album' => 'collections.name',
                'genre' => 'genres.name',
                'duration' => 'tracks.duration',
            ],
            defaultSort: 'name',
            // Searches exactly the four columns the table shows, so every hit is
            // explainable from the row in front of you — a "Moto" that matched
            // *Badmotorfinger* has that album sitting in its Album cell. Matching
            // runs on the `name_fold` companions, which is what makes it accent-
            // and case-insensitive ("Mgla" finds "Mgła") on one code path for both
            // Postgres and sqlite — see FoldedSearch.
            searchCallback: fn (Builder $q, string $search) => FoldedSearch::apply($q, $search, [
                'tracks.name', 'artists.name', 'collections.name', 'genres.name',
            ]),
            // …and the NARROW one, for `?searchIn=name` — the title alone, which is what the
            // cross-kind search dropdown matches and therefore what its hand-off has to land on.
            // Without it "show all 70 songs" opened a table of 2,000+: every track by Godspeed You!
            // Black Emperor and everything filed under Black Metal, none of them a song called
            // Black. See DataTableService::SEARCH_IN_NAME.
            nameSearchCallback: fn (Builder $q, string $search) => FoldedSearch::apply($q, $search, [
                'tracks.name',
            ]),
            rowMapper: fn (Track $song): array => [
                'id' => $song->id,
                'name' => $song->name,
                'artist' => $song->artist_name,
                'album' => $song->album_name,
                'genre' => $song->genre_name,
                // Raw seconds — the page's `cell-duration` slot clocks it to m:ss
                // (Utils/formatting.ts), so the listing and the detail page share
                // one implementation. Sorting is unaffected: it happens in SQL on
                // `tracks.duration`, which is these same seconds.
                'duration' => $song->duration,
                // Makes the row clickable in the frontend DataTable, which visits
                // this on a row click / card tap (and the title cell renders it as
                // a real link). Relative so it works whatever host serves the app.
                'href' => route('music.songs.show', $song->id, absolute: false),
            ],
            // Echoed, not applied here — see the parameter's docblock, and `filters` in the
            // response for the one thing the frontend does with it.
            filters: $filter ? ['filter' => $filter->value] : null,
        );

        return Inertia::render('Music/Songs/SongsPage', [
            'table' => $table,
            'stats' => $this->stats($reader, $filter),
        ]);
    }

    /**
     * The strip's numbers: the library's size, then one tile per SongFilter.
     *
     * EAGER, unlike the `Inertia::defer` a page of aggregates usually wants, and the reason is
     * the DataTable: every sort, page and search is a full visit, so a deferred strip would
     * blank and re-arrive on each click — a skeleton flashing above a table that did not need to
     * wait for it. Five counts, four of them index-backed, against a listing query that already
     * joins three tables and counts twice.
     *
     * A TILE'S `href` IS DECIDED HERE, like every other link this app renders (a row's own
     * href, the widget footers), so the strip cannot drift from the routes. Three readings, and
     * the middle one is the one the strip is really for:
     *
     *   - the ACTIVE filter offers the way back out — the unfiltered listing — because a
     *     filtered table a reader cannot leave is a dead end, and its tile is the only honest
     *     place to put that door;
     *   - a count of zero offers NOTHING, since a link to an empty table is a promise the page
     *     cannot keep;
     *   - anything else links to itself, filtered.
     *
     * @return array{total: int, filters: list<array{key: string, count: int, href: string|null, active: bool}>}
     */
    private function stats(?User $reader, ?SongFilter $active): array
    {
        $tiles = [];

        foreach (SongFilter::cases() as $filter) {
            $count = $filter->count($reader);
            $isActive = $active === $filter;

            $tiles[] = [
                'key' => $filter->value,
                'count' => $count,
                'href' => match (true) {
                    $isActive => route('music.songs', absolute: false),
                    $count > 0 => route('music.songs', ['filter' => $filter->value], absolute: false),
                    default => null,
                },
                'active' => $isActive,
            ];
        }

        return [
            'total' => Track::query()->where('tracks.type', TrackType::Music)->count(),
            'filters' => $tiles,
        ];
    }
}
