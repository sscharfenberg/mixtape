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
use App\Services\Search\FoldedSearch;
use Illuminate\Database\Eloquent\Builder;
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
 * TWO DIFFERENT RULES decide what belongs to a genre, and the difference is deliberate:
 *
 * - ARTISTS are those whose MAIN genre this is (DominantGenre) — NOT everyone who ever
 *   recorded a song in it. That rule is not a free choice here: the hero's artist count
 *   and the Genres listing's artists column already use it, and a tab listing everyone who
 *   ever touched the genre would contradict the number printed directly above it.
 * - ALBUMS are every album carrying at least one music track of this genre. There is no
 *   prior commitment to contradict, and this is the direct companion to the songs tab:
 *   these are the records those songs come from. A "dominant genre per album" rule would
 *   be tighter, but it would hide exactly the compilation a reader opening a genre is
 *   most likely hunting for.
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
        // Fetched once and counted in PHP rather than counted again in SQL: the hero's
        // number and the tab's list are then the same rows by construction, where two
        // queries could drift the moment one grew a condition the other didn't.
        $artists = $this->mainGenreArtists($genre);

        return Inertia::render('Music/Genres/Genre/GenrePage', [
            'genre' => [
                'id' => $genre->id,
                'name' => $genre->name,

                'artists' => $artists->count(),
                'songs' => $totals['songs'],
                'duration' => $totals['duration'],
                'size' => $totals['size'],
            ],
            // The albums tab — every album holding a track of this genre, in one go.
            'discography' => $this->discography($genre),
            // The artists tab — the same rows the hero counted.
            'artists' => $artists->all(),
            // The songs tab, as the payload that owns the page's query params.
            'table' => $this->songTable($request, $genre),
        ]);
    }

    /**
     * Every album carrying at least one music track of this genre, newest first.
     *
     * `whereExists` rather than a join: an album with twelve tracks of this genre must
     * appear once, and a join would need a DISTINCT to promise that — which would then
     * fight the aggregates below. The subquery asks the only question that matters (is
     * there one?) and stops at the first hit.
     *
     * `tracks_count` deliberately counts ALL the album's tracks, not just this genre's:
     * the Discography component prints it as "N Songs" about the RECORD, and a compilation
     * reading "3 Songs" because only three of its twelve are Blues would be describing the
     * wrong thing. Same for the duration beside it.
     *
     * Unpaginated, like the artist page's — see the component's README for why it is a
     * plain list rather than a table, and for the limit that follows from it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function discography(Genre $genre): array
    {
        return Collection::query()
            ->where('collections.type', CollectionType::Album)
            ->whereExists(
                fn ($query) => $query->from('tracks')
                    ->whereColumn('tracks.collection_id', 'collections.id')
                    ->where('tracks.genre_id', $genre->id)
                    ->where('tracks.type', TrackType::Music)
            )
            ->select(['collections.id', 'collections.name', 'collections.year', 'collections.cover_path'])
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
     * The artists whose MAIN genre this is, A–Z, as rows of `(id, name, href)`.
     *
     * Note where the filter sits: on the OUTER query, after the ranking. Unlike the artist
     * page's filter — which DominantGenre pushes down into the innermost count, because
     * restricting to one artist cannot change who wins — restricting to one GENRE before
     * the ranking would change the answer entirely. An artist who is mostly Jazz and partly
     * Ambient would win Ambient in a query that could only see their Ambient tracks, and
     * this page would claim them. Every genre has to compete for an artist before we ask
     * which genre won.
     *
     * @return SupportCollection<int, array<string, mixed>>
     */
    private function mainGenreArtists(Genre $genre): SupportCollection
    {
        return DB::query()
            ->fromSub(DominantGenre::winners(), 'winners')
            ->join('artists', 'artists.id', '=', 'winners.artist_id')
            ->where('winners.genre_id', $genre->id)
            ->orderBy('artists.name')
            ->select(['artists.id', 'artists.name'])
            ->get()
            ->map(fn (object $artist): array => [
                'id' => $artist->id,
                'name' => $artist->name,
                'href' => route('music.artists.show', $artist->id, absolute: false),
            ]);
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
            ]);

        return DataTableService::buildResponse(
            query: $query,
            request: $request,
            sortable: ['name', 'artist', 'album', 'year', 'duration', 'size'],
            sortColumnMap: [
                'name' => 'tracks.name',
                'artist' => 'artists.name',
                'album' => 'collections.name',
                'year' => 'collections.year',
                'duration' => 'tracks.duration',
                'size' => 'tracks.size',
            ],
            // Alphabetical by song, unlike the artist page's chronological default. A genre
            // is not a career — its songs share no timeline to walk — so the useful default
            // here is the one that makes a remembered title findable.
            defaultSort: 'name',
            // All three text columns, matched through their `name_fold` companions so the
            // search is accent- and case-insensitive on one code path for both engines.
            searchCallback: fn (Builder $q, string $search) => FoldedSearch::apply($q, $search, [
                'tracks.name', 'artists.name', 'collections.name',
            ]),
            rowMapper: fn (Track $track): array => [
                'id' => $track->id,
                'name' => $track->name,
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
            // Song titles repeat across a genre far more than across one artist (every
            // "Summertime" ever recorded is Jazz), so the sort alone is nowhere near a total
            // order — without these a duplicate title could appear on two pages across two
            // requests.
            tiebreakers: ['name', 'artist', 'album'],
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
