<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Enums\TrackType;
use App\Models\Artist;
use App\Models\Collection;
use App\Models\Genre;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * How often something has been listened to — yours, and everybody else's. One track, or
 * everything filed under one artist, genre or album.
 *
 * A track, not a song: `tracks` is one table for music and audiobook chapters, and a listen
 * is a listen whatever kind of thing was listened to. Where the type IS asked about, it is
 * the caller's `musicOnly` narrowing rather than a rule of this file — see the note further
 * down. Only the SENTENCES are per-subject, because "you played this song 3 times" is the
 * wrong noun for a chapter — those live with the page that says them.
 *
 * EVERYTHING HERE COUNTS BY `track_id` — plain listening EVENTS against the row that was
 * played. One rule for a song and for a subject, which is what lets the figures add up: the
 * songs under an album sum to the album's own count, and an artist's tracks sum to theirs.
 *
 * NOT BY `content_hash`, which is the tempting alternative: the same recording sits in the
 * library several times over — album, compilation, best-of — and a reader thinks of those as
 * one song. Counting the hash breaks the property above, because each track then quietly
 * counts its twin elsewhere and the tracks sum to more than the record they sit on. It also
 * cannot answer a SUBJECT count at all: "plays of this artist" joins `plays → tracks` and
 * filters on `artist_id`, where matching by hash double-counts any artist holding two copies
 * of one recording — the normal case in a real collection. And the hash grain was never
 * implemented anywhere. It also removed the arithmetic a reader could not reproduce — an
 * album whose track figures summed to more than the album's own, because each track was
 * quietly counting its twin elsewhere.
 *
 * What the change costs is real and worth stating: play a song from the best-of and its
 * entry on the album shows nothing. That is now the honest reading — these are two files,
 * and the page is about the file.
 *
 * SUBJECT COUNTS CARRY THE SCOPE OF THE NUMBERS BESIDE THEM. An artist's or a genre's songs
 * are counted `type = music` everywhere in App\Http\Controllers\Music, so their plays are
 * too — a tile counting listens the neighbouring "songs" tile does not count would be
 * arithmetic a reader cannot reproduce. An album needs no such clause: a collection is an
 * album or an audiobook, never both, so its own row already decides.
 *
 * TWO QUERIES RATHER THAN ONE conditional aggregate: `count(*) FILTER (WHERE …)` is
 * Postgres's spelling and the test suite runs sqlite, and a `SUM(CASE …)` that works in both
 * reads worse than two counts that each say what they mean. Both hit the same indexes
 * (`plays.track_id`, `plays.user_id`), on a page that already runs a handful.
 */
final class PlayCounts
{
    /**
     * Play counts for one track, split into the reader's own and everybody else's.
     *
     * Listens to THIS ROW, and no other — see the class docblock for why that changed and
     * what it costs. No join: `plays.track_id` is the answer on its own, which is also the
     * index the two counts ride.
     *
     * "Others" is derived by subtraction rather than asked for separately — one fewer query,
     * and the two numbers cannot disagree about the total the way two independent counts
     * could if a play landed between them.
     *
     * A guest gets `own: 0` and every play as somebody else's, which is the honest reading:
     * nobody who is not signed in has a listening history here.
     *
     * @return array{own: int, others: int}
     */
    public static function forTrack(Track $track, ?User $user): array
    {
        $plays = fn () => DB::table('plays')->where('plays.track_id', $track->id);

        $total = $plays()->count();
        $own = $user ? $plays()->where('plays.user_id', $user->id)->count() : 0;

        return ['own' => $own, 'others' => $total - $own];
    }

    /**
     * Listens to everything credited to one artist, split the reader's way and everybody
     * else's. Scoped to music, matching the `songs` figure it sits beside.
     *
     * @return array{own: int, others: int}
     */
    public static function forArtist(Artist $artist, ?User $user): array
    {
        return self::forSubject('artist_id', $artist->id, $user, musicOnly: true);
    }

    /**
     * Listens to everything tagged with one genre, split the same way — and scoped to music
     * for the same reason the artist's is.
     *
     * @return array{own: int, others: int}
     */
    public static function forGenre(Genre $genre, ?User $user): array
    {
        return self::forSubject('genre_id', $genre->id, $user, musicOnly: true);
    }

    /**
     * Listens to one album's own tracks. No type clause: the collection row is already an
     * album or an audiobook, and AlbumController 404s anything that is not the former.
     *
     * @return array{own: int, others: int}
     */
    public static function forAlbum(Collection $album, ?User $user): array
    {
        return self::forSubject('collection_id', $album->id, $user, musicOnly: false);
    }

