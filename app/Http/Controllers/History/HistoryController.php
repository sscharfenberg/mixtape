<?php

declare(strict_types=1);

namespace App\Http\Controllers\History;

use App\Enums\TrackType;
use App\Http\Controllers\Controller;
use App\Http\Requests\History\ShowHistoryRequest;
use App\Models\Play;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WHAT THIS READER HAS LISTENED TO, AND WHEN (`GET /history`, route `history`, behind auth).
 *
 * The other half of `plays`, which the app has been WRITING since the player was built and
 * until now only ever read as an aggregate — the most-played widgets count these rows without
 * ever saying what any of them were. This page is the one place the events themselves are
 * shown, which is also what makes a half-finished evening easy to pick up again.
 *
 * IT PAGES OVER DAYS, NOT OVER PLAYS, and that is the whole shape of it. A listening history
 * read as a flat feed answers "what did I play recently" and nothing else; read as days it
 * answers "what did I put on last Saturday", which is the question somebody actually arrives
 * with. So the unit of the page — of the accordion, of the pager, of `LIMIT` — is a day that
 * had listening in it. Twenty-five of them is about a month for a daily listener and a year
 * for an occasional one, which is the right amount of scrolling for both.
 *
 * `date(played_at)` IS PORTABLE, which is why the grouping is spelled that way rather than
 * with `to_char` or `strftime`. Postgres serves this app and sqlite serves its tests, and both
 * answer `date(x)` with `YYYY-MM-DD` (so does MySQL); the driver-specific spellings would need
 * a `match` on the connection to say the same thing twice. Verified against both.
 *
 * THE DAY BOUNDARY IS THE APPLICATION'S, not the reader's browser's — `config('app.timezone')`,
 * which is what `played_at` is stored in. A per-reader boundary would mean sending a timezone
 * up with the request and grouping by it, and the case it would fix is narrow: only listening
 * between local midnight and the application's midnight lands on the day before. For a European
 * reader that is the small hours, where keeping the evening together in one section is arguably
 * the better answer anyway.
 *
 * TWO QUERIES, AND THE SECOND IS A RANGE. Having the page's days, the plays on them could be
 * fetched with `whereIn(date(played_at), …)` — a scan, since no index can serve an expression.
 * But the days on a page are CONSECUTIVE in the descending sequence of days that have plays, so
 * everything between the oldest and the newest of them belongs to one of them: a `BETWEEN` on
 * `played_at` answers exactly the same rows and rides `plays (user_id, played_at)`, the index
 * the migration calls "a user's history feed".
 */
class HistoryController extends Controller
{
    /**
     * How many DAYS one page holds. Not the reader's to change — see the request.
     */
    private const DAYS_PER_PAGE = 25;

    /**
     * The reader's own listening, newest day first, with each day's plays newest first.
     *
     * Every play on the page's days travels WITH the page rather than being fetched when a
     * section is opened. One request rather than one per day: the accordion's panels are
     * `v-if`, so nothing is built until it is opened, and the payload is bounded by the number
     * of listens a person can physically have — a day of solid listening is a few hundred
     * three-field rows. A per-day endpoint would trade that for a spinner on every click.
     */
    public function __invoke(ShowHistoryRequest $request): Response
    {
        $userId = $request->user()->id;

        // The days themselves — the thing being paged. The day is all that is selected: the
        // count each section's header shows is taken from the rows that section actually opens
        // onto, so a `count(*)` here would be a second answer to the same question and could
        // disagree with the first (see `plays()`, which drops a play whose track has gone).
        $days = Play::query()
            ->where('user_id', $userId)
            ->selectRaw('date(played_at) as day')
            ->groupBy(DB::raw('date(played_at)'))
            ->orderByDesc(DB::raw('date(played_at)'))
            ->paginate(self::DAYS_PER_PAGE);

        return Inertia::render('History/HistoryPage', [
            'days' => $this->days($days->items(), $userId),
            'page' => $days->currentPage(),
            'perPage' => self::DAYS_PER_PAGE,
            // DAYS, not plays: it is what the pager counts, and what "1–25 / 63" has to mean
            // for the numbers either side of it to agree.
            'totalDays' => $days->total(),
        ]);
    }

