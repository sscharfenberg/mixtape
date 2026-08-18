<?php

namespace App\Http\Controllers;

use App\Enums\CollectionType;
use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Track;
use App\Models\User;
use App\Services\Library\LibraryStats;
use App\Services\Music\DominantGenre;
use App\Services\Player\PlayCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Music area (`GET /music`, route `music`, behind auth) — the browse view
 * for the music collection. Renders four widgets' worth of data as Inertia
 * props; each widget gets all three modes (latest, popular, random), four
 * entries each, so its header toggle flips between them client-side without a
 * round trip. Eager rather than `Inertia::defer`, because four entries per set is
 * a tiny payload and deferring it would trade one round trip for four — the trade
 * only turns the other way on a collection large enough that the queries
 * themselves are the cost.
 */
class MusicController extends Controller
{
    /** How many entries each widget shows. */
    private const LIMIT = 4;

    /**
     * Render the Music browse page. Every widget gets all three modes, and `popular` means one
     * thing in all four: what THIS READER has actually played, most listens first — see
     * {@see mostPlayed}, which every widget's popular branch is. `stats` carries the
     * collection totals for the stats widget.
     */
    public function __invoke(Request $request): Response
    {
        // Read once and closed over rather than reached for inside each query: every widget
        // carries a play pip, and that count is the reader's own — the only per-viewer number
        // on this page.
        $reader = $request->user();

        // Each widget's data is a closure so a partial reload (the footer's
        // refresh button → router.reload({ only: ['artists'] })) re-runs ONLY
        // that widget's query — reshuffling its `random` — instead of all four.
        // Full page loads still evaluate every closure.
        return Inertia::render('Music/MusicPage', [
            'albums' => fn () => $this->modes(fn (string $mode) => $this->albums($mode, $reader)),
            'artists' => fn () => $this->modes(fn (string $mode) => $this->artists($mode, $reader)),
            'genres' => fn () => $this->modes(fn (string $mode) => $this->genres($mode, $reader)),
            'songs' => fn () => $this->modes(fn (string $mode) => $this->songs($mode, $reader)),
            'stats' => fn (): array => LibraryStats::music(),
        ]);
    }

    /**
     * Wrap a per-mode query into the keyed shape the widgets expect, calling it once per mode.
     *
     * Three fixed modes rather than a per-widget list, because every card offers all three and
     * `popular` means the same thing on each of them ({@see mostPlayed}) — there is nothing
     * left for this method to decide. What differs per widget is which grouped set that shape
     * is handed, and each query method below decides its own.
     *
     * @param  callable(string): array<int, array<string, mixed>>  $query
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function modes(callable $query): array
    {
        return [
            'latest' => $query('latest'),
            'popular' => $query('popular'),
            'random' => $query('random'),
        ];
    }

    /**
     * Narrow a widget's query to what THIS READER has played, most listens first — the one
     * shape of `popular` on this page, and the whole of it.
     *
     * IT IS A FILTER AS MUCH AS AN ORDER. The join is INNER, and the grouped set holds a row
     * only for something this reader has listened to, so nothing unplayed can appear in a
     * most-played list. An empty answer therefore says one thing and says it truthfully —
     * nothing has been played yet — which each card renders as "not enough data" rather than
     * as the generic "nothing here".
     *
     * IT COUNTS THE READER, not the household. Ranking everybody's listens reads as a shared
     * "what gets played here" set — a defensible thing to want, and wrong beside a pip. Every
     * card carries the reader's own count, so a household ranking could put a row showing "1×"
     * above one showing "5×" with nothing on screen to explain the order, and an order that
     * contradicts the number printed next to it is read as a bug.
     *
     * THERE IS NO SECOND SORT KEY, and total file duration is the one that keeps suggesting
     * itself. It ranks the biggest shelf rather than the best-loved thing, which is not what
     * the word says; and next to a visible play pip it puts unplayed rows above played ones,
     * an order the numbers on screen contradict. A card with nothing to rank should say so.
     *
     * GROUPED rather than the correlated count each widget selects for its pip, per the
     * measurement in PlayCounts::ownCountForArtist: this is the branch that SORTS by the
     * number, so every row has to be counted before the limit can apply. The correlated shape
     * would re-probe `plays` once per candidate row to do it.
     *
     * THE JOIN KEY DOUBLES AS A TOTAL TIE-BREAK, because it is the primary key. Equal counts
     * are the normal case on a young `plays` table and the card's refresh button re-runs this
     * query, so under `LIMIT 4` a partial order can answer with a different four each press —
     * which reads as the random mode leaking into this one. SearchRanking owes its tie-break
     * to the same trap.
     *
     * @param  QueryBuilder  $played  a grouped `subject_id` / `plays` set from PlayCounts
     * @param  string  $key  the outer table's primary key, e.g. `artists.id`
     */
    private function mostPlayed(Builder $query, QueryBuilder $played, string $key): Builder
    {
        return $query
            ->joinSub($played, 'popularity', 'popularity.subject_id', '=', $key)
            ->orderByDesc('popularity.plays')
            ->orderBy($key);
    }

