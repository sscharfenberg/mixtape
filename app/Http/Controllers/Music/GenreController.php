<?php

namespace App\Http\Controllers\Music;

use App\Enums\CollectionType;
use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Track;
use App\Services\DataTableService;
use App\Services\Music\DominantGenre;
use App\Services\Music\QueuePayload;
use App\Services\Search\FoldedSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One genre's detail page (`GET /music/genres/{genre}`, route `music.genres.show`,
 * behind auth) — the row-click target of the Genres listing, and where the genre tile on
 * an artist's page leads.
 *
 * Sibling to GenresController by design, like the other three pairs in this namespace:
 * same namespace, singular name for the single-record view, so the pair reads like the
 * routes do (`music.genres` / `music.genres.show`).
 *
 * TWO blocks, the same shape the artist page has: the hero with the genre's name and its
 * listing-row numbers, and below it the genre's contents across an ALBUMS, an ARTISTS and
 * a SONGS tab. Only SONGS is a server-driven DataTable — DataTableService reads
 * `sort` / `dir` / `page` / `search` UNPREFIXED and every panel renders at once, so a
 * second one would re-sort and re-paginate the first from the same params. All three
 * panels are sent on every request and `?tab=` is ignored here, so switching tabs costs no
 * request and raises no loading state over content already on screen (see useTabParam).
 *
 * ONE RULE decides what belongs to a genre, for both the artists and the albums tab: the
 * thing's MAIN genre is this one (DominantGenre). Not everyone who ever recorded a song in
 * it, and not every album that happens to hold one.
 *
 * The artists half was never a free choice — the hero's artist count and the Genres
 * listing's column already use that rule, and a tab listing everyone who ever touched the
 * genre would contradict the number printed directly above it.
 *
 * The albums half started as the looser "holds at least one track of this genre", on the
 * grounds that it would surface compilations. It surfaced them far too well: a twenty-track
 * Bundesvision compilation carrying fifteen Pop songs and one each of five other genres
 * appeared in the album tab of all six. One incidental track is not what a reader browsing
 * Power Metal is looking for, so an album now belongs where most of it belongs.
 *
 * The SONGS tab keeps the literal reading — every track tagged with the genre, wherever it
 * lives — because that is a question about tracks, not about what a record is. So the
 * compilation's one Power Metal song still appears under Power Metal; only the album stops
 * claiming to be one.
 *
 * Sends RAW values like every other controller here: seconds for the playing time, bytes
 * for the size, counts as counts (Utils/formatting.ts does the rest).
 */
class GenreController extends Controller
{
    /**
     * Render one genre. `{genre}` resolves through implicit binding on the UUID, so an
     * unknown id is a 404 before this runs.
     *
     * No type guard, unlike SongController and AlbumController: those two share the
     * `tracks` / `collections` tables with audiobooks and podcasts, so a bare binding
     * would serve an audiobook chapter under /music/songs/…. A genre row is not a
     * container of anything — it is a name other rows point at — so there is nothing to
     * exclude here. What IS scoped is every number below, to music tracks.
     */
    public function __invoke(Request $request, Genre $genre): Response
    {
        $totals = $this->trackTotals($genre);
        // Fetched ONCE and used three times: the hero's album count, the albums tab, and
        // each artist card's album count and cover fan. Three readings of "this genre's
        // albums" that must agree, and the cheapest way to guarantee they do is for them to
        // be the same rows rather than three queries carrying the same condition.
        $albums = $this->mainGenreAlbums($genre);
        $discography = $this->discographyPayload($albums);
        // Fetched once and counted in PHP rather than counted again in SQL: the hero's
        // number and the tab's list are then the same rows by construction, where two
        // queries could drift the moment one grew a condition the other didn't.
        $artists = $this->mainGenreArtists($genre, $albums);

        return Inertia::render('Music/Genres/Genre/GenrePage', [
            // The whole subject as queue entries, for the hero menu's Play / Enqueue.
            // OPTIONAL: never sent with the page, only when the menu asks for it by name
            // (`router.reload({ only: ["queueTracks"] })`). The songs table here is
            // paginated, so "play this" means every track and not the 25 on screen — which
            // is a payload worth a few hundred kilobytes on a big subject and worth nothing
            // at all to a visit that is just browsing. See App\Services\Music\QueuePayload.
            'queueTracks' => Inertia::optional(
                fn (): array => QueuePayload::fromQuery(QueuePayload::query()->where('tracks.genre_id', $genre->id))
            ),
            'genre' => [
                'id' => $genre->id,
                'name' => $genre->name,

                'artists' => $artists->count(),
                'albums' => $albums->count(),
                'songs' => $totals['songs'],
                'duration' => $totals['duration'],
                'size' => $totals['size'],
            ],
            // The albums tab — every album whose main genre this is, in one go.
            'discography' => $discography,
            // The artists tab — the same rows the hero counted, each with the numbers that
            // describe it WITHIN this genre and a handful of its covers to fan out.
            'artists' => $artists->all(),
            // The songs tab, as the payload that owns the page's query params.
            'table' => $this->songTable($request, $genre),
        ]);
    }

