<?php

namespace App\Http\Controllers\Music;

use App\Enums\CollectionType;
use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Track;
use App\Services\DataTableService;
use App\Services\Music\DominantGenre;
use App\Services\Search\FoldedSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One artist's detail page (`GET /music/artists/{artist}`, route
 * `music.artists.show`, behind auth) — the row-click target of the Artists listing,
 * and where the artist tile on a song or album page leads.
 *
 * Sibling to ArtistsController by design, like SongController is to SongsController:
 * same namespace, singular name for the single-record view, so the pair reads like the
 * routes do (`music.artists` / `music.artists.show`).
 *
 * TWO blocks: the hero, holding the artist's name and the same numbers the listing
 * shows plus the dominant genre — and below it their catalogue, split across two tabs.
 *
 * The two tabs are shaped DIFFERENTLY on purpose, because the two sets are different
 * sizes. SONGS is the server-driven DataTable every other listing uses: an artist can
 * have hundreds (406 is the collection's current worst case, and 42 artists are over one
 * page), so it needs real sorting, searching and paging. ALBUMS is a plain discography
 * list with none of that machinery — the collection's biggest discography is 26 and the
 * average is 1.5, so a search box and a pager over a handful of rows would be furniture
 * around nothing.
 *
 * That split also keeps the page's URL coherent. Both panels render at once, and
 * DataTableService reads unprefixed `sort` / `dir` / `page` / `search` — so a second
 * server-driven table here would silently re-sort and re-paginate the first one from the
 * same params. One table on the page means one owner of the query string.
 *
 * BOTH panels are sent on every request, and the `?tab=` param is deliberately IGNORED
 * here even though it reaches us. Answering only the open tab would be the obvious saving
 * and is the wrong trade: the frontend then has to fetch on every tab click, which means a
 * spinner over content the reader can already see, and a page that is slower exactly when
 * they are comparing the two halves. The cost of sending both is one extra count-and-sum
 * query over at most 26 albums (see above), which is not worth a loading state. The param
 * exists purely so a reload or a shared link reopens the right tab — see useTabParam.
 *
 * Sends RAW values like every other controller here: seconds for the playing time, bytes
 * for the size, counts as counts. Formatting happens on the page against the viewer's
 * locale (Utils/formatting.ts).
 */
class ArtistController extends Controller
{
    /**
     * Render one artist. `{artist}` resolves through implicit binding on the UUID, so an
     * unknown id is a 404 before this runs.
     *
     * No type guard here, unlike SongController and AlbumController: those two share the
     * `tracks` / `collections` tables with audiobooks and podcasts, so a bare binding
     * would serve an audiobook chapter under /music/songs/…. The artists table is
     * music-only by construction (the tracks CHECK bars an audiobook from carrying an
     * `artist_id` at all), so there is nothing to exclude.
     */
    public function __invoke(Request $request, Artist $artist): Response
    {
        $totals = $this->trackTotals($artist);
        $genre = $this->dominantGenre($artist);

        return Inertia::render('Music/Artists/Artist/ArtistPage', [
            // The albums tab: every album they are credited with, in one go. No paging
            // because there is nothing to page — see the class docblock.
            'discography' => $this->discography($artist),
            // The songs tab, as the same server-driven payload every listing sends. It owns
            // the page's query params outright, for the reason given in the class docblock.
            'table' => $this->songTable($request, $artist),
            'artist' => [
                'id' => $artist->id,
                'name' => $artist->name,

                // The discography — albums credited to them via
                // `collections.album_artist_id`, the same count and the same meaning as the
                // listing's column (owner's call: an artist's albums are the ones they are
                // credited with, not every album a track of theirs turns up on). Then
                // everything counted over their own tracks.
                'albums' => $artist->albums()->count(),
                'songs' => $totals['songs'],
                'duration' => $totals['duration'],
                'size' => $totals['size'],

                // What this artist mostly IS, tag-wise. Nullable in two ways: an artist
                // with no tracks of their own has no genre to derive one from, and
                // neither does one whose files all left the genre frame empty.
                'genre' => $genre?->genre_name,
                // Where that genre leads — the same server-decided shape SongController
                // uses for `albumUrl`, so the page renders a link when it is handed one and
                // plain text when it is not. Null only when there is no genre to lead to,
                // which is why it is derived from the same row rather than from the artist.
                'genreUrl' => $genre === null
                    ? null
                    : route('music.genres.show', $genre->genre_id, absolute: false),
            ],
        ]);
    }

