<?php

namespace App\Services\Music;

use App\Enums\TrackType;
use App\Models\Track;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Which genre is this artist / this album, mostly?" — the one place that answers it.
 *
 * MixTape tags genre per TRACK, so neither an artist nor an album has a genre of its own,
 * and plenty of both vary it (a band with one acoustic record; a TV-contest compilation
 * holding fifteen Pop songs and one of everything else). The app derives a single MAIN
 * genre for each: the one most of its music tracks carry.
 *
 * It lives in a service rather than in a controller because SEVERAL screens read it from
 * opposite ends — the artist page shows one artist's main genre, the genre listing counts
 * the artists whose main genre each genre is, and the genre page lists both the artists
 * and the albums that belong to it. Computed separately, they would eventually disagree
 * about the same row (a page saying "Ambient" while a listing files it under "Jazz"),
 * which is the kind of contradiction a reader has no way to explain. One query shape, one
 * tie-break rule, every caller.
 *
 * Deriving it for ALBUMS is what keeps a compilation out of five genres it does not
 * belong to. "Holds at least one track of this genre" reads reasonably until you meet a
 * twenty-track contest album with one Power Metal entry, which under that rule appears in
 * the album tab of every genre it grazes.
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
     * @param  string|null  $artistId  Restrict to one artist — applied at the innermost
     *                                 level, so a single artist's answer costs a lookup on
     *                                 the `artist_id` index rather than ranking the whole
     *                                 library and filtering afterwards.
     */
    public static function winners(?string $artistId = null): Builder
    {
        return self::rankedBy('artist_id', $artistId);
    }

    /**
     * Each album's main genre, as rows of `(collection_id, genre_id, genre_name)`.
     *
     * This is what decides whether an album belongs to a genre. The looser reading — "holds
     * at least one track of it" — puts a fifteen-Pop-song contest compilation into the album
     * tab of all five other genres it grazes, which is noise in every one of them.
     *
     * @param  string|null  $collectionId  Restrict to one album, as above.
     */
    public static function albumWinners(?string $collectionId = null): Builder
    {
        return self::rankedBy('collection_id', $collectionId);
    }

    /**
     * The shared shape behind both: the per-owner modal genre, as rows of
     * `(<owner>, genre_id, genre_name)`.
     *
     * Three levels, because the answer is a per-group maximum and SQL has no such
     * aggregate: count each owner's tracks per genre, rank those counts within the owner
     * (ties on genre name), then keep rank 1. A window function does the ranking — Postgres
     * and SQLite have both had `row_number() OVER (…)` for years, so this stays one query
     * on both drivers.
     *
     * Tracks with no owner or no genre are excluded rather than grouped under a NULL key:
     * "the untagged artist" is not an artist, and a file with no genre frame cannot vote
     * for one.
     *
     * @param  string  $owner  The `tracks` column identifying the owner — `artist_id` or
     *                         `collection_id`. An internal constant from the two callers
     *                         above, never user input, which is what makes it safe to
     *                         interpolate into the window function below.
     * @param  string|null  $ownerId  Restrict to one owner, applied at the innermost level.
     */
    private static function rankedBy(string $owner, ?string $ownerId): Builder
    {
        $column = "tracks.{$owner}";

        // Level 1 — how many music tracks this owner has in each genre. Scoped to music
        // like every query in the Music area: `tracks` holds audiobook chapters as well as
        // music, and a chapter cannot carry an artist or a genre at all — the type CHECK
        // forbids it. So the scope is belt-and-braces today, and what keeps the numbers right
        // the day a kind that CAN carry them is added.
        $perGenre = Track::query()
            ->where('tracks.type', TrackType::Music)
            ->whereNotNull($column)
            ->whereNotNull('tracks.genre_id')
            ->when($ownerId !== null, fn ($query) => $query->where($column, $ownerId))
            ->groupBy($column, 'tracks.genre_id')
            ->select([$column, 'tracks.genre_id'])
            ->selectRaw('count(*) as tracks_count')
            ->toBase();

        // Level 2 — rank each owner's genres: most tracks first, the genre's name breaking
        // a tie. The join is what makes that name available AND carries it out to the
        // caller, so the artist page needs no second query to turn an id into a label.
        // (ORDER BY on the ICU-collated `name` is fine — only LIKE is not; see
        // FoldedSearch.)
        $ranked = DB::query()
            ->fromSub($perGenre, 'per_genre')
            ->join('genres', 'genres.id', '=', 'per_genre.genre_id')
            ->select(["per_genre.{$owner}", 'per_genre.genre_id'])
            ->selectRaw('genres.name as genre_name')
            ->selectRaw(
                "row_number() over (partition by per_genre.{$owner}"
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
