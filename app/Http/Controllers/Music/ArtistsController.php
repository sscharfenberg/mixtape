<?php

namespace App\Http\Controllers\Music;

use App\Enums\ArtistFilter;
use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\User;
use App\Services\DataTableService;
use App\Services\Music\ArtistCredits;
use App\Services\Player\PlayCounts;
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
 * `songs`, `duration` and `size` read the other way round, and deliberately: they count
 * everything CREDITED to the artist — what they perform, plus what sits on a record credited
 * to them (App\Services\Music\ArtistCredits). So a compilation owner whose files all name the
 * individual performers reports its albums AND their tracks, and a band's own row includes the
 * "feat." variants that tag as a separate artist. These are the same three numbers the artist's
 * own hero prints, which is the point — a listing that counted the narrower set would send a
 * reader to a page disagreeing with the row they clicked.
 *
 * `plays` is the READER'S OWN listens, and the only column on this page that differs per
 * viewer. It counts listening events over the artist's tracks — so a run through an album
 * twice is 24, not 12 — and it is deliberately not the instance-wide total: this box is
 * shared with family and friends, and what makes a browse list useful is what YOU have
 * played. The yours/others split belongs on the detail page, where a tile can label it.
 *
 * ABOVE THE TABLE SITS A STATS STRIP — four counts, each a link to the table narrowed to exactly
 * what it counted (`?filter=`, one value per ArtistFilter case).
 * Two of the four are questions the table cannot be asked: a credit that looks like several
 * artists, and anything about dates — the listing has no date column at all. The other two are
 * reachable by a sort but not isolable by one (docs/browse-stats.md).
 *
 * The counts describe every row the listing can show, never the filtered view, so they hold still
 * while a reader works through one of them (SongsController carries that argument in full).
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
        $reader = $request->user();
        $filter = ArtistFilter::fromInput($request->input('filter'));

        $query = Artist::query()
            ->select(['artists.id', 'artists.name'])
            // The discography. The collections CHECK pins `album_artist_id` to
            // `type = 'album'`, so this needs no type clause of its own (Artist::albums()).
            ->withCount('albums')
            // THE READER'S OWN listens, not the instance's. This one is shared with family
            // and friends, so a household total would answer a question nobody asked of a
            // browse list — "how much have I played this artist" is what sorts usefully. The
            // yours/others split lives on the detail page, where there is room to label it.
            //
            // A grouped join rather than a correlated count, because this column is
            // SORTABLE and a sortable column is computed for every artist before the sort can
            // run — see PlayCounts::ownPerArtist for the measurement that settled the shape.
            // LEFT, and COALESCEd below: an artist nobody has played has no row here and
            // still belongs in the listing.
            ->leftJoinSub(PlayCounts::ownPerArtist($request->user()), 'own_plays', 'own_plays.subject_id', '=', 'artists.id')
            ->selectRaw('coalesce(own_plays.plays, 0) as plays_count')
            // The three catalogue numbers, over everything CREDITED to the artist — performed
            // plus album-credited, the union App\Services\Music\ArtistCredits defines and the
            // artist's own page lists. Scoped to music inside that service, like every other
            // query in this namespace.
            //
            // A GROUPED JOIN rather than three correlated subselects, for the same reason
            // `own_plays` above is one: all three columns are SORTABLE, so every artist has to
            // be computed before the sort can run. The credit union cannot be answered from
            // one table's index, so correlated it re-probes once per artist — 366 ms against
            // 12 ms for aggregating the library once and hash-joining it. LEFT, and COALESCEd
            // below, because an artist credited with nothing playable has no row here and
            // still belongs in the listing.
            ->leftJoinSub(ArtistCredits::totalsPerArtist(), 'credited', 'credited.subject_id', '=', 'artists.id')
            // COALESCEd rather than left NULL, which is what keeps such an artist from
            // leading a descending sort on Postgres (see the docblock). "0:00" and "0.00 MB"
            // are also the honest readings for one with nothing to play.
            ->selectRaw('coalesce(credited.songs_count, 0) as songs_count')
            ->selectRaw('coalesce(credited.duration_total, 0) as duration_total')
            ->selectRaw('coalesce(credited.size_total, 0) as size_total');

        // Before DataTableService sees it, so the filter is part of what gets counted, searched
        // and paged rather than something applied to one page of rows.
        $filter?->apply($query, $reader);

        $table = DataTableService::buildResponse(
            query: $query,
            request: $request,
            sortable: ['name', 'albums', 'songs', 'duration', 'size', 'plays'],
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
                'plays' => 'plays_count',
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
                // The reader's own listens. Sent as the raw count including 0 — the page
                // decides that a zero prints as a dash, which is a display decision and so
                // belongs there rather than here.
                'plays' => (int) $artist->plays_count,
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
            // Echoed rather than applied here — the frontend drops a row selection when it changes,
            // since the rows under those ticks are no longer the same rows.
            filters: $filter ? ['filter' => $filter->value] : null,
        );

        return Inertia::render('Music/Artists/ArtistsPage', [
            'table' => $table,
            'stats' => $this->stats($reader, $filter),
        ]);
    }

    /**
     * The strip's numbers: how many rows the listing has, then one tile per ArtistFilter.
     *
     * EAGER rather than deferred, and each tile's `href` decided here, for the reasons
     * SongsController spells out — every table interaction is a full visit, and a link is the
     * controller's to own. The active filter's tile offers the way back out; a count of zero offers
     * nothing at all.
     *
     * @return array{total: int, filters: list<array{key: string, count: int, href: string|null, active: bool}>}
     */
    private function stats(?User $reader, ?ArtistFilter $active): array
    {
        $tiles = [];

        foreach (ArtistFilter::cases() as $filter) {
            $count = $filter->count($reader);
            $isActive = $active === $filter;

            $tiles[] = [
                'key' => $filter->value,
                'count' => $count,
                'href' => match (true) {
                    $isActive => route('music.artists', absolute: false),
                    $count > 0 => route('music.artists', ['filter' => $filter->value], absolute: false),
                    default => null,
                },
                'active' => $isActive,
            ];
        }

        return [
            'total' => Artist::query()->count(),
            'filters' => $tiles,
        ];
    }
}