    /**
     * The artist's discography — every album credited to them, newest first.
     *
     * Unpaginated and unsorted-by-the-reader by design (class docblock): the biggest
     * discography in the collection is 26 albums. So this is a plain array, not a
     * TableResponse, and the page renders it as a list rather than a DataTable — which is
     * also what lets the songs table keep the query string to itself.
     *
     * Credited via `collections.album_artist_id`, the same relation the hero's album count
     * uses, so the tab can never disagree with the number above it about how many albums
     * this artist has.
     *
     * @return array<int, array<string, mixed>>
     */
    private function discography(Artist $artist): array
    {
        return $artist->albums()
            ->where('collections.type', CollectionType::Album)
            ->select(['collections.id', 'collections.name', 'collections.year', 'collections.cover_path'])
            // Raw seconds, like every duration that goes over the wire; the page clocks it.
            ->withCount('tracks')
            ->withSum('tracks', 'duration')
            ->addSelect([
                // Whether ANY of its files carries embedded art — the cover route's fallback
                // when the directory has no image. Selected here so the list costs no
                // filesystem access at all, the same trade AlbumsController makes.
                'embedded_cover_id' => Track::query()
                    ->select('id')
                    ->whereColumn('tracks.collection_id', 'collections.id')
                    ->where('tracks.cover', true)
                    ->limit(1)
                    ->toBase(),
            ])
            // Newest first (owner's call), so a discography opens on the most recent record
            // rather than on whatever came out first.
            //
            // The NULL flag stays ASCENDING while the year reverses, and that is exactly why
            // it is spelled as a CASE rather than left to the engine: undated albums sit at
            // the END in both directions, instead of leading the list the moment the sort
            // flips (Postgres puts NULLs FIRST under DESC). It also makes the two engines
            // agree, where their default NULL placement does not — a difference the SQLite
            // suite would never show. Then name, so the order is total.
            ->orderByRaw('case when collections.year is null then 1 else 0 end')
            ->orderByDesc('collections.year')
            ->orderBy('collections.name')
            ->get()
            ->map(fn (Collection $album): array => [
                'id' => $album->id,
                'name' => $album->name,
                'year' => $album->year,
                'songs' => (int) $album->tracks_count,
                'duration' => $album->tracks_sum_duration === null ? null : (float) $album->tracks_sum_duration,
                'coverUrl' => $album->cover_path !== null || $album->embedded_cover_id !== null
                    ? route('music.albums.cover', $album->id, absolute: false)
                    : null,
                'href' => route('music.albums.show', $album->id, absolute: false),
            ])
            ->all();
    }