    /**
     * Play counts for everything in one playlist.
     *
     * NOT `forSubject`, and it cannot be: the other three subjects are a COLUMN on `tracks`, so
     * a play belongs to them through a foreign key it already carries. Playlist membership lives
     * in a pivot instead, so this reads that pivot as a SET of track ids — the only structural
     * difference, and the reason this is a method rather than another `forSubject('…_id', …)` line.
     *
     * NO TYPE CLAUSE, matching the page it feeds: a playlist may deliberately mix music with
     * audiobook chapters and its own facts count both, so its plays must too. A tile counting
     * listens that the "Titel" tile beside it does not count is arithmetic a reader cannot
     * reproduce — which is the class docblock's rule, reaching the opposite answer here from the
     * one it reaches for an artist.
     *
     * A TRACK LISTED TWICE COUNTS ITS LISTENS ONCE, which is why this is not a `join` on the
     * pivot: a join yields a row per ENTRY, so a song sitting in the playlist twice makes every
     * one of its plays count twice. The argument for the other reading — "somebody who put a song
     * in twice hears it twice" — describes playing the list THROUGH, which produces two rows in
     * `plays` and counts two under either rule. It does not describe the case that actually
     * breaks: one listen, one row in `plays`, and a tile claiming two. These numbers are counts of
     * listening EVENTS everywhere else in this class.
     *
     * SO THE PIVOT IS A SET, NOT A JOIN — `whereIn` over the playlist's track ids, which makes
     * double counting structurally impossible rather than something a later reader has to
     * remember to `distinct()` away. Postgres and sqlite both plan it as a semi-join.
     *
     * It follows that the plays tile and the "Titel" tile beside it can now disagree in a way they
     * could not before — twelve entries, eleven distinct tracks — and that is the right way round:
     * the track count is about the LIST, the plays count is about listening, and only the second
     * one is arithmetic a reader can check against their own memory of pressing play.
     *
     * @return array{own: int, others: int}
     */
    public static function forPlaylist(Playlist $playlist, ?User $user): array
    {
        $plays = fn () => DB::table('plays')
            ->whereIn('plays.track_id', fn (QueryBuilder $entries) => $entries
                ->select('track_id')
                ->from('playlist_tracks')
                ->where('playlist_id', $playlist->id)
            );

        $total = $plays()->count();
        $own = $user ? $plays()->where('plays.user_id', $user->id)->count() : 0;

        return ['own' => $own, 'others' => $total - $own];
    }

    /**
     * The reader's OWN listens per artist, as an unexecuted grouped query for a listing to
     * `leftJoinSub` on `subject_id`.
     *
     * A grouped join rather than the correlated subquery the other columns on those pages
     * use, and the difference is not stylistic — it was measured. A sortable column has to
     * be computed for every row before the sort can happen, and a correlated count re-probes
     * the plays table once per parent: on the genres listing against 500k plays that is
     * 914 ms, against 123 ms for aggregating the whole table once and hash-joining it. The
     * correlated shape is right for `songs_count` (it rides `tracks.artist_id` and touches
     * nothing else) and wrong here.
     */
    public static function ownPerArtist(?User $user): QueryBuilder
    {
        return self::ownPerSubject('artist_id', $user, musicOnly: true);
    }

    /** The reader's own listens per genre — see ownPerArtist for the shape and why. */
    public static function ownPerGenre(?User $user): QueryBuilder
    {
        return self::ownPerSubject('genre_id', $user, musicOnly: true);
    }

    /** The reader's own listens per album — see ownPerArtist for the shape and why. */
    public static function ownPerAlbum(?User $user): QueryBuilder
    {
        return self::ownPerSubject('collection_id', $user, musicOnly: false);
    }

    /**
     * The reader's own listens per TRACK — what a most-played SONG list sorts and filters on.
     *
     * No join to `tracks`, unlike its three siblings: `plays.track_id` IS the key being
     * grouped, so the tracks table has nothing to add. Which also means it takes no
     * `musicOnly` narrowing — a chapter's plays group like any other row, and the caller's own
     * `type` filter decides what those rows can join back to.
     */
    public static function ownPerTrack(?User $user): QueryBuilder
    {
        $query = DB::table('plays')
            ->groupBy('plays.track_id')
            ->selectRaw('plays.track_id as subject_id')
            ->selectRaw('count(*) as plays');

        return self::scopedToReader($query, $user);
    }

    /**
     * The reader's own listens for the artist an OUTER query is on, as a CORRELATED subquery
     * to drop into `addSelect(['plays_count' => …])`.
     *
     * THE OTHER SHAPE, and the pair is deliberate rather than duplication. Which one is right
     * depends entirely on how many parent rows have to be counted:
     *
     *   - A listing SORTS by this column, so every artist must be counted before the sort can
     *     run. Correlated, that re-probes `plays` once per artist — 914 ms on the genres
     *     listing against a five-year table, where aggregating once and hash-joining is 123 ms.
     *     Use `ownPerArtist`.
     *   - A widget shows FOUR rows and orders by something else, so the engine evaluates this
     *     for four rows only — four index probes. Aggregating the whole `plays` table to serve
     *     them would be the expensive mistake in the other direction. Use this.
     *
     * Correlated to `artists.id`, so it only composes with a query whose outer table is
     * `artists` — which is the only place an artist's play count can be selected anyway.
     */
    public static function ownCountForArtist(?User $user): QueryBuilder
    {
        return self::ownCountCorrelated('artist_id', 'artists.id', $user, musicOnly: true);
    }