    /**
     * Every album whose MAIN genre is this one, newest first.
     *
     * Note where the filter sits: on the OUTER query, after the ranking — the same trap
     * mainGenreArtists documents. Restricting to one genre BEFORE the ranking would change
     * the answer entirely, since an album that is mostly Pop would win Power Metal in a
     * query that could only see its Power Metal track. Every genre has to compete for an
     * album before we ask which genre won.
     *
     * `whereIn` against that subquery rather than a join: an album must appear once, and a
     * join to the ranked set would need a DISTINCT to promise it — which would then fight
     * the aggregates below.
     *
     * `tracks_count` deliberately counts ALL the album's tracks, not just this genre's:
     * the Discography component prints it as "N Songs" about the RECORD, and a compilation
     * reading "3 Songs" because only three of its twelve are Blues would be describing the
     * wrong thing. Same for the duration beside it.
     *
     * Unpaginated, like the artist page's — see the component's README for why it is a
     * plain list rather than a table, and for the limit that follows from it.
     *
     * Returns the MODELS rather than the page payload, because three separate things need
     * these rows: the albums tab, the hero's album count, and each artist card (its album
     * count and the covers it fans out). Mapping to the payload is discographyPayload's job.
     *
     * @return EloquentCollection<int, Collection>
     */
    private function mainGenreAlbums(Genre $genre): EloquentCollection
    {
        return Collection::query()
            ->where('collections.type', CollectionType::Album)
            ->whereIn('collections.id', DB::query()
                ->fromSub(DominantGenre::albumWinners(), 'album_genre')
                ->where('album_genre.genre_id', $genre->id)
                ->select('album_genre.collection_id'))
            // The album-artist comes along because a genre's albums are by different people,
            // and the name is what tells one tile from the next (the artist page's own
            // discography needs no such column — see the component's `showArtist`). LEFT, so
            // a compilation filed under no album-artist still lists; the tile drops the chip.
            ->leftJoin('artists', 'collections.album_artist_id', '=', 'artists.id')
            ->select([
                'collections.id',
                'collections.name',
                'collections.year',
                'collections.cover_path',
                // Not sent to the client — it is what lets the artist cards group these same
                // rows by the artist they belong to, without a second query.
                'collections.album_artist_id',
                'artists.name as artist_name',
            ])
            ->withCount('tracks')
            ->withSum('tracks', 'duration')
            ->addSelect([
                // Whether any file carries embedded art — the cover route's fallback when
                // the directory has no image. Selected here so the list costs no filesystem
                // access at all.
                'embedded_cover_id' => Track::query()
                    ->select('id')
                    ->whereColumn('tracks.collection_id', 'collections.id')
                    ->where('tracks.cover', true)
                    ->limit(1)
                    ->toBase(),
            ])
            // Newest first, like the artist page's. The NULL flag stays ASCENDING while the
            // year reverses, which is what keeps undated albums at the END in both directions
            // rather than leading the list the moment the sort flips (Postgres puts NULLs
            // first under DESC). It also makes the two engines answer the same, where their
            // default NULL placement does not — a difference the SQLite suite would never
            // show.
            ->orderByRaw('case when collections.year is null then 1 else 0 end')
            ->orderByDesc('collections.year')
            ->orderBy('collections.name')
            ->get();
    }

