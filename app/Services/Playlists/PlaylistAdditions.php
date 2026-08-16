<?php

declare(strict_types=1);

namespace App\Services\Playlists;

use App\Enums\PlaylistSubject;
use App\Enums\TrackType;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\User;
use App\Services\Music\QueuePayload;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Putting tracks INTO a playlist — what the detail-page heroes and the queue menu call. (The
 * `app:playlist` artisan command fills one too, but bluntly and at random; see its docblock.)
 *
 * ONE SERVICE FOR TWO CALLERS THAT NAME THEIR TRACKS DIFFERENTLY, which is the whole shape of
 * this file. A detail page hands over a SUBJECT — "artist X" — and the tracks are worked out
 * here, because the browser does not have them: those pages paginate, so what is on screen is
 * never the whole artist, and making the client fetch a few hundred kilobytes of queue payload
 * to post 900 ids back would be a round trip in front of an INSERT … SELECT. The play queue
 * hands over IDS, because it must: the queue is client state, in the reader's own order, and
 * the server's copy of it (`player_states`) is written late on purpose — asking that copy what
 * is queued would sometimes answer with the queue from a minute ago.
 *
 * NOTHING IS EVER ADDED TWICE. The UI's own rule is that a playlist already holding the whole
 * subject is not offered at all ({@see openTo}), but that answer is computed when the page
 * renders and acted on when the reader presses save, so the two can disagree — a second tab, a
 * queue containing the same song twice, a page left open. {@see append} therefore filters
 * against what is in the playlist NOW, and reports what actually landed rather than what was
 * asked for. Duplicates are not forbidden by the schema (`playlist_tracks` deliberately allows
 * the same track twice, since a hand-built playlist may want it), so this is a decision about
 * this feature rather than a constraint being upheld.
 */
final class PlaylistAdditions
{
    /**
     * How many entries one request may add.
     *
     * A bound rather than a policy: it applies to the ids shape (the play queue, and a track
     * table's ticked rows), where the caller says every track by name. 10,000 is past the whole
     * of this instance's library and about 370 kB of UUIDs — comfortably under the 1 MB body
     * nginx accepts by default, which is the limit that would otherwise decide this for us with
     * a 413 nobody could read.
     */
    public const MAX_TRACKS = 10_000;

    /**
     * How many SUBJECTS one request may name — the other shape's bound.
     *
     * The subject shape needs its own because what it expands to is unbounded: 10,000 genre ids
     * is a small body that resolves to every track in the library several times over, so the
     * ceiling that protects the ids shape protects nothing here. Five hundred is five full
     * pages at the largest size a DataTable offers (DataTableService::ALLOWED_PAGE_SIZES tops
     * out at 100), and a selection survives paging — so it is well past what ticking boxes can
     * plausibly produce while still being a number.
     */
    public const MAX_SUBJECTS = 500;

    /**
     * A query over `tracks` narrowed to one or more subjects OF THE SAME KIND.
     *
     * THE SAME NARROWING THE FOUR DETAIL CONTROLLERS APPLY to build their optional
     * `queueTracks` prop, down to the music-only filter — so "add this artist to a playlist"
     * and "play this artist" can never come to mean different sets of songs. An audiobook
     * chapter is excluded for the reason QueuePayload gives: an artist's narration is not part
     * of "this artist".
     *
     * …EXCEPT FOR `song`, WHICH IS EXACT TRACK IDS AND THEREFORE UNFILTERED. Every other case
     * names a container, where "which of its tracks do you mean" is a real question this app
     * answers with "the music ones". A caller sending `song` has named each track individually;
     * it already knows what it has, and filtering would make a ticked audiobook chapter vanish
     * with nothing to explain it — a button that silently adds nothing on the one page where a
     * chapter is all there is. App\Services\Player\QueueSelection states the identical rule for
     * the play queue, and the two must agree: the same ticked rows feed both.
     *
     * PLURAL BECAUSE A LISTING'S CHECKBOXES NAME SEVERAL AT ONCE, and one kind rather than a
     * mixed bag because that is all a checkbox column can produce: a table lists one kind of
     * thing, so its ticked rows are three albums or three artists, never one of each. The
     * single-subject callers pass a one-element array, which keeps ONE query shape here rather
     * than a scalar path and a plural path that could narrow differently.
     *
     * @param  list<string>  $ids  subjects of the kind `$subject` names
     */
    public static function subjectTracks(PlaylistSubject $subject, array $ids): Builder
    {
        $tracks = DB::table('tracks')->whereIn($subject->column(), $ids);

        return $subject === PlaylistSubject::Song
            ? $tracks
            : $tracks->where('tracks.type', TrackType::Music->value);
    }