    /** The reader's own listens for the genre an outer query is on — see ownCountForArtist. */
    public static function ownCountForGenre(?User $user): QueryBuilder
    {
        return self::ownCountCorrelated('genre_id', 'genres.id', $user, musicOnly: true);
    }

    /** The reader's own listens for the album an outer query is on — see ownCountForArtist. */
    public static function ownCountForAlbum(?User $user): QueryBuilder
    {
        return self::ownCountCorrelated('collection_id', 'collections.id', $user, musicOnly: false);
    }

    /**
     * The reader's own listens for the track an outer query is on — see ownCountForArtist.
     *
     * No join, unlike its three siblings: `plays.track_id` correlates straight to `tracks.id`,
     * which is the whole question for a single row.
     */
    public static function ownCountForTrack(?User $user): QueryBuilder
    {
        $query = DB::table('plays')
            ->selectRaw('count(*)')
            ->whereColumn('plays.track_id', 'tracks.id');

        return self::scopedToReader($query, $user);
    }

    /**
     * The shared body of the three subject counts: every play whose track points at `$id`
     * through `$column`, counted twice — once in total, once for this reader.
     *
     * "Others" is derived by subtraction rather than asked for separately, exactly as
     * forTrack does it: one fewer query, and the two numbers cannot disagree about the total
     * the way two independent counts could if a play landed between them. A guest gets
     * `own: 0` and every play as somebody else's, which is the honest reading — nobody who is
     * not signed in has a listening history here.
     *
     * @param  string  $column  a `tracks` FK, always a literal from the callers above —
     *                          never a request value, which is what makes the interpolation safe
     */
    private static function ownCountCorrelated(string $column, string $outerColumn, ?User $user, bool $musicOnly): QueryBuilder
    {
        $query = DB::table('plays')
            ->selectRaw('count(*)')
            ->join('tracks', 'plays.track_id', '=', 'tracks.id')
            ->whereColumn("tracks.{$column}", $outerColumn);

        if ($musicOnly) {
            $query->where('tracks.type', TrackType::Music);
        }

        return self::scopedToReader($query, $user);
    }

    /**
     * Narrow a count to the reader, or to nobody when there is no reader.
     *
     * A guest has no listening history at all, so the honest answer is 0 — spelled as an
     * impossible predicate rather than as a separate return, so every caller gets one query
     * shape whether or not somebody is signed in.
     */
    private static function scopedToReader(QueryBuilder $query, ?User $user): QueryBuilder
    {
        return $user === null
            ? $query->whereRaw('1 = 0')
            : $query->where('plays.user_id', $user->id);
    }

    /**
     * The shared body of the three subject counts: every play whose track points at `$id`
     * through `$column`, counted twice — once in total, once for this reader.
     *
     * "Others" is derived by subtraction rather than asked for separately, exactly as
     * forTrack does it: one fewer query, and the two numbers cannot disagree about the total
     * the way two independent counts could if a play landed between them. A guest gets
     * `own: 0` and every play as somebody else's, which is the honest reading — nobody who is
     * not signed in has a listening history here.
     *
     * @param  string  $column  a `tracks` FK, always a literal from the three callers above —
     *                          never a request value, which is what makes the interpolation safe
     * @return array{own: int, others: int}
     */
    private static function forSubject(string $column, string $id, ?User $user, bool $musicOnly): array
    {
        $plays = function () use ($column, $id, $musicOnly) {
            $query = DB::table('plays')
                ->join('tracks', 'plays.track_id', '=', 'tracks.id')
                ->where("tracks.{$column}", $id);

            return $musicOnly ? $query->where('tracks.type', TrackType::Music) : $query;
        };

        $total = $plays()->count();
        $own = $user ? $plays()->where('plays.user_id', $user->id)->count() : 0;

        return ['own' => $own, 'others' => $total - $own];
    }

    /**
     * The shared body of the three grouped counts, aliased to a fixed `subject_id` /
     * `plays` pair so all three listings join and read it identically.
     *
     * Tracks filed under nothing are dropped rather than grouped: a NULL key joins to no
     * parent row anyway, and leaving them in makes the grouped set one row bigger than it
     * can ever be used for.
     *
     * A guest yields no rows (scopedToReader), and the caller's COALESCE turns the missing
     * row into the 0 that is true.
     *
     * @param  string  $column  a `tracks` FK, always a literal from the three callers above
     */
    private static function ownPerSubject(string $column, ?User $user, bool $musicOnly): QueryBuilder
    {
        $query = DB::table('plays')
            ->join('tracks', 'plays.track_id', '=', 'tracks.id')
            ->whereNotNull("tracks.{$column}")
            ->groupBy("tracks.{$column}")
            ->selectRaw("tracks.{$column} as subject_id")
            ->selectRaw('count(*) as plays');

        if ($musicOnly) {
            $query->where('tracks.type', TrackType::Music);
        }

        return self::scopedToReader($query, $user);
    }
}