    /**
     * The albums tab's payload — the rows above in the shape Discography.vue reads.
     *
     * `album_artist_id` is deliberately dropped here: it earns its place in the query
     * because the artist cards group by it, but the component has no use for it and an
     * unused key in a payload is a claim the page does not make.
     *
     * @param  EloquentCollection<int, Collection>  $albums
     * @return array<int, array<string, mixed>>
     */
    private function discographyPayload(EloquentCollection $albums): array
    {
        return $albums
            ->map(fn (Collection $album): array => [
                'id' => $album->id,
                'name' => $album->name,
                'artist' => $album->artist_name,
                'year' => $album->year,
                'songs' => (int) $album->tracks_count,
                'duration' => $album->tracks_sum_duration === null ? null : (float) $album->tracks_sum_duration,
                'coverUrl' => $this->coverUrl($album),
                'href' => route('music.albums.show', $album->id, absolute: false),
            ])
            ->all();
    }

    /**
     * Where an album's artwork is served from, or null when it has none.
     *
     * One definition, because two readings of "does this album have a picture" — the tab's
     * and the artist card's — drifting apart would show a cover in one place and a
     * placeholder in the other for the same record. Costs no filesystem access: both flags
     * come off the query above.
     */
    private function coverUrl(Collection $album): ?string
    {
        return $album->cover_path !== null || $album->embedded_cover_id !== null
            ? route('music.albums.cover', $album->id, absolute: false)
            : null;
    }

    /**
     * The artists whose MAIN genre this is — busiest first, then alphabetically.
     *
     * Note where the filter sits: on the OUTER query, after the ranking. Unlike the artist
     * page's filter — which DominantGenre pushes down into the innermost count, because
     * restricting to one artist cannot change who wins — restricting to one GENRE before
     * the ranking would change the answer entirely. An artist who is mostly Jazz and partly
     * Ambient would win Ambient in a query that could only see their Ambient tracks, and
     * this page would claim them. Every genre has to compete for an artist before we ask
     * which genre won.
     *
     * Every number on the card is scoped to THIS GENRE, not to the artist's whole
     * catalogue (owner's call): the songs are their songs tagged with it, the albums are
     * their albums it won. On a genre page that is the honest reading — a card claiming an
     * artist's full discography while sitting under one genre would describe something the
     * page is not about. The two numbers therefore come from different places, and have to:
     * a song belongs to a genre by its own tag, an album by which genre most of it is.
     *
     * @param  EloquentCollection<int, Collection>  $albums  this genre's albums, already fetched
     * @return SupportCollection<int, array<string, mixed>>
     */
    private function mainGenreArtists(Genre $genre, EloquentCollection $albums): SupportCollection
    {
        // The same rows the albums tab shows, indexed by whose they are. A compilation
        // filed under no album-artist groups under an empty key and matches no artist,
        // which is right: nobody's card should claim it.
        $albumsByArtist = $albums->groupBy('album_artist_id');

        return DB::query()
            ->fromSub(DominantGenre::winners(), 'winners')
            ->join('artists', 'artists.id', '=', 'winners.artist_id')
            // Joined rather than looked up per card, so the ORDER BY below can see the
            // count — and so the names sort under the DATABASE's collation. Sorting them in
            // PHP afterwards would order umlauts and accents by byte value, which is not
            // where a German reader expects to find "Ärzte".
            ->leftJoinSub($this->artistSongTotals($genre), 'totals', 'totals.artist_id', '=', 'artists.id')
            ->where('winners.genre_id', $genre->id)
            /*
             * Biggest contributors first, then alphabetically (owner's call). The tab answers
             * "who makes this genre" before "who is in it", and on a genre with a hundred
             * artists an A–Z list buries the five that account for most of it behind ninety
             * that have one track from a compilation.
             *
             * COALESCEd, and not for tidiness: an artist wins a genre on their track counts
             * across ALL types, so one whose only tracks here are podcast episodes joins to
             * no row — and Postgres sorts NULLs FIRST under DESC, which would open the tab
             * with exactly the artists who have nothing in it.
             */
            ->orderByRaw('coalesce(totals.songs, 0) desc')
            ->orderBy('artists.name')
            ->select(['artists.id', 'artists.name', 'totals.songs', 'totals.duration_total'])
            ->get()
            ->map(function (object $artist) use ($albumsByArtist): array {
                $own = $albumsByArtist->get($artist->id) ?? new EloquentCollection;

                return [
                    'id' => $artist->id,
                    'name' => $artist->name,
                    'songs' => (int) ($artist->songs ?? 0),
                    'albums' => $own->count(),
                    // Raw seconds, formatted by the page against the viewer's locale.
                    'duration' => (float) ($artist->duration_total ?? 0),
                    'covers' => $this->fannedCovers($own),
                    'href' => route('music.artists.show', $artist->id, absolute: false),
                ];
            });
    }

