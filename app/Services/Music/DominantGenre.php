<?php

namespace App\Services\Music;

use App\Enums\TrackType;
use App\Models\Track;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Which genre is this artist, mostly?" — the one place that answers it.
 *
 * MixTape tags genre per TRACK, so an artist has no genre of their own, and plenty of
 * them vary it across a catalogue (a band with one acoustic record, a composer filed half
 * under Score and half under Electronic). The app derives a single MAIN genre per artist:
 * the one most of their music tracks carry.
 *
 * It lives in a service rather than in a controller because TWO screens read it from
 * opposite ends — the artist page shows one artist's main genre, and the genre listing
 * counts the artists whose main genre each genre is. Computed separately, the two would
 * eventually disagree about the same artist (the page saying "Ambient" while the listing
 * files them under "Jazz"), which is the kind of contradiction a reader has no way to
 * explain. One query shape, one tie-break rule, both callers.
 *
 * The TIE-BREAK is the load-bearing part. An artist split 6/6 across two genres has no
 * majority, and SQL orders tied rows arbitrarily — so without a second key the answer
 * could differ between two requests, and between the two screens on the same request.
 * Ties break on the genre's own name, ascending: not meaningful, but stable and
 * explainable, which is all a tie can be.
 */
class DominantGenre
{
    /**
     * Each artist's main genre, as rows of `(artist_id, genre_id, genre_name)`.
     *
     * Three levels, because the answer is a per-group maximum and SQL has no such
     * aggregate: count each artist's tracks per genre, rank those counts within the
     * artist (ties on genre name), then keep rank 1. A window function does the ranking —
     * Postgres and SQLite both have had `row_number() OVER (…)` for years, so this stays
     * one query on both drivers.
     *
     * Tracks with no artist or no genre are excluded rather than grouped under a NULL key:
     * "the untagged artist" is not an artist, and a file with no genre frame cannot vote
     * for one.
     *
     * @param  string|null  $artistId  Restrict to one artist — applied at the innermost
     *                                 level, so a single artist's answer costs a lookup on
     *                                 the `artist_id` index rather than ranking the whole
     *                                 library and filtering afterwards.
     */
    public static function winners(?string $artistId = null): Builder
    {
        // Level 1 — how many music tracks this artist has in each genre. Scoped to music
        // like every query in the Music area: a podcast episode may legally carry both an
        // artist and a genre (only audiobooks are barred by the tracks CHECK), and it has
        // no business voting on what a musician mostly plays.
        $perGenre = Track::query()
            ->where('tracks.type', TrackType::Music)
            ->whereNotNull('tracks.artist_id')
            ->whereNotNull('tracks.genre_id')
            ->when($artistId !== null, fn ($query) => $query->where('tracks.artist_id', $artistId))
            ->groupBy('tracks.artist_id', 'tracks.genre_id')
            ->select(['tracks.artist_id', 'tracks.genre_id'])
            ->selectRaw('count(*) as tracks_count')
            ->toBase();

        // Level 2 — rank each artist's genres: most tracks first, the genre's name
        // breaking a tie. The join is what makes that name available AND carries it out to
        // the caller, so the artist page needs no second query to turn an id into a label.
        // (ORDER BY on the ICU-collated `name` is fine — only LIKE is not; see
        // FoldedSearch.)
        $ranked = DB::query()
            ->fromSub($perGenre, 'per_genre')
            ->join('genres', 'genres.id', '=', 'per_genre.genre_id')
            ->select(['per_genre.artist_id', 'per_genre.genre_id'])
            ->selectRaw('genres.name as genre_name')
            ->selectRaw(
                'row_number() over (partition by per_genre.artist_id'
                .' order by per_genre.tracks_count desc, genres.name asc) as genre_rank'
            );

        // Level 3 — the winner. Aliased `genre_rank` rather than `rank` or `position`,
        // both of which are SQL keywords Postgres would rather read as functions.
        return DB::query()->fromSub($ranked, 'ranked')->where('genre_rank', 1);
    }

    /**
     * How many artists each genre is the main genre OF, as rows of
     * `(genre_id, artists_count)` — the Genres listing's "artists" column.
     *
     * Every artist with at least one tagged music track is counted exactly ONCE, under
     * their main genre alone, which is what makes the column add up: the sum down it is
     * the number of artists in the library, not a total inflated by everyone who ever
     * recorded one song in a second style.
     *
     * Only genres that win somewhere appear here, so callers LEFT JOIN it and COALESCE the
     * missing rows to 0 — a genre can hold plenty of songs without being anybody's main
     * genre.
     */
    public static function artistCountsPerGenre(): Builder
    {
        return DB::query()
            ->fromSub(self::winners(), 'winners')
            ->groupBy('winners.genre_id')
            ->select('winners.genre_id')
            ->selectRaw('count(*) as artists_count');
    }
}