    /**
     * Four music albums. `latest` orders by the album's newest track's file mtime
     * (`modified_at`) — a collection row has no file date of its own, and mtime is
     * the true "recently added" after a bulk import; `random` shuffles; `popular` is THE
     * READER'S OWN listens, over the albums they have actually played.
     *
     * Each row also carries the reader's OWN listens to it, as the card's play pip.
     *
     * @return array<int, array{id: string, name: string, artist: ?string, year: ?int, plays: int, href: string}>
     */
    private function albums(string $mode, ?User $reader): array
    {
        return Collection::query()
            ->where('type', CollectionType::Album)
            ->with('albumArtist:id,name')
            // CORRELATED, not the grouped subquery the albums LISTING uses, and the choice is
            // measured — see PlayCounts::ownCountForArtist. No mode sorts by THIS select
            // (`popular` orders on the join it adds below), so the engine evaluates it for the
            // four rows that survive the limit.
            ->addSelect(['plays_count' => PlayCounts::ownCountForAlbum($reader)])
            ->tap(fn (Builder $q) => match ($mode) {
                'random' => $q->inRandomOrder(),
                'popular' => $this->mostPlayed($q, PlayCounts::ownPerAlbum($reader), 'collections.id'),
                default => $q->withMax('tracks', 'modified_at')->orderByDesc('tracks_max_modified_at'),
            })
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Collection $album) => [
                'id' => $album->id,
                'name' => $album->name,
                'artist' => $album->albumArtist?->name,
                'year' => $album->year,
                'plays' => (int) $album->plays_count,
                // Decided here like every other route in the app, so the widget links
                // wherever the listing's rows link and the two cannot drift.
                'href' => route('music.albums.show', $album->id, absolute: false),
            ])
            ->all();
    }

    /**
     * Four music songs (music-type tracks). `latest` orders by file mtime
     * (`modified_at`), the true "recently added" after a bulk scan; `random`
     * shuffles. `popular` is {@see mostPlayed}, over every song this reader has played.
     *
     * DELIBERATELY NOT GATED AT MORE THAN ONE PLAY, tempting as that is on the theory that a
     * single listen is noise rather than popularity. That theory hides the answer: a library
     * with three played songs shows "not enough data" while the data is sitting right there,
     * and the pip on every other card makes the emptiness look like a fault. A top-four teaser
     * over a young `plays` table is thin, not wrong — and the ranking earns its meaning as
     * listening accumulates. The note therefore appears only when NOTHING has been played,
     * which is the one case where there is genuinely nothing to rank.
     *
     * It counts by `track_id`, the app's one grain — see docs/data-model.md and
     * App\Services\Player\PlayCounts.
     *
     * The YEAR comes off the song's album rather than the track, because a track has none
     * of its own — it is a fact about the release. Eager-loaded rather than joined so the
     * `select` above stays a track select; four rows make the second query free.
     *
     * @return array<int, array{id: string, name: string, artist: ?string, year: ?int, plays: int, href: string}>
     */
    private function songs(string $mode, ?User $reader): array
    {
        return Track::query()
            ->where('type', TrackType::Music)
            ->with(['artist:id,name', 'collection:id,year'])
            // Qualified, because `popular` joins: an unqualified list is one column name away
            // from being ambiguous, and the engine that says so is the one in production.
            ->select(['tracks.id', 'tracks.name', 'tracks.artist_id', 'tracks.collection_id'])
            // AFTER the select above, not before: `select()` REPLACES the list, so the other
            // order silently drops this sub-select and every card reports 0 — a wrong number
            // rather than an error, the same trap genres() documents.
            ->addSelect(['own_plays_count' => PlayCounts::ownCountForTrack($reader)])
            ->tap(fn (Builder $q) => match ($mode) {
                'random' => $q->inRandomOrder(),
                'popular' => $this->mostPlayed($q, PlayCounts::ownPerTrack($reader), 'tracks.id'),
                default => $q->orderByDesc('modified_at'),
            })
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Track $song) => [
                'id' => $song->id,
                'name' => $song->name,
                'artist' => $song->artist?->name,
                'year' => $song->collection?->year,
                // `own_plays_count` rather than `plays_count`, because it counts THE READER's
                // listens — the pip beside it is theirs, not the household's.
                'plays' => (int) $song->own_plays_count,
                'href' => route('music.songs.show', $song->id, absolute: false),
            ])
            ->all();
    }

    /**
     * Four artists that actually perform tracks. The `has('tracks')` filter is
     * load-bearing: an artist can exist as an album_artist only — compilation
     * owners like "Irish Folk Festival", whose songs credit the individual
     * performers — with zero tracks of its own, so its `max(modified_at)` is
     * NULL. Postgres sorts NULLs FIRST under `ORDER BY … DESC`, which floated
     * those track-less artists to the top of "latest" (invisible on SQLite,
     * which sorts NULLs last). Requiring tracks drops them and keeps the widget
     * to real performers. `popular` (the default in the widget) is {@see mostPlayed}, over the
     * artists this reader has actually listened to; `latest` orders by the newest track's
     * mtime; `random` shuffles.
     *
     * THIS CARD OPENS ON A SET THAT CAN BE EMPTY, which is the cost of `popular` meaning only
     * what it says. The alternative is a second sort key on total file duration, which would
     * always leave four rows to show — at the price of ranking artists by "the one with the
     * most audio", which is not popularity, and of putting unplayed artists above played ones
     * beside a visible play pip, which is an order the numbers on screen contradict. A card
     * with nothing to rank says so instead.
     *
     * Each row also carries the three numbers its card shows as pips: how many albums are
     * credited to them, how many tracks they perform, and what those add up to in seconds.
     *
     * `albums` counts the collections they are the ALBUM-ARTIST of, which is the same
     * relation the artist page's own discography lists — not "albums holding a track of
     * theirs", which would count every compilation they appear on once. `songs` and
     * `duration` are over their own tracks. All three are aggregates rather than loaded
     * relations: four artists must not become four more queries.
     *
     * `tracks_sum_duration` is aliased away by hand below for the reason the genre page's
     * totals document — an aggregate landing on an attribute that HAS a cast gets that cast.
     *
     * A fourth pip carries the reader's OWN listens across those tracks — correlated rather
     * than grouped, for the four-rows reason PlayCounts::ownCountForArtist spells out.
     *
     * @return array<int, array{id: string, name: string, albums: int, songs: int, duration: float, plays: int, href: string}>
     */
    private function artists(string $mode, ?User $reader): array
    {
        return Artist::query()
            ->has('tracks')
            ->withCount(['albums', 'tracks'])
            ->withSum('tracks as total_duration', 'duration')
            ->addSelect(['plays_count' => PlayCounts::ownCountForArtist($reader)])
            ->tap(fn (Builder $q) => match ($mode) {
                'random' => $q->inRandomOrder(),
                'popular' => $this->mostPlayed($q, PlayCounts::ownPerArtist($reader), 'artists.id'),
                default => $q->withMax('tracks', 'modified_at')->orderByDesc('tracks_max_modified_at'),
            })
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Artist $artist) => [
                'id' => $artist->id,
                'name' => $artist->name,
                'albums' => (int) $artist->albums_count,
                'songs' => (int) $artist->tracks_count,
                // Raw seconds; the widget clocks it against the viewer's locale.
                'duration' => (float) ($artist->total_duration ?? 0),
                'plays' => (int) $artist->plays_count,
                'href' => route('music.artists.show', $artist->id, absolute: false),
            ])
            ->all();
    }

    /**
     * Four genres that have tracks. Genres are minted from track tags, so they
     * normally always have some — but `has('tracks')` guards the same Postgres
     * NULLS-FIRST trap as artists() should a genre ever be orphaned (all its
     * tracks pruned). `popular` (the default in the widget) is {@see mostPlayed}, over the
     * genres this reader has actually listened to — and so, like the artists card, it can open
     * empty; artists() carries that argument. `latest` orders by the newest track's mtime;
     * `random` shuffles.
     *
     * Each row also carries the three numbers its card shows as pips, and they use exactly
     * the rules the genre's own page uses, so a reader meeting the same genre twice is not
     * told two different things:
     *
     *   artists — those whose MAIN genre this is (DominantGenre), not everyone who ever
     *             recorded a song in it
     *   albums  — those whose MAIN genre this is, same rule, so a compilation grazing five
     *             genres is counted only by the one that owns it
     *   songs   — every music track tagged with it, the literal reading, because that is a
     *             question about tracks rather than about what a record is
     *
     * Both dominant counts arrive as LEFT joins and are COALESCEd: a genre can hold plenty
     * of songs while being nobody's main genre, and would otherwise report null.
     *
     * A fourth pip carries the reader's OWN listens to its songs — correlated rather than
     * grouped, for the four-rows reason PlayCounts::ownCountForArtist spells out.
     *
     * @return array<int, array{id: string, name: string, artists: int, albums: int, songs: int, plays: int, href: string}>
     */
    private function genres(string $mode, ?User $reader): array
    {
        $albumCounts = DB::query()
            ->fromSub(DominantGenre::albumWinners(), 'album_winners')
            ->groupBy('album_winners.genre_id')
            ->select('album_winners.genre_id')
            ->selectRaw('count(*) as albums_count');

        return Genre::query()
            ->has('tracks')
            ->leftJoinSub(DominantGenre::artistCountsPerGenre(), 'artist_counts', 'artist_counts.genre_id', '=', 'genres.id')
            ->leftJoinSub($albumCounts, 'album_counts', 'album_counts.genre_id', '=', 'genres.id')
            /*
             * `genres.*` explicitly, because the joins put other tables' columns in scope and
             * an unqualified select would hydrate the model from them.
             *
             * And it must come BEFORE withCount, not after: `select()` REPLACES the select
             * list, so calling it later silently discards the count's own sub-select and
             * every genre reports 0 songs — a wrong number rather than an error.
             */
            ->select('genres.*')
            ->addSelect(['artist_counts.artists_count', 'album_counts.albums_count'])
            ->addSelect(['plays_count' => PlayCounts::ownCountForGenre($reader)])
            // Scoped to music like every other number on this page: an audiobook chapter may
            // legally carry a genre (only audiobooks are barred by the tracks CHECK).
            ->withCount(['tracks as songs_count' => fn ($q) => $q->where('type', TrackType::Music)])
            ->tap(fn (Builder $q) => match ($mode) {
                'random' => $q->inRandomOrder(),
                'popular' => $this->mostPlayed($q, PlayCounts::ownPerGenre($reader), 'genres.id'),
                default => $q->withMax('tracks', 'modified_at')->orderByDesc('tracks_max_modified_at'),
            })
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Genre $genre) => [
                'id' => $genre->id,
                'name' => $genre->name,
                'artists' => (int) ($genre->artists_count ?? 0),
                'albums' => (int) ($genre->albums_count ?? 0),
                'songs' => (int) $genre->songs_count,
                'plays' => (int) $genre->plays_count,
                'href' => route('music.genres.show', $genre->id, absolute: false),
            ])
            ->all();
    }
}
