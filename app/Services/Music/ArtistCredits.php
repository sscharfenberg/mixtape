<?php

declare(strict_types=1);

namespace App\Services\Music;

use App\Enums\TrackType;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WHICH TRACKS BELONG TO AN ARTIST — the single definition, for every reader of that
 * question (the artist page and its tabs, the artists listing, the Music widget, share
 * grants, "add this artist to a playlist", and the play counts beside all of them).
 *
 * A TRACK IS CREDITED TWO WAYS, and both count. `tracks.artist_id` is what the file's
 * performer tag says, and `collections.album_artist_id` is who the record is by — and a
 * collaboration puts a different string in the first. "Bring Me The Horizon feat.
 * BABYMETAL" is its own row in `artists`, so a band's own page used to be missing the
 * songs a listener is most likely to be looking for. Measured on the live library: 570
 * such tracks across 29 artists, of which the worst case is an album-artist with NONE of
 * its own — "Motorhead" owning a record whose files all tag "Motörhead", which read as an
 * artist with one album and no songs.
 *
 * THE RULE IS THE UNION, NOT ONE OR THE OTHER, and both halves are load-bearing. Only the
 * performer knows about a guest on somebody else's compilation; only the album artist
 * knows about a feature credit on their own record. A track credited to the same artist
 * both ways is ONE track — {@see relation} is a `UNION` rather than a `UNION ALL` for
 * exactly that, so nothing is counted twice.
 *
 * IT INCLUDES THE COMPILATION OWNERS, deliberately: "Various Artists" owns 256 tracks
 * here and its page lists them, because the alternative rule — only widen an artist that
 * already performs something — drops the Motörhead case, which is the fault this exists
 * to fix. The albums tab has always shown those records; now the songs tab agrees with it.
 *
 * SPELLED AS A `(track_id, artist_id)` RELATION rather than as an `OR` over two columns,
 * and that is a measurement rather than a taste. An `OR` spanning `tracks` and
 * `collections` cannot be answered from either table's indexes, so Postgres falls back to
 * a sequential scan of `tracks` once per artist: the artists listing's three aggregates
 * went from 9 ms to 1.9 SECONDS. The union is pushed down into both of its branches
 * instead (each an index scan), which is 0.14 ms for one artist and 12 ms for the whole
 * listing.
 *
 * FOUR SHAPES, AND WHICH ONE IS RIGHT IS A MEASUREMENT rather than a taste — the wrong one is
 * correct and unusable. {@see of} narrows a `tracks` query to named artists (0.14 ms).
 * {@see totalsPerArtist} is the grouped aggregate a LISTING joins, because a sortable column is
 * computed for every artist before the sort can run (12 ms, against 366 ms of correlated probes).
 * {@see artistIds} is the SET an "artists that …" predicate semi-joins with, because correlating
 * that question nests twice and Postgres cannot push the artist through both levels (15 ms,
 * against 29 SECONDS for one stats tile). {@see correlated} is the leftover case and the only one
 * that probes per row: a card showing FOUR rows, where building any of the above would be the
 * expensive mistake in the other direction.
 *
 * IT DOES NOT NARROW BY TRACK TYPE. Which kinds of track a caller wants is the caller's
 * question and they disagree — an artist's music, a share's grant, a play count that has
 * to match the "songs" figure beside it — so the type clause stays where it already is
 * rather than being decided here for all of them.
 */
final class ArtistCredits
{
    /**
     * The relation itself: one row per `(track_id, artist_id)` pair the library credits.
     *
     * `union` and not `unionAll` — a track whose performer IS its album artist (the normal
     * case, 9,379 of 9,949 here) appears in both branches, and counting it twice would make
     * every artist's song count wrong rather than only the collaborations.
     *
     * Both branches select the same two aliases, so a caller can join or filter on
     * `credits.track_id` / `credits.artist_id` without knowing which arm a row came from —
     * which is the whole point of handing back a relation rather than a predicate.
     */
    public static function relation(): Builder
    {
        return DB::table('tracks')
            ->select(['tracks.id as track_id', 'tracks.artist_id as artist_id'])
            ->whereNotNull('tracks.artist_id')
            ->union(
                DB::table('tracks')
                    ->join('collections', 'collections.id', '=', 'tracks.collection_id')
                    ->select(['tracks.id as track_id', 'collections.album_artist_id as artist_id'])
                    ->whereNotNull('collections.album_artist_id')
            );
    }

    /**
     * Narrow a query over `tracks` to everything credited to one or more artists.
     *
     * THE SHAPE EVERY "this artist's tracks" CALLER USES — as a semi-join on `tracks.id`
     * rather than a join, so it composes with a query that has already joined `collections`
     * (most of them have, for the album name and the playing order) instead of colliding
     * with it. Nothing is added to the select list and no row can be duplicated.
     *
     * PLURAL because a listing's checkboxes name several artists at once, and the single
     * callers pass a one-element array — one query shape rather than a scalar path and a
     * plural path that could narrow differently.
     *
     * @template TBuilder of BuilderContract
     *
     * @param  TBuilder  $tracks  a query whose base table is `tracks`; accepts the Eloquent
     *                            and the query builder alike, since every caller here is one
     *                            or the other
     * @param  list<string>  $ids  artist ids
     * @return TBuilder the same query, narrowed
     */
    public static function of(BuilderContract $tracks, array $ids): BuilderContract
    {
        return $tracks->whereIn('tracks.id', self::trackIds()->whereIn('credits.artist_id', $ids));
    }

