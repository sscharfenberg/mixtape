<?php

namespace App\Http\Controllers\Music;

use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Genre;
use App\Models\Track;
use App\Services\Music\DominantGenre;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One genre's detail page (`GET /music/genres/{genre}`, route `music.genres.show`,
 * behind auth) — the row-click target of the Genres listing, and where the genre tile on
 * an artist's page leads (the link that tile was waiting for).
 *
 * Sibling to GenresController by design, like the other three pairs in this namespace:
 * same namespace, singular name for the single-record view, so the pair reads like the
 * routes do (`music.genres` / `music.genres.show`).
 *
 * ONE block for now — the hero, holding the genre's name and the same four numbers its
 * listing row shows. The ARTISTS and SONGS listings that belong under it are deliberately
 * still to come, exactly as on the artist page: the pages and the links between them
 * first, the tables inside them after.
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
    public function __invoke(Genre $genre): Response
    {
        $totals = $this->trackTotals($genre);

        return Inertia::render('Music/Genres/Genre/GenrePage', [
            'genre' => [
                'id' => $genre->id,
                'name' => $genre->name,

                'artists' => $this->mainGenreArtists($genre),
                'songs' => $totals['songs'],
                'duration' => $totals['duration'],
                'size' => $totals['size'],
            ],
        ]);
    }

    /**
     * How many artists have THIS as their main genre — the same number, from the same
     * rule, as the listing's artists column (DominantGenre).
     *
     * Note where the filter sits: on the OUTER query, after the ranking. Unlike the artist
     * page's filter — which DominantGenre pushes down into the innermost count, because
     * restricting to one artist cannot change who wins — restricting to one GENRE before
     * the ranking would change the answer entirely. An artist who is mostly Jazz and
     * partly Ambient would win Ambient in a query that could only see their Ambient
     * tracks, and this page would claim them. Every genre has to compete for an artist
     * before we ask which genre won.
     */
    private function mainGenreArtists(Genre $genre): int
    {
        return DB::query()
            ->fromSub(DominantGenre::winners(), 'winners')
            ->where('winners.genre_id', $genre->id)
            ->count();
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
     * Scoped to music like every query in this namespace: a podcast episode may legally
     * carry a `genre_id` (only audiobooks are barred, by the tracks CHECK).
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