    /**
     * The subjects' track ids, in the order the player would play them.
     *
     * Ordered rather than merely collected, because this order becomes the playlist's own: an
     * album arrives as an album, and an artist arrives as records rather than as an
     * alphabetical list of titles. The ordering itself is QueuePayload's — imported rather than
     * repeated, so a playlist built from "add this artist" holds the tracks in the sequence
     * "play this artist" would have played them.
     *
     * With several subjects that order runs ACROSS them rather than subject by subject: four
     * ticked albums interleave by year, because the sort is one ORDER BY over the union and the
     * reader's tick order is not sent (a checkbox is not a position). Adding albums one at a
     * time is what puts them in the playlist one after another.
     *
     * @param  list<string>  $ids  subjects of the kind `$subject` names
     * @return list<string>
     */
    public static function subjectTrackIds(PlaylistSubject $subject, array $ids): array
    {
        $tracks = self::subjectTracks($subject, $ids)
            // The playing order sorts by the album's year and name, so the join has to be here
            // even though nothing is selected from it.
            ->leftJoin('collections', 'tracks.collection_id', '=', 'collections.id')
            ->select('tracks.id');

        return QueuePayload::inPlayingOrder($tracks)->pluck('id')->all();
    }

    /**
     * Which of the user's playlists this subject would actually add something to.
     *
     * The ids only — the names and the reader's own ordering come from the shared `playlists`
     * prop, so a page says which of a list it already has rather than sending a second copy of
     * that list (HandleInertiaRequests holds the one copy).
     *
     * A playlist drops out when it already holds EVERY track of the subject, which is the
     * general form of "only playlists that do not yet have this song": the question is whether
     * pressing save would do anything. A playlist
     * with nine of an album's ten tracks stays, because the tenth is a real addition.
     *
     * THE COMPARISON IS COUNT AGAINST COUNT, in one grouped query rather than one query per
     * playlist. `count(distinct track_id)` and not `count(*)`, because a playlist is allowed to
     * hold the same track twice and two copies of one song must not read as two of the
     * subject's tracks being present.
     *
     * @return list<string>
     */
    public static function openTo(User $user, PlaylistSubject $subject, string $id): array
    {
        $total = self::subjectTracks($subject, [$id])->count();

        // Nothing to add anywhere — an artist with only audiobook chapters, or an id whose rows
        // have been pruned. No playlist is "open" to a subject that is empty, which is what
        // makes the page hide the whole block rather than offer a save that would do nothing.
        if ($total === 0) {
            return [];
        }

        $held = DB::table('playlist_tracks')
            ->join('playlists', 'playlists.id', '=', 'playlist_tracks.playlist_id')
            ->where('playlists.user_id', $user->id)
            // A subquery rather than a list of ids: the ids are already in the database, and a
            // genre's worth of them in an IN clause is a statement the size of the answer.
            ->whereIn('playlist_tracks.track_id', function (Builder $query) use ($subject, $id): void {
                $query->select('tracks.id')
                    ->from('tracks')
                    ->where($subject->column(), $id)
                    ->where('tracks.type', TrackType::Music->value);
            })
            ->groupBy('playlist_tracks.playlist_id')
            ->selectRaw('playlist_tracks.playlist_id as playlist_id, count(distinct playlist_tracks.track_id) as held')
            ->pluck('held', 'playlist_id');

        return Playlist::query()
            ->where('user_id', $user->id)
            ->pluck('id')
            ->reject(fn (string $playlistId): bool => (int) ($held[$playlistId] ?? 0) >= $total)
            ->values()
            ->all();
    }