    /**
     * The page's days, each carrying its own listens.
     *
     * The plays are fetched once for the whole page and handed out by day here rather than a
     * query per section — twenty-five queries to draw one page is the N+1 this shape exists to
     * avoid, and the range they come back in is exactly the page's own span.
     *
     * @param  list<object>  $rows  the paginated day rows, each carrying only `day`
     * @param  string  $userId  whose history this is — the plays query is scoped again, since a
     *                          range on its own would answer for everybody
     * @return list<array<string, mixed>> days in the shape `HistoryDay` expects
     */
    private function days(array $rows, string $userId): array
    {
        if ($rows === []) {
            return [];
        }

        // Newest first, so the last row is the oldest day on this page. The window is the whole
        // of both: from the start of the oldest to the end of the newest.
        $newest = (string) $rows[0]->day;
        $oldest = (string) $rows[array_key_last($rows)]->day;

        $plays = Play::query()
            ->where('user_id', $userId)
            ->whereBetween('played_at', [$oldest.' 00:00:00', $newest.' 23:59:59'])
            // The FKs are selected alongside the names because the relations are resolved
            // through them — a column list that leaves one out silently loads null.
            ->with([
                'track:id,name,type,artist_id,author_id,collection_id',
                'track.artist:id,name',
                'track.author:id,name',
                'track.collection:id,name',
            ])
            ->orderByDesc('played_at')
            ->get()
            ->groupBy(fn (Play $play): string => $play->played_at->format('Y-m-d'));

        return array_map(function (object $row) use ($plays): array {
            $rows = $this->plays($plays->get((string) $row->day) ?? new Collection);

            return [
                'date' => (string) $row->day,
                // COUNTED OFF THE ROWS THEMSELVES rather than asked of the database: a header
                // saying "12 Titel" over a section that opens onto eleven is worse than either
                // number alone, and the two can differ — `plays()` drops a listen whose track
                // has gone, in the window between a scan removing a file and the FK cascade.
                'count' => count($rows),
                'plays' => $rows,
            ];
        }, $rows);
    }

    /**
     * One day's listens as rows the page can draw.
     *
     * A ROW IS FOR READING, NOT FOR PLAYING, which is why this is shaped here rather than
     * through `QueuePayload::entry()`. Nothing on this page starts audio — a row is a link to
     * the thing that was listened to — so it carries no stream URL, no cover and no duration,
     * and it carries one thing a queue entry has no use for: an audiobook chapter's AUTHOR.
     * That is the field the two shapes genuinely disagree about (docs/audiobooks.md — an
     * audiobook's author hangs off the chapter, beside the narrator, where a song's credit is
     * its artist), and it is exactly what a history row wants to show.
     *
     * A play whose track has gone is dropped rather than drawn blank. `plays.track_id` cascades
     * on delete, so this is only reachable for the moment between a scan removing a file and the
     * cascade landing — but a row naming nothing, linking nowhere, is worse than one fewer row.
     *
     * @param  Collection<int, Play>  $plays  one day's plays, already newest first
     * @return list<array<string, mixed>> rows in the shape `HistoryPlay` expects
     */
    private function plays(Collection $plays): array
    {
        return $plays
            ->map(function (Play $play): ?array {
                $track = $play->track;

                if ($track === null) {
                    return null;
                }

                // Compared as the ENUM rather than parsed out of a string, unlike
                // QueuePayload's identical-looking line: that one shapes a raw query row where
                // `type` is still the column's text, and this is an Eloquent model where the
                // cast has already turned it into a TrackType.
                $audiobook = $track->type === TrackType::Audiobook;

                return [
                    'id' => $play->id,
                    // Raw, like every instant this app sends — the page prints the clock in the
                    // reader's own locale and timezone.
                    'playedAt' => $play->played_at->toIso8601String(),
                    'kind' => $audiobook ? TrackType::Audiobook->value : TrackType::Music->value,
                    'name' => $track->name,
                    // The credit this KIND of thing is known by, under one key: a song is its
                    // artist's, a chapter is its author's. One field rather than two, because the
                    // row shows one pip and `kind` already says which of the two it is holding.
                    'creator' => $audiobook ? $track->author?->name : $track->artist?->name,
                    'container' => $track->collection?->name,
                    // A chapter has no page of its own, so it points at its BOOK — the same
                    // destination, and the same fallback, a queue row uses.
                    'href' => $audiobook
                        ? ($track->collection_id === null
                            ? route('audiobooks', absolute: false)
                            : route('audiobooks.show', $track->collection_id, absolute: false))
                        : route('music.songs.show', $track->id, absolute: false),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