    /**
     * The artist's songs, as the server-driven table payload.
     *
     * An explicit query rather than `$artist->tracks()` for the reason AlbumController
     * documents: a HasMany is not a Builder, so FoldedSearch would throw the moment
     * somebody typed in the search box — a failure that only shows up on the search path.
     *
     * No ARTIST column, unlike the album's track table: every row here is by the artist
     * whose page this is, so the column would repeat one name down the whole table. The
     * ALBUM takes its place, and links to it — on this page that is the fact worth having
     * per row, and the one destination that differs from where the row itself goes.
     *
     * @return array<string, mixed>
     */
    private function songTable(Request $request, Artist $artist): array
    {
        $query = Track::query()
            ->where('tracks.artist_id', $artist->id)
            // Scoped to music like everything else in this namespace: a podcast episode may
            // legally carry an `artist_id`, and only audiobooks are barred by the CHECK.
            ->where('tracks.type', TrackType::Music)
            ->leftJoin('collections', 'tracks.collection_id', '=', 'collections.id')
            ->select([
                'tracks.id',
                'tracks.name',
                'tracks.disc',
                'tracks.track',
                'tracks.duration',
                'tracks.size',
                // Decides whether the artwork cell gets a URL or the placeholder, without
                // touching the filesystem.
                'tracks.cover',
                // Where the album CELL links to; off `tracks`, so the join above pays for it.
                'tracks.collection_id',
                'collections.name as album_name',
                'collections.year as album_year',
            ])
            // The year the default sort actually orders by, with "no year" folded to 0 so the
            // column it sorts on is never NULL. That is not tidiness — the default is
            // DESCENDING, and Postgres puts NULLs FIRST under DESC, so without this an artist
            // with one untagged rip would open their songs tab on that rip instead of on their
            // newest record. Exactly the trap GenresController documents for its descending
            // sums, and the same fix: never sort on a nullable expression.
            //
            // 0 rather than a high sentinel because the DEFAULT view is what has to be right:
            // descending, undated material lands last. Flipping to ascending puts it first,
            // which is the honest mirror of "oldest first" and is at least the SAME on both
            // engines — which sorting on the raw column never was (Postgres and SQLite put
            // NULLs at opposite ends in both directions).
            ->selectRaw('coalesce(collections.year, 0) as year_sort')
            // The denominators behind "1/1" and "3/12" — how many discs the row's album has,
            // and how many tracks share the row's disc. Same two definitions SongController
            // computes for its facts card, so a song's own page and this table can never
            // disagree about the "3/12" they both print.
            //
            // As correlated subqueries rather than a per-row lookup: this is a paginated
            // table, so the N+1 SongController can afford for ONE song would be up to a
            // hundred round trips here. Both ride the (collection_id, disc, track) index.
            // Aliased to `sib` because the outer query is over `tracks` too.
            ->addSelect([
                'disc_total' => DB::table('tracks as sib')
                    ->selectRaw('count(distinct sib.disc)')
                    ->whereColumn('sib.collection_id', 'tracks.collection_id'),
                // NULL-safe on purpose: an untagged disc has to group with the other
                // untagged ones, and `sib.disc = tracks.disc` matches nothing when both are
                // NULL — which would report 0 tracks for a whole album's worth of files.
                // Spelled as the explicit OR rather than `IS NOT DISTINCT FROM`, which
                // Postgres has and SQLite does not.
                'track_total' => DB::table('tracks as sib')
                    ->selectRaw('count(*)')
                    ->whereColumn('sib.collection_id', 'tracks.collection_id')
                    ->whereRaw('(sib.disc = tracks.disc or (sib.disc is null and tracks.disc is null))'),
            ]);

        return DataTableService::buildResponse(
            query: $query,
            request: $request,
            sortable: ['name', 'album', 'year', 'disc', 'track', 'duration', 'size'],
            sortColumnMap: [
                'name' => 'tracks.name',
                'album' => 'collections.name',
                // The COALESCEd alias, not the raw column — see the select above. Both
                // Postgres and SQLite resolve a SELECT alias in ORDER BY.
                'year' => 'year_sort',
                'disc' => 'tracks.disc',
                'track' => 'tracks.track',
                'duration' => 'tracks.duration',
                'size' => 'tracks.size',
            ],
            // Newest first, then each album in its own running order — year desc, then album,
            // disc, track (owner's call). It makes the tab read as the catalogue rather than
            // as a bag of songs: the reader lands on the most recent record, and inside any
            // one album gets the sequence it was meant to be heard in. Alphabetical would
            // scatter every album's tracks across the whole table.
            //
            // `year` first rather than `album` because two albums can share a name across a
            // career (a re-recording, a live version) while the pairing with a year is what
            // separates them — and because ordering by album name alone is chronologically
            // arbitrary, which is the thing this order exists to avoid.
            //
            // Only the YEAR reverses. The tiebreakers stay ascending (DataTableService does
            // that on purpose), which is what makes this order readable rather than merely
            // reversed: the albums come newest-first, but track 1 still precedes track 2
            // inside each of them. A wholesale DESC would hand back every album backwards.
            defaultSort: 'year',
            defaultDirection: 'desc',
            // Both text columns on show, matched through their `name_fold` companions so the
            // search is accent- and case-insensitive on one code path for Postgres and
            // SQLite alike (FoldedSearch).
            searchCallback: fn (Builder $q, string $search) => FoldedSearch::apply($q, $search, [
                'tracks.name', 'collections.name',
            ]),
            rowMapper: fn (Track $track): array => [
                'id' => $track->id,
                'name' => $track->name,
                // Position + denominator apart, so the page renders "1/1" and "3/12" — or
                // just the bare number where the total isn't trustworthy (formatPosition).
                // Both totals are null for a track filed under no collection: with no
                // container there is nothing to count against, and a "2/0" would be worse
                // than a blank. The same rule, and the same reason, as SongController's.
                'disc' => $track->disc,
                'discTotal' => $track->collection_id === null ? null : (int) $track->disc_total,
                'track' => $track->track,
                'trackTotal' => $track->collection_id === null ? null : (int) $track->track_total,
                'album' => $track->album_name,
                'year' => $track->album_year,
                // The one cell leading somewhere other than the row's own destination: the
                // row opens the song, this opens the album. The DataTable supports that on
                // purpose — its row-click guard stands down on an anchor. Null for a track
                // filed under no collection, and then the cell is plain text.
                'albumUrl' => $track->collection_id === null
                    ? null
                    : route('music.albums.show', $track->collection_id, absolute: false),
                // Raw seconds and raw bytes; the page formats both against the viewer's
                // locale (Utils/formatting.ts).
                'duration' => $track->duration,
                'size' => $track->size,
                // Offered only when the FILE claims a picture of its own (`tracks.cover`,
                // the scan-time flag), so a long table costs no per-row filesystem access.
                'coverUrl' => $track->cover
                    ? route('music.songs.cover', $track->id, absolute: false)
                    : null,
                // Makes the row clickable, and backs the title link.
                'href' => route('music.songs.show', $track->id, absolute: false),
            ],
            // The rest of the default order — sort KEYS, not columns, mapped like the
            // primary, and handed back so the header can mark all four as sorted rather
            // than pretending only `year` is.
            //
            // `name` last is the determinism backstop every table here carries: a year, an
            // album and a disc/track pair still tie for an untagged rip where disc and track
            // are both null, and SQL guarantees no order at all between rows the sort cannot
            // separate — so without it a row could appear on page 1 AND page 2 across two
            // requests.
            //
            // These three are still nullable and their NULLs are left to the engine, unlike
            // the primary (see `year_sort` above). It matters far less here and the trade is
            // different: they are always ASCENDING, where Postgres puts NULLs LAST — so on
            // production an untagged rip sits at the END of its album, which is the reading
            // we want. SQLite puts them first, so the suite sees the opposite; don't assert
            // where a null disc or track lands. Making these agree too would mean
            // null-ordering in DataTableService, which touches all six tables.
            tiebreakers: ['album', 'disc', 'track', 'name'],
        );
    }

