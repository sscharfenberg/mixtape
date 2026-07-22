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
 * props; each widget gets BOTH its latest-added and a random pick (four entries
 * each), so its header toggle flips between them client-side without a round
 * trip. (For a huge collection, or fresher randomness on every toggle, these
 * could move to Inertia::defer / partial reloads — the payload is tiny, so it's
 * eager for now.)
 */
class MusicController extends Controller
{
    /** How many entries each widget shows. */
    private const LIMIT = 4;

    /** Render the Music browse page with the four widgets' latest + random sets. */
    public function __invoke(): Response
    {
        return Inertia::render('Music/MusicPage', [
            'albums' => $this->modes($this->albums(...)),
            'artists' => $this->modes($this->artists(...)),
            'genres' => $this->modes($this->genres(...)),
            'songs' => $this->modes($this->songs(...)),
        ]);
    }

    /**
     * Wrap a per-mode query into the `{ latest, random }` shape the widgets
     * expect, calling it once for each side of the toggle.
     *
     * @param  callable(string): array<int, array<string, mixed>>  $query
     * @return array{latest: array<int, array<string, mixed>>, random: array<int, array<string, mixed>>}
     */
    private function modes(callable $query): array
    {
        return ['latest' => $query('latest'), 'random' => $query('random')];
    }

    /**
     * Four music albums. `latest` orders by the album's newest track's file mtime
     * (`modified_at`) — a collection row has no file date of its own, and mtime is
     * the true "recently added" after a bulk import; `random` shuffles.
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
     * Four music songs (music-type tracks). `latest` orders by the file mtime
     * (`modified_at`), the true "recently added" once the library is scanned in
     * bulk; `random` shuffles in the database.
     *
     * @return array<int, array{id: string, name: string, artist: ?string}>
     */
    private function songs(string $mode): array
    {
        return Track::query()
            ->where('type', TrackType::Music)
            ->with('artist:id,name')
            ->when(
                $mode === 'random',
                fn (Builder $q) => $q->inRandomOrder(),
                fn (Builder $q) => $q->orderByDesc('modified_at'),
            )
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'artist_id'])
            ->map(fn (Track $song) => [
                'id' => $song->id,
                'name' => $song->name,
                'artist' => $song->artist?->name,
            ])
            ->all();
    }

    /**
     * Four artists. Artists carry no timestamps (scanner-managed, random UUID
     * PKs), so "latest" means "most recently added" — ordered by their newest
     * track's file mtime (`modified_at`); `random` shuffles.
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function artists(string $mode): array
    {
        return Artist::query()
            ->when(
                $mode === 'random',
                fn (Builder $q) => $q->inRandomOrder(),
                fn (Builder $q) => $q->withMax('tracks', 'modified_at')->orderByDesc('tracks_max_modified_at'),
            )
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Artist $artist) => ['id' => $artist->id, 'name' => $artist->name])
            ->all();
    }

    /**
     * Four genres. Like artists, genres have no timestamps, so "latest" is
     * ordered by their newest track's file mtime (`modified_at`); `random`
     * shuffles.
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function genres(string $mode): array
    {
        return Genre::query()
            ->when(
                $mode === 'random',
                fn (Builder $q) => $q->inRandomOrder(),
                fn (Builder $q) => $q->withMax('tracks', 'modified_at')->orderByDesc('tracks_max_modified_at'),
            )
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Genre $genre) => ['id' => $genre->id, 'name' => $genre->name])
            ->all();
    }
}
