<?php

namespace App\Http\Controllers\Music;

use App\Enums\GenreFilter;
use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Genre;
use App\Models\Track;
use App\Models\User;
use App\Services\DataTableService;
use App\Services\Music\DominantGenre;
use App\Services\Player\PlayCounts;
use App\Services\Search\FoldedSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Music → Genres sub-section (`GET /music/genres`, route `music.genres`, behind
 * auth) — the full genre listing as a server-driven DataTable (sort / search / paginate
 * all in the URL). Linked from the GenresWidget footer, and built on the same
 * DataTableService as the other three listings.
 *
 * Like an artist row, a genre row is ENTIRELY aggregate apart from its name — the genres
 * table holds a name and nothing else. Three of the four numbers are the obvious counts
 * over the genre's own tracks. The fourth is not:
 *
 * ARTISTS counts the artists whose MAIN genre this is — not everyone who ever recorded a
 * song in it (owner's call). That is what makes the column add up: each artist is counted
 * exactly once, under one genre, so the column totals the library's artists rather than a
 * number inflated by every guitarist who once cut a blues track. The rule and its
 * tie-break live in DominantGenre, shared with the artist page so the two can never
 * disagree about where an artist belongs.
 *
 * The consequence worth knowing while reading a row: a genre can hold hundreds of songs
 * and still count 0 artists — it is nobody's *main* genre, only everybody's second one.
 * That is the intended reading, not missing data.
 *
 * The fifth number, `plays`, is the READER'S OWN listens and the only column here that
 * differs per viewer: listening events over the genre's tracks, not the instance's total.
 * This box is shared with family and friends, and what makes a browse list useful is what
 * YOU have played — the yours/others split belongs on the detail page, where a tile can
 * label it. Same rule and same shape as the artists listing's.
 *
 * ABOVE THE TABLE SITS A STATS STRIP — four counts, each a link to the table narrowed to exactly
 * what it counted (`?filter=`, one value per GenreFilter case).
 * The one worth the strip is "only one artist", which is NOT this table's `artists` column
 * asked with a filter: that column counts artists whose MAIN genre this is, where the tile
 * counts the distinct performers of its songs (GenreFilter says why they differ).
 *
 * The counts describe every row the listing can show, never the filtered view, so they hold still
 * while a reader works through one of them (SongsController carries that argument in full).
 *
 * Every row also carries an `href` to the genre's own page (GenreController), which is
 * what makes the table's rows clickable — the frontend only follows what the server puts
 * there.
 */
class GenresController extends Controller
{
    /**
     * Render the Genres listing.
     *
     * Not filtered to genres that have tracks, for the same reason the Artists listing
     * isn't: the sums are COALESCEd to 0 instead, so no aggregate is ever NULL. That
     * matters because Postgres sorts NULLs FIRST under `ORDER BY … DESC` and the default
     * sort IS a descending sum — an orphaned genre would otherwise lead the page a reader
     * opens (and SQLite sorts NULLs last, so the test suite would never have shown it).
     */
    public function __invoke(Request $request): Response
    {
        $reader = $request->user();
        $filter = GenreFilter::fromInput($request->input('filter'));

        // One reusable correlated base: "the music tracks tagged with the genre in the
        // current row". Scoped to music like every query in this namespace: `tracks` holds audiobook chapters as well as
        // music, and a chapter cannot carry an artist or a genre at all — the type CHECK
        // forbids it. So the scope is belt-and-braces today, and what keeps the numbers right
        // the day a kind that CAN carry them is added.
        $tracksOfGenre = fn (): \Illuminate\Database\Query\Builder => Track::query()
            ->where('tracks.type', TrackType::Music)
            ->whereColumn('tracks.genre_id', 'genres.id')
            ->toBase();

        $query = Genre::query()
            ->select(['genres.id', 'genres.name'])
            // LEFT, and COALESCEd below: that subquery only has rows for genres that win
            // somewhere, and a genre nobody counts as their main one still belongs in the
            // listing with its songs.
            ->leftJoinSub(DominantGenre::artistCountsPerGenre(), 'main', 'main.genre_id', '=', 'genres.id')
            ->selectRaw('coalesce(main.artists_count, 0) as artists_count')
            // THE READER'S OWN listens, not the instance's — see the class docblock. The
            // second grouped join on this query, and for the same reason as the first: the
            // column is SORTABLE, so it is computed for every genre before the sort can run,
            // and a correlated count would re-probe the plays table once per genre (measured
            // at 914 ms against 123 ms on a five-year table — PlayCounts::ownPerArtist).
            ->leftJoinSub(PlayCounts::ownPerGenre($request->user()), 'own_plays', 'own_plays.subject_id', '=', 'genres.id')
            ->selectRaw('coalesce(own_plays.plays, 0) as plays_count')
            ->addSelect([
                'songs_count' => $tracksOfGenre()->selectRaw('count(*)'),
                'duration_total' => $tracksOfGenre()->selectRaw('coalesce(sum(duration), 0)'),
                'size_total' => $tracksOfGenre()->selectRaw('coalesce(sum(size), 0)'),
            ]);

        // Before DataTableService sees it, so the filter is part of what gets counted, searched
        // and paged rather than something applied to one page of rows.
        $filter?->apply($query, $reader);

        $table = DataTableService::buildResponse(
            query: $query,
            request: $request,
            sortable: ['name', 'artists', 'songs', 'duration', 'size', 'plays'],
            // Sort keys → real columns. Every aggregate sorts by its SELECT alias, which
            // both Postgres and SQLite resolve in ORDER BY; the name sorts on the raw
            // (ICU-collated) column, which is fine for ORDER BY — only LIKE is not (see
            // FoldedSearch).
            sortColumnMap: [
                'name' => 'genres.name',
                'artists' => 'artists_count',
                'songs' => 'songs_count',
                'duration' => 'duration_total',
                'size' => 'size_total',
                'plays' => 'plays_count',
            ],
            // Most audio first — the same default, and the same reasoning, as the Artists
            // listing: the genre you have the most of is the one you are most likely
            // browsing for, where alphabetical order just puts Ambient on top forever.
            defaultSort: 'duration',
            defaultDirection: 'desc',
            // The one text column there is, matched through its `name_fold` companion so
            // the search is accent- and case-insensitive on one code path for Postgres and
            // SQLite alike.
            searchCallback: fn (Builder $q, string $search) => FoldedSearch::apply($q, $search, [
                'genres.name',
            ]),
            rowMapper: fn (Genre $genre): array => [
                'id' => $genre->id,
                'name' => $genre->name,
                'artists' => (int) $genre->artists_count,
                'songs' => (int) $genre->songs_count,
                // Raw seconds and raw bytes — the page clocks and humanises them against
                // the viewer's locale (Utils/formatting.ts), like every other listing.
                'duration' => (float) $genre->duration_total,
                'size' => (int) $genre->size_total,
                // The reader's own listens, raw count and all — a zero prints as a dash on
                // the page, which is a display decision and belongs there.
                'plays' => (int) $genre->plays_count,
                // Makes the row clickable in the frontend DataTable, which visits this on
                // a row click / card tap (and the name cell renders it as a real link).
                // Relative so it works whatever host serves the app.
                'href' => route('music.genres.show', $genre->id, absolute: false),
            ],
            // Paging stability first, and on the default sort it doubles as the compound
            // order the header advertises ("most audio, then A–Z"). Genre names are
            // unique, so this makes all four aggregate sorts deterministic — without it
            // the tail of the list, where a dozen genres sit at one song each, could
            // reshuffle between two requests and drop a row off the page a reader is on.
            tiebreakers: ['name'],
            // Echoed rather than applied here — the frontend drops a row selection when it changes,
            // since the rows under those ticks are no longer the same rows.
            filters: $filter ? ['filter' => $filter->value] : null,
        );

        return Inertia::render('Music/Genres/GenresPage', [
            'table' => $table,
            'stats' => $this->stats($reader, $filter),
        ]);
    }

    /**
     * The strip's numbers: how many rows the listing has, then one tile per GenreFilter.
     *
     * EAGER rather than deferred, and each tile's `href` decided here, for the reasons
     * SongsController spells out — every table interaction is a full visit, and a link is the
     * controller's to own. The active filter's tile offers the way back out; a count of zero offers
     * nothing at all.
     *
     * @return array{total: int, filters: list<array{key: string, count: int, href: string|null, active: bool}>}
     */
    private function stats(?User $reader, ?GenreFilter $active): array
    {
        $tiles = [];

        foreach (GenreFilter::cases() as $filter) {
            $count = $filter->count($reader);
            $isActive = $active === $filter;

            $tiles[] = [
                'key' => $filter->value,
                'count' => $count,
                'href' => match (true) {
                    $isActive => route('music.genres', absolute: false),
                    $count > 0 => route('music.genres', ['filter' => $filter->value], absolute: false),
                    default => null,
                },
                'active' => $isActive,
            ];
        }

        return [
            'total' => Genre::query()->count(),
            'filters' => $tiles,
        ];
    }
}
