<?php

namespace App\Http\Controllers\Music;

use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Track;
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
 */
class SongsController extends Controller
{
    /**
     * Render the Songs listing. Left-joins the taxonomy tables so a song's
     * artist / album / genre names are one query (and sortable by those columns),
     * then hands the query to DataTableService which reads the URL state and
     * shapes the TableResponse the frontend DataTable expects.
     */
    public function __invoke(Request $request): Response
    {
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
        );

        return Inertia::render('Music/Songs/SongsPage', [
            'table' => $table,
        ]);
    }
}