    /**
     * Up to three of an artist's covers, picked at RANDOM (owner's call) from the albums
     * they have in this genre.
     *
     * Random per request, which is a real trade and worth naming: the fan is different on
     * every visit, and there is deliberately nothing to cache. It is a decorative flourish
     * whose only job is to look like a stack of records, so a stable pick would buy
     * cacheability the page does not otherwise need — and re-shuffling is the point.
     *
     * Albums with no artwork are dropped rather than fanned as placeholders: a card showing
     * two sleeves and a grey square reads as a rendering fault, where two sleeves reads as
     * an artist with two records. An artist whose albums ALL lack artwork yields an empty
     * list, and the card falls back to a single placeholder — see GenreArtists.vue, which
     * owns how one, two or three covers are laid out.
     *
     * @param  EloquentCollection<int, Collection>  $albums  one artist's albums in this genre
     * @return array<int, string>
     */
    private function fannedCovers(EloquentCollection $albums): array
    {
        return $albums
            ->map(fn (Collection $album): ?string => $this->coverUrl($album))
            ->filter()
            ->shuffle()
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * How many songs each artist has IN THIS GENRE, and how long they run — as a QUERY, for
     * the caller to join against.
     *
     * One grouped aggregate rather than a count per card: the biggest genre here carries
     * getting on for a hundred artists, so a per-artist query would be a hundred round trips
     * for two numbers. Returned unexecuted so the join can order by `songs`, which a PHP-side
     * lookup could not.
     *
     * Aliased away from the model's own `duration` attribute, for the reason trackTotals
     * spells out below: an aggregate landing on an attribute that HAS a cast gets that cast
     * applied to it.
     */
    private function artistSongTotals(Genre $genre): QueryBuilder
    {
        return Track::query()
            ->where('tracks.genre_id', $genre->id)
            ->where('tracks.type', TrackType::Music)
            ->whereNotNull('tracks.artist_id')
            ->groupBy('tracks.artist_id')
            ->selectRaw('tracks.artist_id as artist_id')
            ->selectRaw('count(*) as songs')
            ->selectRaw('coalesce(sum(tracks.duration), 0) as duration_total')
            ->toBase();
    }

    /**
     * The genre's songs, as the server-driven table payload.
     *
     * An explicit query rather than a relation, for the reason AlbumController documents:
     * FoldedSearch takes a Builder, so a HasMany would throw the moment somebody typed in
     * the search box — a failure that only shows up on the search path.
     *
     * No GENRE column, for the same reason the artist page's table has no artist one:
     * every row here carries the genre whose page this is. ARTIST and ALBUM take that
     * space instead, and both link out — on a genre page those are the two facts that
     * tell one row from the next.
     *
     * @return array<string, mixed>
     */
    private function songTable(Request $request, Genre $genre): array
    {
        $query = Track::query()
            ->where('tracks.genre_id', $genre->id)
            // Scoped to music like everything else here: a podcast episode may legally
            // carry a `genre_id` (only audiobooks are barred, by the tracks CHECK).
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
                // Decides whether the artwork cell gets a URL, without touching the disk.
                'tracks.cover',
                // Where the artist / album cells link to; both off `tracks`, so the joins
                // above pay for them.
                'tracks.artist_id',
                'tracks.collection_id',
                'artists.name as artist_name',
                'collections.name as album_name',
                'collections.year as album_year',
            ])
            // The year the default sort actually orders by, with "no year" folded to 0 so the
            // column it sorts on is never NULL. Not tidiness — the default is DESCENDING and
            // Postgres puts NULLs FIRST under DESC, so without this a genre would open on
            // whichever of its songs came off an untagged rip. Same fix, same reason, as the
            // artist page's songs table.
            ->selectRaw('coalesce(collections.year, 0) as year_sort')
            // The denominators behind "1/1" and "3/12" — how many discs the row's album has,
            // and how many tracks share the row's disc. The same two definitions
            // SongController computes for its facts card, so a song's own page and this table
            // can never disagree. Correlated subqueries rather than a per-row lookup: this
            // table is paginated, so an N+1 would be up to a hundred round trips. Aliased to
            // `sib` because the outer query is over `tracks` too.
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
            sortable: ['name', 'artist', 'album', 'year', 'disc', 'track', 'duration', 'size'],
            sortColumnMap: [
                'name' => 'tracks.name',
                'artist' => 'artists.name',
                'album' => 'collections.name',
                // The COALESCEd alias, not the raw column — see the select above. Both
                // engines resolve a SELECT alias in ORDER BY.
                'year' => 'year_sort',
                'disc' => 'tracks.disc',
                'track' => 'tracks.track',
                'duration' => 'tracks.duration',
                'size' => 'tracks.size',
            ],
            // Newest first, then each record in its own running order (owner's call), so the
            // tab reads as the genre's recent history rather than as a bag of songs.
            //
            // Only the YEAR reverses; the tiebreakers below stay ascending, which is what
            // makes it readable rather than merely backwards — track 1 still precedes track 2
            // inside each album.
            defaultSort: 'year',
            defaultDirection: 'desc',
            // All three text columns, matched through their `name_fold` companions so the
            // search is accent- and case-insensitive on one code path for both engines.
            searchCallback: fn (Builder $q, string $search) => FoldedSearch::apply($q, $search, [
                'tracks.name', 'artists.name', 'collections.name',
            ]),
            rowMapper: fn (Track $track): array => [
                'id' => $track->id,
                'name' => $track->name,
                // Position + denominator apart, so the page renders "1/1" and "3/12" — or the
                // bare number where the total is not trustworthy (formatPosition). Both are
                // null for a track filed under no collection: with no container there is
                // nothing to count against, and "2/0" would be worse than a blank.
                'disc' => $track->disc,
                'discTotal' => $track->collection_id === null ? null : (int) $track->disc_total,
                'track' => $track->track,
                'trackTotal' => $track->collection_id === null ? null : (int) $track->track_total,
                'artist' => $track->artist_name,
                // Two cells leading somewhere other than the row's own destination, which
                // the DataTable supports on purpose (its row-click guard stands down on an
                // anchor). Null for a track crediting nobody / filed under no collection,
                // and then the cell is plain text.
                'artistUrl' => $track->artist_id === null
                    ? null
                    : route('music.artists.show', $track->artist_id, absolute: false),
                'album' => $track->album_name,
                'albumUrl' => $track->collection_id === null
                    ? null
                    : route('music.albums.show', $track->collection_id, absolute: false),
                'year' => $track->album_year,
                // Raw seconds and raw bytes; the page formats both against the viewer's
                // locale (Utils/formatting.ts).
                'duration' => $track->duration,
                'size' => $track->size,
                'coverUrl' => $track->cover
                    ? route('music.songs.cover', $track->id, absolute: false)
                    : null,
                // Makes the row clickable, and backs the title link.
                'href' => route('music.songs.show', $track->id, absolute: false),
            ],
            // ALBUM sits between the year and the disc, though it is not one of the three
            // keys the order is described by. It has to: a genre routinely holds several
            // records from the same year, and without it their tracks interleave — disc 1
            // track 1 of one album, then disc 1 track 1 of the next — which reads as a
            // shuffled table rather than a sorted one.
            //
            // `name` last is the determinism backstop. Song titles repeat across a genre far
            // more than across one artist (every "Summertime" ever recorded is Jazz), and an
            // untagged rip ties on disc and track as well, so without it a duplicate could
            // appear on two pages across two requests.
            tiebreakers: ['album', 'disc', 'track', 'name'],
        );
    }

    /**
     * The three numbers counted over the genre's own tracks: how many songs, how long they
     * play, how much disk they take.
     *
     * One aggregate query rather than three, and rather than hydrating every track row to
     * count it — the same reason the album and artist pages compute their totals in SQL.
     * The sums are COALESCEd so a genre whose tracks were all pruned reports 0 rather than
     * null, matching what the listing sends for the same genre.
     *
     * @return array{songs: int, duration: float, size: int}
     */
    private function trackTotals(Genre $genre): array
    {
        $totals = Track::query()
            ->where('genre_id', $genre->id)
            ->where('type', TrackType::Music)
            ->selectRaw('count(*) as songs')
            ->selectRaw('coalesce(sum(duration), 0) as duration_total')
            ->selectRaw('coalesce(sum(size), 0) as size_total')
            ->first();

        return [
            'songs' => (int) $totals?->songs,
            // Aliased away from the model's own `duration` / `size` attribute names and
            // cast by hand, for the reason ArtistController spells out: an aggregate
            // landing on an attribute that HAS a cast gets that cast applied to it.
            'duration' => (float) $totals?->duration_total,
            'size' => (int) $totals?->size_total,
        ];
    }
}