    /**
     * Narrow a query over `tracks` to whatever is credited to the artist an OUTER query is
     * on — the correlated form, for a subquery selected beside `artists.id`.
     *
     * The sibling of {@see of} for the callers that cannot name the id because there is one
     * per row. Correlated to `artists.id`, so it only composes with a query whose outer table
     * is `artists` — which is the only place the question comes up in this form.
     *
     * PREFER {@see totalsPerArtist} WHEN THE COLUMN IS SORTABLE. A sortable aggregate has to
     * be computed for every artist before the sort can run, and this shape re-probes the
     * union once per row to do it; the grouped form aggregates the whole library once and
     * hash-joins it. PlayCounts::ownCountForArtist carries the same pair for the same reason,
     * with the measurement.
     *
     * @template TBuilder of BuilderContract
     *
     * @param  TBuilder  $tracks  a query whose base table is `tracks`
     * @return TBuilder the same query, narrowed
     */
    public static function correlated(BuilderContract $tracks): BuilderContract
    {
        return $tracks->whereIn('tracks.id', self::trackIds()->whereColumn('credits.artist_id', 'artists.id'));
    }

    /**
     * The ids of the artists credited with at least one track the caller admits — a SET, for an
     * outer query to semi-join with `whereIn('artists.id', …)`.
     *
     * WHAT EVERY "artists that …" QUESTION ASKS, and it must be spelled this way rather than as
     * a correlated `EXISTS`. Correlated, the probe nests twice — the outer artist reaches the
     * union through `tracks.id` — and Postgres cannot push the artist through both levels:
     * measured on the artists strip, 29 SECONDS for one tile against 15 ms for the semi-join,
     * which builds the set once and hashes it. The listing runs four of those tiles per page.
     *
     * `artist_id` is never null in either arm of {@see relation}, which is what makes the
     * complement safe to spell as `whereNotIn` — a single NULL in the subquery would make `NOT
     * IN` answer with no rows at all, silently, for every artist.
     *
     * @param  (callable(Builder): mixed)|null  $narrow  applied to the joined `tracks` query, for
     *                                                   a caller that wants a subset of the library
     */
    public static function artistIds(?callable $narrow = null): Builder
    {
        $query = DB::query()
            ->fromSub(self::relation(), 'credits')
            ->join('tracks', 'tracks.id', '=', 'credits.track_id')
            ->select('credits.artist_id');

        if ($narrow !== null) {
            $narrow($query);
        }

        return $query;
    }

    /**
     * Every artist's music totals in one grouped pass — how many tracks they are credited
     * with, how long those play, how much disk they take, and the newest file among them.
     *
     * FOR A LISTING TO `leftJoinSub` (or `joinSub`, where the inner join is itself the
     * "artists with something to play" filter the Music widget wants). Grouped rather than
     * correlated because both of its callers SORT by one of these columns, which means every
     * artist is computed before the limit or the page can apply — the shape argument
     * PlayCounts::ownPerArtist makes in full. Measured here: 12 ms for all 641 artists,
     * against 366 ms of correlated probes.
     *
     * `subject_id` rather than `artist_id`, matching the alias PlayCounts' grouped counts
     * hand back, so a listing joins both the same way.
     *
     * MUSIC ONLY, unlike everything else in this class: these numbers sit beside a "songs"
     * label on pages in the music area, and an artist's audiobook narration is not part of
     * what those pages mean by their catalogue. The `tracks` CHECK forbids a chapter from
     * carrying an `artist_id` at all today — but not from sitting on a collection, so the
     * album-artist arm could reach one the day audiobooks grow an album artist.
     *
     * The sums are COALESCEd because a caller left-joining this reads the row it finds; the
     * missing row (an artist credited with nothing) is the caller's own COALESCE to write.
     * `modified_at` stays nullable — "the newest file" of nothing is not a date.
     */
    public static function totalsPerArtist(): Builder
    {
        return DB::query()
            ->fromSub(self::relation(), 'credits')
            ->join('tracks', 'tracks.id', '=', 'credits.track_id')
            ->where('tracks.type', TrackType::Music->value)
            ->groupBy('credits.artist_id')
            ->selectRaw('credits.artist_id as subject_id')
            ->selectRaw('count(*) as songs_count')
            ->selectRaw('coalesce(sum(tracks.duration), 0) as duration_total')
            ->selectRaw('coalesce(sum(tracks.size), 0) as size_total')
            ->selectRaw('max(tracks.modified_at) as modified_at');
    }

    /**
     * The credited track ids, as an unfinished subquery for the caller to narrow by artist.
     *
     * Private because the two narrowings above are the only two that exist, and both belong
     * here: a caller building its own would be free to forget the `credits.` qualification —
     * `artist_id` is a column on `tracks` too, so an unqualified one resolves to the OUTER
     * query's row and silently answers a different question.
     */
    private static function trackIds(): Builder
    {
        return DB::query()->select('credits.track_id')->fromSub(self::relation(), 'credits');
    }
}
