<?php

namespace App\Http\Controllers;

use App\Enums\CollectionType;
use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Track;
use App\Services\Music\DominantGenre;
use Illuminate\Database\Eloquent\Builder;
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
     * artists and genres also get a "popular" set (songs by plays, the two
     * taxonomies by total file duration). `stats` carries the collection totals
     * for the stats widget.
     */
    public function __invoke(): Response
    {
        // Each widget's data is a closure so a partial reload (the footer's
        // refresh button → router.reload({ only: ['artists'] })) re-runs ONLY
        // that widget's query — reshuffling its `random` — instead of all four.
        // Full page loads still evaluate every closure.
        return Inertia::render('Music/MusicPage', [
            'albums' => fn () => $this->modes($this->albums(...)),
            'artists' => fn () => $this->modes($this->artists(...), ['popular']),
            'genres' => fn () => $this->modes($this->genres(...), ['popular']),
            'songs' => fn () => $this->modes($this->songs(...), ['popular']),
            'stats' => fn () => $this->stats(),
        ]);
    }

    /**
     * Collection totals for the stats widget — music only, matching the browse
     * widgets (artists/genres restricted to those with tracks, like their lists).
     * Raw numbers only; the frontend formats size (bytes → GB) and playtime
     * (seconds → months/days/…). `size`/`duration` are cast so a null SUM on an
     * empty library becomes 0 rather than null.
     *
     * @return array{songs: int, sizeBytes: int, playtimeSeconds: float, albums: int, artists: int, genres: int}
     */
    private function stats(): array
    {
        $music = fn () => Track::query()->where('type', TrackType::Music);

        return [
            'songs' => $music()->count(),
            'sizeBytes' => (int) $music()->sum('size'),
            'playtimeSeconds' => (float) $music()->sum('duration'),
            'albums' => Collection::query()->where('type', CollectionType::Album)->count(),
            'artists' => Artist::query()->has('tracks')->count(),
            'genres' => Genre::query()->has('tracks')->count(),
        ];
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
     * `popular` mode — the owner scoped it to songs/artists/genres.)
     *
     * @return array<int, array{id: string, name: string, artist: ?string, year: ?int, href: string}>
     */
    private function albums(string $mode): array
    {
        return Collection::query()
            ->where('type', CollectionType::Album)
            ->with('albumArtist:id,name')
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
                // Decided here like every other route in the app, so the widget links
                // wherever the listing's rows link and the two cannot drift.
                'href' => route('music.albums.show', $album->id, absolute: false),
            ])
            ->all();
    }

    /**
     * Four music songs (music-type tracks). `latest` orders by file mtime
     * (`modified_at`), the true "recently added" after a bulk scan; `random`
     * shuffles. `popular` is the most-played by play count (the `plays` table,
     * all users) — but restricted to songs with MORE THAN ONE play
     * (`has('plays', '>', 1)`): a single listen is noise, not popularity. Until
     * real listens accumulate this set is usually empty, and the widget shows a
     * "not enough data" note rather than a meaningless ranking. (Counts are
     * per track *row*; the schema's clone-aggregation by `content_hash` — open
     * decision #5 — is deferred; a top-four teaser doesn't need it.)
     *
     * The YEAR comes off the song's album rather than the track, because a track has none
     * of its own — it is a fact about the release. Eager-loaded rather than joined so the
     * `select` above stays a track select; four rows make the second query free.
     *
     * @return array<int, array{id: string, name: string, artist: ?string, year: ?int, href: string}>
     */
    private function songs(string $mode): array
    {
        return Track::query()
            ->where('type', TrackType::Music)
            ->with(['artist:id,name', 'collection:id,year'])
            ->select(['id', 'name', 'artist_id', 'collection_id'])
            ->tap(fn (Builder $q) => match ($mode) {
                'random' => $q->inRandomOrder(),
                'popular' => $q->has('plays', '>', 1)->withCount('plays')->orderByDesc('plays_count'),
                default => $q->orderByDesc('modified_at'),
            })
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Track $song) => [
                'id' => $song->id,
                'name' => $song->name,
                'artist' => $song->artist?->name,
                'year' => $song->collection?->year,
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
     * to real performers. `popular` (the default in the widget) orders by total
     * file duration — the artist with the most audio; `latest` by the newest
     * track's mtime; `random` shuffles.
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
     * @return array<int, array{id: string, name: string, albums: int, songs: int, duration: float, href: string}>
     */
    private function artists(string $mode): array
    {
        return Artist::query()
            ->has('tracks')
            ->withCount(['albums', 'tracks'])
            ->withSum('tracks as total_duration', 'duration')
            ->tap(fn (Builder $q) => match ($mode) {
                'random' => $q->inRandomOrder(),
                'popular' => $q->withSum('tracks', 'duration')->orderByDesc('tracks_sum_duration'),
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
                'href' => route('music.artists.show', $artist->id, absolute: false),
            ])
            ->all();
    }

    /**
     * Four genres that have tracks. Genres are minted from track tags, so they
     * normally always have some — but `has('tracks')` guards the same Postgres
     * NULLS-FIRST trap as artists() should a genre ever be orphaned (all its
     * tracks pruned). `popular` (the default in the widget) orders by total file
     * duration — the genre with the most audio; `latest` by the newest track's
     * mtime; `random` shuffles.
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
     * @return array<int, array{id: string, name: string, artists: int, albums: int, songs: int, href: string}>
     */
    private function genres(string $mode): array
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
            // Scoped to music like every other number on this page: a podcast episode may
            // legally carry a genre (only audiobooks are barred by the tracks CHECK).
            ->withCount(['tracks as songs_count' => fn ($q) => $q->where('type', TrackType::Music)])
            ->tap(fn (Builder $q) => match ($mode) {
                'random' => $q->inRandomOrder(),
                'popular' => $q->withSum('tracks', 'duration')->orderByDesc('tracks_sum_duration'),
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
                'href' => route('music.genres.show', $genre->id, absolute: false),
            ])
            ->all();
    }
}
