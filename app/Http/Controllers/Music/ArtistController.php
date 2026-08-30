<?php

namespace App\Http\Controllers\Music;

use App\Enums\CollectionType;
use App\Enums\PlaylistSubject;
use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Track;
use App\Services\DataTableService;
use App\Services\Music\DominantGenre;
use App\Services\Music\FannedCovers;
use App\Services\Music\QueuePayload;
use App\Services\Player\PlayCounts;
use App\Services\Playlists\PlaylistAdditions;
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
     * `tracks` / `collections` tables with audiobooks, so a bare binding
     * would serve an audiobook chapter under /music/songs/…. The artists table is
     * music-only by construction (the tracks CHECK bars an audiobook from carrying an
     * `artist_id` at all), so there is nothing to exclude.
     */
    public function __invoke(Request $request, Artist $artist): Response
    {
        $totals = $this->trackTotals($artist);
        $genre = $this->dominantGenre($artist);
        // Built once and read twice — the albums tab, and the hero's fan of a few of their
        // sleeves. Two readings of "this artist's records" that must agree, and the cheapest
        // way to guarantee they do is for them to be the same rows.
        $discography = $this->discography($artist);

        return Inertia::render('Music/Artists/Artist/ArtistPage', [
            // The whole subject as queue entries, for the hero menu's Play / Enqueue.
            // OPTIONAL: never sent with the page, only when the menu asks for it by name
            // (`router.reload({ only: ["queueTracks"] })`). The songs table here is
            // paginated, so "play this" means every track and not the 25 on screen — which
            // is a payload worth a few hundred kilobytes on a big subject and worth nothing
            // at all to a visit that is just browsing. See App\Services\Music\QueuePayload.
            'queueTracks' => Inertia::optional(
                fn (): array => QueuePayload::fromQuery(
                    PlaylistSubject::Artist->apply(QueuePayload::query(), [$artist->id])
                )
            ),
            // Which of the reader's playlists the hero's "add to playlist" may offer: the ids
            // of those that do not already hold EVERY one of this artist's tracks. Ids only —
            // the names and the reader's ordering are the shared `playlists` prop.
            // SongController's copy carries the full reasoning.
            'addablePlaylists' => fn (): array => PlaylistAdditions::openTo(
                $request->user(), PlaylistSubject::Artist, $artist->id
            ),
            // The albums tab: every album they are credited with, in one go. No paging
            // because there is nothing to page — see the class docblock.
            'discography' => $discography,
            // Up to three of their covers for the hero's fanned sleeves, standing in for the
            // artist photograph MixTape does not store. Keyed by ALBUM id, so the fan is three
            // different records; everything else about the pick belongs to the service.
            'covers' => FannedCovers::pick(
                array_map(fn (array $album): array => [$album['id'], $album['coverUrl']], $discography)
            ),
            // The songs tab, as the same server-driven payload every listing sends. It owns
            // the page's query params outright, for the reason given in the class docblock.
            'table' => $this->songTable($request, $artist),
            // How much of this artist has been listened to — the reader's own listens and
            // everybody else's, as listening EVENTS (App\Services\Player\PlayCounts explains
            // why a subject counts by track and a single song counts by content hash).
            //
            // ITS OWN PROP, not a member of `artist`, and that is load-bearing rather than
            // tidy: the player refreshes this number in place when a track finishes, through
            // a partial reload naming exactly this key. Folded into `artist` it would drag
            // the whole hero — name, genre, every total — back over the wire to move one
            // figure.
            'plays' => PlayCounts::forArtist($artist, $request->user()),
            'artist' => [
                'id' => $artist->id,
                'name' => $artist->name,

                // The discography — albums credited to them via
                // `collections.album_artist_id`, the same count and the same meaning as the
                // listing's column (owner's call: an artist's albums are the ones they are
                // credited with, not every album a track of theirs turns up on). Then
                // everything counted over their CREDITED tracks — the union the songs tab
                // below lists, so the number and the table under it cannot disagree
                // (App\Services\Music\ArtistCredits).
                'albums' => $artist->albums()->count(),
                'songs' => $totals['songs'],
                'duration' => $totals['duration'],
                'size' => $totals['size'],

                // Whether the songs tab should carry an ARTIST column at all — true only when
                // something credited to this artist is performed by somebody else. Decided here
                // rather than per page of rows, so the column cannot appear and vanish as a
                // reader pages or sorts; and decided at all because on 612 of this library's 641
                // artists the column could only repeat the name at the top of the page, which is
                // the same "a tile that can only read 0 is worse than no tile" rule the browse
                // strips are built on (docs/browse-stats.md).
                'hasGuestCredits' => $this->hasGuestCredits($artist),

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
     * THE ROWS ARE THE CREDIT UNION, not `tracks.artist_id` — everything they perform plus
     * everything on a record credited to them (App\Services\Music\ArtistCredits), which is
     * what puts "Bring Me The Horizon feat. BABYMETAL" on Bring Me The Horizon's page.
     *
     * THE ARTIST COLUMN IS CONDITIONAL, and the condition is computed for the whole
     * catalogue rather than per page ({@see hasGuestCredits}): a table always carries the
     * column's data, and the PAGE decides whether to draw it. Send it unconditionally and it
     * repeats the hero's name down every row for the 612 artists whose catalogue is entirely
     * their own; leave it out and a compilation owner's 256 rows name nobody at all. Its
     * link goes to the performer's page, which is a third destination — the row opens the
     * song, the album cell opens the album.
     *
     * @return array<string, mixed>
     */
    private function songTable(Request $request, Artist $artist): array
    {
        $query = PlaylistSubject::Artist->apply(Track::query(), [$artist->id])
            // Scoped to music like everything else in this namespace: an audiobook chapter may
            // legally carry an `artist_id`, and only audiobooks are barred by the CHECK.
            ->where('tracks.type', TrackType::Music)
            ->leftJoin('artists', 'tracks.artist_id', '=', 'artists.id')
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
                // Where the album and artist CELLS link to; both off `tracks`, so the joins
                // above pay for them.
                'tracks.collection_id',
                'tracks.artist_id',
                'artists.name as artist_name',
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
            sortable: ['name', 'artist', 'album', 'year', 'disc', 'track', 'duration', 'size'],
            sortColumnMap: [
                'name' => 'tracks.name',
                'artist' => 'artists.name',
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
            // Every text column the table can show, matched through their `name_fold`
            // companions so the search is accent- and case-insensitive on one code path for
            // Postgres and SQLite alike (FoldedSearch). The performer is searched even where
            // the column is hidden: it costs nothing on a page whose rows all name the artist
            // in the hero, and hiding it from the search on the pages that DO show it would be
            // the one place a visible column could not be searched.
            searchCallback: fn (Builder $q, string $search) => FoldedSearch::apply($q, $search, [
                'tracks.name', 'artists.name', 'collections.name',
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
                // Who actually performs it — normally the artist whose page this is, and
                // worth a column only when it is sometimes not (see the docblock). Null for a
                // file carrying no artist tag at all.
                'artist' => $track->artist_name,
                'artistUrl' => $track->artist_id === null
                    ? null
                    : route('music.artists.show', $track->artist_id, absolute: false),
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
     * The three numbers counted over everything credited to the artist: how many songs, how
     * long they play, how much disk they take.
     *
     * CREDITED, not performed — the same union the songs tab lists and the hero's Play
     * button queues (App\Services\Music\ArtistCredits), because these three sit directly
     * above that table and a hero reading "34 Songs" over a pager saying "1-25 of 64" is a
     * wrong number rather than a different question.
     *
     * One aggregate query rather than three, and rather than hydrating every track row to
     * count it — the same reason AlbumController computes its totals in SQL. The sums are
     * COALESCEd so an artist with nothing reports 0 rather than null; that is rarer than it
     * was (an album-artist now owns their record's tracks) but still reachable, by an artist
     * whose only credit is an audiobook the music scope excludes.
     *
     * Scoped to music for the same reason the listing is — an audiobook chapter may legally
     * carry an `artist_id`.
     *
     * @return array{songs: int, duration: float, size: int}
     */
    private function trackTotals(Artist $artist): array
    {
        $totals = PlaylistSubject::Artist->apply(Track::query(), [$artist->id])
            ->where('tracks.type', TrackType::Music)
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
     * Whether anything credited to this artist is performed by somebody ELSE — which is what
     * decides if the songs tab shows an ARTIST column at all.
     *
     * True for exactly the two shapes the credit union exists for: a feature credit on their
     * own record ("Bring Me The Horizon feat. BABYMETAL" on a Bring Me The Horizon album),
     * and a compilation owner whose files all name the individual performers ("Various
     * Artists", "Irish Folk Festival"). False for the 612 artists here whose catalogue is
     * entirely their own, where the column could only repeat the name in the hero.
     *
     * An EXISTS rather than a count: the page asks a yes/no, and the planner can stop at the
     * first row. It compares against the artist's own id rather than against `album_artist_id`
     * so a NULL performer — a file with no artist tag on their record — counts as a guest
     * credit too, which is the honest reading: the column then shows the blank, and a reader
     * can see that the row is not credited to anyone.
     */
    private function hasGuestCredits(Artist $artist): bool
    {
        return PlaylistSubject::Artist->apply(Track::query(), [$artist->id])
            ->where('tracks.type', TrackType::Music)
            ->where(fn (Builder $guest) => $guest
                ->whereNull('tracks.artist_id')
                ->orWhere('tracks.artist_id', '!=', $artist->id)
            )
            ->exists();
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