    /**
     * The three numbers counted over the artist's own tracks: how many songs, how long they
     * play, how much disk they take.
     *
     * One aggregate query over the `artist_id` index rather than three, and rather than
     * hydrating every track row to count it — the same reason AlbumController computes
     * its totals in SQL. The sums are COALESCEd so a track-less artist reports 0 rather
     * than null: unlike an album (which cannot exist without files), an artist credited
     * only as an album-artist legitimately has none, and "0:00" beside "3 albums" reads
     * as the fact it is.
     *
     * Scoped to music for the same reason the listing is — a podcast episode may legally
     * carry an `artist_id`.
     *
     * @return array{songs: int, duration: float, size: int}
     */
    private function trackTotals(Artist $artist): array
    {
        $totals = Track::query()
            ->where('artist_id', $artist->id)
            ->where('type', TrackType::Music)
            ->selectRaw('count(*) as songs')
            ->selectRaw('coalesce(sum(duration), 0) as duration_total')
            ->selectRaw('coalesce(sum(size), 0) as size_total')
            ->first();

        return [
            'songs' => (int) $totals?->songs,
            // Aliased away from the model's own `duration` / `size` attribute names and
            // then cast by hand: an aggregate landing on an attribute that HAS a cast
            // gets that cast applied to it, which is a trap worth stepping around rather
            // than relying on (see AlbumController's `modified_at` note for the version
            // of this that actually bit).
            'duration' => (float) $totals?->duration_total,
            'size' => (int) $totals?->size_total,
        ];
    }

    /**
     * The genre most of this artist's songs carry, as a row of `(genre_id, genre_name)`,
     * or null when none of them carry one.
     *
     * A derived fact, not a stored one: MixTape tags genre per TRACK, so an artist has no
     * genre of their own — and plenty of them vary it (a band with one acoustic record, a
     * composer filed half under Score and half under Electronic). Picking the modal genre
     * is what makes the tile a useful summary rather than a coin toss.
     *
     * The rule itself lives in DominantGenre, NOT here, because the Genres listing reads
     * it from the other end — counting the artists each genre is the main genre of. Two
     * implementations would eventually disagree about the same artist, with this page
     * saying "Ambient" while the listing files them under "Jazz"; there is no way for a
     * reader to tell which one is lying. See that service for the tie-break rule and why
     * it is load-bearing.
     *
     * Still one query, and it hands back the genre's NAME as well as its id — so the tile
     * has its label without a second lookup, and the id is ready for the link this tile
     * gets once the genre area has a detail page.
     */
    private function dominantGenre(Artist $artist): ?object
    {
        return DominantGenre::winners($artist->id)->first();
    }
}
