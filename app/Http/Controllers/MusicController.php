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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Music area (`GET /music`, route `music`, behind auth) — the browse view
 * for the music collection. Renders four widgets' worth of data as Inertia
 * props; each widget gets every mode it supports (latest + random for all, plus
 * a "popular" set for songs/artists/genres), four entries each, so its header
 * toggle flips between them client-side without a round trip. (For a huge
 * collection, or fresher randomness on every toggle, these could move to
 * Inertia::defer / partial reloads — the payload is tiny, so it's eager for now.)
 */
class MusicController extends Controller
{
    /** How many entries each widget shows. */
    private const LIMIT = 4;

    /**
     * Render the Music browse page. Albums get latest/random only; songs,
     * artists and genres also get a "popular" set — all three ranked by THE READER'S OWN
     * listens, the two taxonomies falling back to total file duration so their
     * cards stay populated before much has been played. `stats` carries the collection totals
     * for the stats widget.
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
            'artists' => fn () => $this->modes(fn (string $mode) => $this->artists($mode, $reader), ['popular']),
            'genres' => fn () => $this->modes(fn (string $mode) => $this->genres($mode, $reader), ['popular']),
            'songs' => fn () => $this->modes(fn (string $mode) => $this->songs($mode, $reader), ['popular']),
            'stats' => fn (): array => LibraryStats::music(),
        ]);
    }

    /**
     * Wrap a per-mode query into the keyed shape the widgets expect, calling it
     * once per mode. `latest` and `random` are always built; `$extra` adds any
     * others the widget supports (currently just `popular`) — albums pass none.
     *
     * @param  callable(string): array<int, array<string, mixed>>  $query
     * @param  list<string>  $extra  modes beyond latest/random, e.g. ['popular']
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function modes(callable $query, array $extra = []): array
    {
        $modes = ['latest' => $query('latest'), 'random' => $query('random')];

        foreach ($extra as $mode) {
            $modes[$mode] = $query($mode);
        }

        return $modes;
    }

    /**
     * Four music albums. `latest` orders by the album's newest track's file mtime
     * (`modified_at`) — a collection row has no file date of its own, and mtime is
     * the true "recently added" after a bulk import; `random` shuffles. (No
     * `popular` mode — it is scoped to songs, artists and genres.)
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
            // measured — see PlayCounts::ownCountForArtist. Nothing here sorts by it, so the
            // engine evaluates it for the four rows that survive the limit.
            ->addSelect(['plays_count' => PlayCounts::ownCountForAlbum($reader)])
            ->when(
                $mode === 'random',
                fn (Builder $q) => $q->inRandomOrder(),
                fn (Builder $q) => $q->withMax('tracks', 'modified_at')->orderByDesc('tracks_max_modified_at'),
            )
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
     * shuffles. `popular` is the most-played by play count (the `plays` table, all users),
     * over every song with AT LEAST ONE play.
     *
     * DELIBERATELY NOT GATED AT MORE THAN ONE PLAY, tempting as that is on the theory that a
     * single listen is noise rather than popularity. That theory hides the answer: a library
     * with three played songs shows "not enough data" while the data is sitting right there,
     * and the pip on every other card makes the emptiness look like a fault. A top-four teaser
     * over a young `plays` table is thin, not wrong — and the ranking earns its meaning as
     * listening accumulates. The "not enough data" note therefore appears only when NOTHING
     * has been played, which is the one case where there is genuinely nothing to rank.
     *
     * `popular` COUNTS THE READER, not the household. Ranking everybody's listens reads as a
     * shared "what gets played here" set — a defensible thing to want, and wrong beside a pip.
     * Every card carries the reader's own count, so a household ranking could put a song
     * showing "1×" above one showing "5×" with nothing on screen to explain the order. An order
     * that contradicts the number printed next to it is read as a bug.
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
            ->select(['id', 'name', 'artist_id', 'collection_id'])
            // AFTER the select above, not before: `select()` REPLACES the list, so the other
            // order silently drops this sub-select and every card reports 0 — a wrong number
            // rather than an error, the same trap genres() documents.
            ->addSelect(['own_plays_count' => PlayCounts::ownCountForTrack($reader)])
            ->tap(fn (Builder $q) => match ($mode) {
                'random' => $q->inRandomOrder(),
                // Ordered by the alias `addSelect` put on the query above — both engines
                // resolve a SELECT alias in ORDER BY. The filter is a separate EXISTS rather
                // than a reuse of that subquery, because a scalar count cannot be a WHERE
                // condition; with no reader it matches nothing, which is the honest answer.
                'popular' => $q
                    ->whereHas('plays', fn ($plays) => $plays->where('plays.user_id', $reader?->id))
                    ->orderByDesc('own_plays_count'),
                default => $q->orderByDesc('modified_at'),
            })
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Track $song) => [
                'id' => $song->id,
                'name' => $song->name,
                'artist' => $song->artist?->name,
                'year' => $song->collection?->year,
                // Aliased away from `plays_count`, which `withCount('plays')` already owns in
                // the popular branch — and which counts EVERYBODY's listens, not the reader's.
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
     * to real performers. `popular` (the default in the widget) orders by THE READER'S OWN
     * listens, then by total file duration; `latest` by the newest track's mtime; `random`
     * shuffles.
     *
     * THE TWO-KEY ORDER IS THE DESIGN. Minutes alone — "the artist with the most audio" — is
     * defensible right up until a card grows a play pip: the set then shows unplayed artists
     * above played ones, and an order that contradicts the numbers printed on it is read as a
     * bug. Plays first fixes that; minutes second is what keeps the card POPULATED on a
     * library nobody has listened to much, where a strict play ranking would leave this
     * widget's DEFAULT view nearly empty. So a played artist can never sit below an unplayed
     * one, and the unplayed tail keeps the old, useful order.
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
                // The GROUPED subquery, not the correlated one selected above: this is an
                // ORDER BY, so it is computed for every artist before the limit can apply —
                // the one case where aggregating `plays` once beats probing it per row
                // (PlayCounts::ownCountForArtist carries the measurement).
                //
                // COALESCEd rather than ordered on the raw joined column, and that is
                // load-bearing: an unplayed artist has no row in the subquery, and Postgres
                // sorts NULLs FIRST under DESC — which would float exactly the artists nobody
                // has played to the top of "most played". SQLite sorts them last, so the suite
                // would never have shown it.
                'popular' => $q->withSum('tracks', 'duration')
                    ->leftJoinSub(PlayCounts::ownPerArtist($reader), 'popularity', 'popularity.subject_id', '=', 'artists.id')
                    ->orderByRaw('coalesce(popularity.plays, 0) desc')
                    ->orderByDesc('tracks_sum_duration'),
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
     * tracks pruned). `popular` (the default in the widget) orders by THE READER'S OWN
     * listens, then by total file duration — the same two-key order as the artists widget,
     * and for the same reason (artists() carries the argument); `latest` by the newest
     * track's mtime; `random` shuffles.
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
                // Grouped and COALESCEd, exactly as artists() documents — including why the
                // NULL handling is not tidiness.
                'popular' => $q->withSum('tracks', 'duration')
                    ->leftJoinSub(PlayCounts::ownPerGenre($reader), 'popularity', 'popularity.subject_id', '=', 'genres.id')
                    ->orderByRaw('coalesce(popularity.plays, 0) desc')
                    ->orderByDesc('tracks_sum_duration'),
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
