<?php

namespace App\Http\Controllers\Music;

use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\Track;
use App\Services\DataTableService;
use App\Services\Search\FoldedSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Music → Artists sub-section (`GET /music/artists`, route `music.artists`,
 * behind auth) — the full artist listing as a server-driven DataTable (sort / search /
 * paginate all in the URL). Linked from the ArtistsWidget footer, and built on the same
 * DataTableService as the Songs and Albums listings.
 *
 * An artist row is ENTIRELY aggregate apart from its name: the artists table holds a
 * name and nothing else (not even timestamps — the scanner mints and prunes it), so
 * every number a listener browses by is counted over other tables. They are correlated
 * subqueries rather than joins with a GROUP BY, so the query stays one row per artist
 * and each aggregate sorts through DataTableService's plain `orderBy` on its alias.
 *
 * `albums` counts the artist's own DISCOGRAPHY — albums credited to them
 * (`collections.album_artist_id`) — and nothing else (owner's call). An artist relates to
 * albums a second way, through the albums their individual tracks sit on, and the two
 * numbers regularly disagree: a session player who only guests on other people's
 * compilations reports 0 albums beside a dozen songs. That is the literal truth about
 * their discography, and the reading this column commits to — so 0-albums-with-N-songs is
 * expected here, not missing data. The other count is not shown anywhere: an artist's
 * albums are the ones they are credited with, full stop.
 *
 * Every row also carries an `href` to the artist's own page (ArtistController), which is
 * what makes the table's rows clickable — the frontend only follows what the server
 * puts there.
 */
class ArtistsController extends Controller
{
    /**
     * Render the Artists listing.
     *
     * Deliberately NOT filtered to artists that have tracks, unlike the Music page's
     * artists widget: that filter is there because a track-less compilation owner has a
     * NULL aggregate and Postgres sorts NULLs FIRST under `ORDER BY … DESC`, floating
     * exactly the artists nobody was looking for to the top. Here the sums are COALESCEd
     * to 0 instead, so no aggregate is ever NULL, both sort directions are well-defined on
     * both drivers (SQLite sorts NULLs last, so the bug would have been invisible in the
     * test suite), and a credited-only artist stays listed — with its `albums` column
     * saying what it is.
     *
     * That is load-bearing rather than tidy, because the default sort IS a descending sum:
     * left NULL, every credited-only artist would lead the page a reader opens.
     */
    public function __invoke(Request $request): Response
    {
        // One reusable correlated base: "this artist's own music tracks". Scoped to music
        // like every other query in this namespace: `tracks` holds audiobook chapters as well as
        // music, and a chapter cannot carry an artist or a genre at all — the type CHECK
        // forbids it. So the scope is belt-and-braces today, and what keeps the numbers right
        // the day a kind that CAN carry them is added.
        $tracksOfArtist = fn (): \Illuminate\Database\Query\Builder => Track::query()
            ->where('tracks.type', TrackType::Music)
            ->whereColumn('tracks.artist_id', 'artists.id')
            ->toBase();

        $query = Artist::query()
            ->select(['artists.id', 'artists.name'])
            // The discography. The collections CHECK pins `album_artist_id` to
            // `type = 'album'`, so this needs no type clause of its own (Artist::albums()).
            ->withCount('albums')
            ->addSelect([
                'songs_count' => $tracksOfArtist()->selectRaw('count(*)'),
                // COALESCEd rather than left NULL, which is what keeps a track-less
                // artist from leading a descending sort on Postgres (see the docblock).
                // "0:00" and "0.00 MB" are also the honest readings for an artist with no
                // files of their own.
                'duration_total' => $tracksOfArtist()->selectRaw('coalesce(sum(duration), 0)'),
                'size_total' => $tracksOfArtist()->selectRaw('coalesce(sum(size), 0)'),
            ]);

        $table = DataTableService::buildResponse(
            query: $query,
            request: $request,
            sortable: ['name', 'albums', 'songs', 'duration', 'size'],
            // Sort keys → real columns. Every aggregate sorts by its SELECT alias, which
            // both Postgres and SQLite resolve in ORDER BY; the name sorts on the raw
            // (ICU-collated) column, which is fine for ORDER BY — only LIKE is not (see
            // FoldedSearch).
            sortColumnMap: [
                'name' => 'artists.name',
                'albums' => 'albums_count',
                'songs' => 'songs_count',
                'duration' => 'duration_total',
                'size' => 'size_total',
            ],
            // Most audio first, which is the same "popular" reading the Music page's
            // artists widget opens on (MusicController::artists) — the artist you have the
            // most of is the one you are most likely browsing for, and alphabetical order
            // just puts whoever starts with an A on top. One header click gets it back.
            defaultSort: 'duration',
            defaultDirection: 'desc',
            // The one text column there is. Folded so the search is accent- and
            // case-insensitive on one code path for Postgres and SQLite alike ("Mgla"
            // finds "Mgła" — for artist names, the case that matters most).
            searchCallback: fn (Builder $q, string $search) => FoldedSearch::apply($q, $search, [
                'artists.name',
            ]),
            rowMapper: fn (Artist $artist): array => [
                'id' => $artist->id,
                'name' => $artist->name,
                'albums' => (int) $artist->albums_count,
                'songs' => (int) $artist->songs_count,
                // Raw seconds and raw bytes — the page clocks and humanises them against
                // the viewer's locale (Utils/formatting.ts), like every other listing.
                'duration' => (float) $artist->duration_total,
                'size' => (int) $artist->size_total,
                // Makes the row clickable in the frontend DataTable, which visits this on
                // a row click / card tap (and the name cell renders it as a real link).
                'href' => route('music.artists.show', $artist->id, absolute: false),
            ],
            // Paging stability first, and on the default sort it doubles as the compound
            // order the header advertises ("most audio, then A–Z"). Artist names are
            // unique, so this makes all four aggregate sorts deterministic; without it the
            // many artists sharing "1 album, 12 songs" — or, on the default sort, the many
            // credited-only artists all sitting at 0 seconds — could reshuffle between two
            // requests and drop a row off the page a reader is on.
            tiebreakers: ['name'],
        );

        return Inertia::render('Music/Artists/ArtistsPage', [
            'table' => $table,
        ]);
    }
}