    /**
     * Append tracks to the end of a playlist, skipping every one it already holds.
     *
     * Returns the ids that ACTUALLY LANDED, in the order they were written — not the count, and
     * not what was asked for. The caller needs the difference: it names the track in the toast
     * when exactly one arrived, and says "already in there" when none did.
     *
     * THREE FILTERS, in this order, and each is a real case rather than defensiveness:
     *
     *   1. the playlist's current contents, because the offer was computed when the page
     *      rendered and pressed some time later (see the class docblock);
     *   2. duplicates within the request, because a play queue may legitimately hold the same
     *      song twice and adding it twice here is not what "add the queue" means;
     *   3. ids with no track, because a queue restored from localStorage can name a file the
     *      scanner has since removed — and a foreign key would answer that with a 500.
     *
     * WRITTEN AS ONE BULK INSERT, and the playlist touched by hand afterwards. Per-row
     * `create()` (what `app:playlist` does, for a dozen rows) would fire PlaylistTrack::$touches
     * once per entry, so adding an artist's 900 tracks would also write the playlist row 900
     * times. The explicit touch is the same compensation PlaylistTrackOrderController makes for
     * the same reason, and it matters for the same reason too: both playlist pages print a
     * "changed" date, and what a listener changes about a playlist is what is IN it.
     *
     * @param  list<string>  $trackIds  ordered; the order becomes the playlist's own
     * @return list<string> the ids appended, in the order they were written
     */
    public static function append(Playlist $playlist, array $trackIds): array
    {
        $fresh = self::freshOnes($playlist, $trackIds);

        if ($fresh === []) {
            return [];
        }

        $next = (int) (DB::table('playlist_tracks')->where('playlist_id', $playlist->id)->max('position') ?? -1) + 1;
        $now = now();

        $rows = [];
        foreach ($fresh as $index => $trackId) {
            $rows[] = [
                'id' => (new PlaylistTrack)->newUniqueId(),
                'playlist_id' => $playlist->id,
                'track_id' => $trackId,
                'position' => $next + $index,
                'created_at' => $now,
            ];
        }

        // In a transaction so an interrupted write cannot leave a gap in `position` — the column
        // is documented as contiguous, and the reorder path renumbers the whole set assuming it.
        DB::transaction(function () use ($playlist, $rows): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                PlaylistTrack::insert($chunk);
            }

            $playlist->touch();
        });

        return $fresh;
    }

    /**
     * The subset of `$trackIds` that is new to this playlist, real, and not repeated — in the
     * order it was given. The three filters are argued in {@see append}, which is the only
     * caller; they live here so that method stays a description of the write.
     *
     * @param  list<string>  $trackIds
     * @return list<string>
     */
    private static function freshOnes(Playlist $playlist, array $trackIds): array
    {
        if ($trackIds === []) {
            return [];
        }

        $held = DB::table('playlist_tracks')
            ->where('playlist_id', $playlist->id)
            ->pluck('track_id')
            ->flip();

        $candidates = [];
        foreach ($trackIds as $trackId) {
            if (! $held->has($trackId) && ! isset($candidates[$trackId])) {
                $candidates[$trackId] = true;
            }
        }

        // One query for "which of these are still in the library", flipped so the filter below
        // is a hash lookup rather than an in_array scan over a queue-sized list.
        $existing = DB::table('tracks')
            ->whereIn('id', array_keys($candidates))
            ->pluck('id')
            ->flip();

        return array_values(array_filter(
            array_keys($candidates),
            fn (string $trackId): bool => $existing->has($trackId)
        ));
    }
}
