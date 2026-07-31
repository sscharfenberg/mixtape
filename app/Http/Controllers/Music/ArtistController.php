<?php

namespace App\Http\Controllers\Music;

use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\Track;
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
 * ONE block for now — the hero, holding the artist's name and the same numbers the
 * listing shows, plus the dominant genre. Their SONGS and ALBUMS listings are
 * deliberately still to come (owner's call: ship the page and the links into it first),
 * which is why this controller sends no table at all yet.
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
    public function __invoke(Artist $artist): Response
    {
        $totals = $this->trackTotals($artist);
        $genre = $this->dominantGenre($artist);

        return Inertia::render('Music/Artists/Artist/ArtistPage', [
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
                'genre' => $genre?->name,
                // Where that genre WOULD lead. Null today because the genre area is
                // still a listing with no detail page behind it (`music.genres` has no
                // `.show` sibling) — the same shape SongController's `albumUrl` takes, so
                // the page renders a link when it is handed one and plain text when it is
                // not. Once a GenreController exists this becomes
                // `route('music.genres.show', $genre->id)` and the tile becomes clickable
                // with no change to the page at all.
                'genreUrl' => null,
            ],
        ]);
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
     * The genre most of this artist's songs carry, or null when none of them carry one.
     *
     * A derived fact, not a stored one: MixTape tags genre per TRACK, so an artist has no
     * genre of their own — and plenty of them vary it across their catalogue (a band with
     * one acoustic record, a soundtrack composer filed half under Score and half under
     * Electronic). Picking the modal genre is what makes the tile a useful summary rather
     * than a coin toss.
     *
     * GROUPed and ordered in SQL, limit 1, so it costs one query and never hydrates the
     * catalogue. The second ORDER BY is the load-bearing half: a two-genre artist split
     * 6/6 would otherwise show whichever row the engine happened to hand back first and
     * could show a DIFFERENT one on the next request — so the tie breaks on the genre's
     * own name, and the page is at least stable and explainable.
     *
     * Returns the raw row (id + name) rather than a Genre model: the id is here for the
     * link this tile will get once the genre area has a detail page, and hydrating a
     * model to read two columns off it buys nothing.
     */
    private function dominantGenre(Artist $artist): ?object
    {
        return Track::query()
            ->where('artist_id', $artist->id)
            ->where('type', TrackType::Music)
            ->whereNotNull('genre_id')
            ->toBase()
            ->join('genres', 'tracks.genre_id', '=', 'genres.id')
            ->select(['genres.id', 'genres.name'])
            ->selectRaw('count(*) as songs')
            // Both columns, not just the id: Postgres would accept grouping by the PK
            // alone, SQLite is laxer still, but naming every selected column is the only
            // form that is valid SQL on both.
            ->groupBy('genres.id', 'genres.name')
            ->orderByDesc('songs')
            ->orderBy('genres.name')
            ->first();
    }
}
