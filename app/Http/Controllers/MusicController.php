<?php

namespace App\Http\Controllers;

use App\Enums\CollectionType;
use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
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
     * taxonomies by total file duration).
     */
    public function __invoke(): Response
    {
        return Inertia::render('Music/MusicPage', [
            'albums' => $this->modes($this->albums(...)),
            'artists' => $this->modes($this->artists(...), ['popular']),
            'genres' => $this->modes($this->genres(...), ['popular']),
            'songs' => $this->modes($this->songs(...), ['popular']),
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
     * `popular` mode — the owner scoped it to songs/artists/genres.)
     *
     * @return array<int, array{id: string, name: string, artist: ?string, year: ?int}>
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
            ])
            ->all();
    }

    /**
     * Four music songs (music-type tracks). `latest` orders by file mtime
     * (`modified_at`), the true "recently added" after a bulk scan; `popular` is
     * the most-played, ordered by play count (the `plays` table, all users);
     * `random` shuffles. Note: `popular` counts plays per track *row*; the
     * schema's plan to aggregate clones by `content_hash` (open decision #5) is
     * deferred — for a top-four teaser the per-row count is close enough.
     *
     * @return array<int, array{id: string, name: string, artist: ?string}>
     */
    private function songs(string $mode): array
    {
        return Track::query()
            ->where('type', TrackType::Music)
            ->with('artist:id,name')
            ->select(['id', 'name', 'artist_id'])
            ->tap(fn (Builder $q) => match ($mode) {
                'random' => $q->inRandomOrder(),
                'popular' => $q->withCount('plays')->orderByDesc('plays_count'),
                default => $q->orderByDesc('modified_at'),
            })
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Track $song) => [
                'id' => $song->id,
                'name' => $song->name,
                'artist' => $song->artist?->name,
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
     * @return array<int, array{id: string, name: string}>
     */
    private function artists(string $mode): array
    {
        return Artist::query()
            ->has('tracks')
            ->tap(fn (Builder $q) => match ($mode) {
                'random' => $q->inRandomOrder(),
                'popular' => $q->withSum('tracks', 'duration')->orderByDesc('tracks_sum_duration'),
                default => $q->withMax('tracks', 'modified_at')->orderByDesc('tracks_max_modified_at'),
            })
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Artist $artist) => ['id' => $artist->id, 'name' => $artist->name])
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
     * @return array<int, array{id: string, name: string}>
     */
    private function genres(string $mode): array
    {
        return Genre::query()
            ->has('tracks')
            ->tap(fn (Builder $q) => match ($mode) {
                'random' => $q->inRandomOrder(),
                'popular' => $q->withSum('tracks', 'duration')->orderByDesc('tracks_sum_duration'),
                default => $q->withMax('tracks', 'modified_at')->orderByDesc('tracks_max_modified_at'),
            })
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Genre $genre) => ['id' => $genre->id, 'name' => $genre->name])
            ->all();
    }
}
