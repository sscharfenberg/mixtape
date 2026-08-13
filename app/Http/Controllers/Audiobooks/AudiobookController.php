<?php

namespace App\Http\Controllers\Audiobooks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audiobooks\ShowAudiobookRequest;
use App\Models\AudiobookBookmark;
use App\Models\Collection;
use App\Models\Track;
use App\Services\DataTableService;
use App\Services\Media\CoverService;
use App\Services\Music\QueuePayload;
use App\Services\Player\PlayCounts;
use App\Services\Search\FoldedSearch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One audiobook's page (`GET /audiobooks/{audiobook}`, route `audiobooks.show`, behind auth):
 * a hero of everything known about the book, over its chapters as a server-driven table.
 *
 * SHAPED AFTER AlbumController, because a book is a collection and the two pages answer the
 * same questions — what is this, how long is it, play it, download it — and a reader moving
 * between the areas should not have to learn a second layout. Three things differ, and each
 * follows from the data rather than from taste:
 *
 * - **The credits are LISTS.** An album has one album-artist; a book has as many authors and
 *   narrators as its chapters name, and on this share the anthologies run to six and five.
 *   Both are read through the chapters (`Collection::authors()`), which is where the columns
 *   live, and both arrive as arrays even when there is one name in them.
 * - **The chapter table carries Author and Narrator per row**, which is the whole reason the
 *   author moved onto the track (M1): in an anthology those change from chapter to chapter,
 *   and they are what tells one story from the next.
 * - **No genre and no "add to playlist".** The tracks CHECK forbids an audiobook a genre, and
 *   `PlaylistAdditions` resolves a subject's tracks music-only on purpose — a playlist may
 *   still hold a chapter, it just cannot arrive through a subject button.
 */
class AudiobookController extends Controller
{
    public function __invoke(ShowAudiobookRequest $request, Collection $audiobook, CoverService $covers): Response
    {
        $totals = $this->chapterTotals($audiobook);
        $bookmark = AudiobookBookmark::query()
            ->where('user_id', $request->user()->id)
            ->where('collection_id', $audiobook->id)
            ->first();

        return Inertia::render('Audiobooks/Audiobook/AudiobookPage', [
            // The whole book as queue entries, for the hero's Play / Enqueue. OPTIONAL:
            // never sent with the page, only when the buttons ask for it by name — which on
            // a 673-chapter book is the difference between a page load and a payload nobody
            // browsing wanted. `only: null` so the chapters are not filtered out as
            // non-music; QueuePayload::entry() addresses them by their own type.
            'queueTracks' => Inertia::optional(
                fn (): array => QueuePayload::fromQuery(
                    QueuePayload::query()->where('tracks.collection_id', $audiobook->id),
                    only: null,
                )
            ),
            'table' => $this->chapterTable($request, $audiobook, $bookmark),
            /*
             * WHERE THE READER LEFT OFF, or null for a book they have not started.
             *
             * Its own prop rather than a member of `audiobook`, for the reason the album
             * page's play counts are: it changes while the page is open — the player writes
             * it on a heartbeat — and it must be refreshable on its own rather than dragging
             * the whole hero back with it.
             */
            'bookmark' => $bookmark === null ? null : [
                'trackId' => $bookmark->track_id,
                // Milliseconds into that chapter, raw: the page seeks with it and never
                // prints it, so there is nothing to format.
                'positionMs' => $bookmark->position_ms,
            ],
            // Listening events on this book — the reader's own and everybody else's. The
            // album-grain call, which passes `musicOnly: false`, so it counts chapters:
            // PlayCounts has never been music-only and says so.
            'plays' => PlayCounts::forAlbum($audiobook, $request->user()),
            'audiobook' => [
                'id' => $audiobook->id,
                'name' => $audiobook->name,
                // Qualified columns, both of them: `tracks` is the pivot and has a `name` of
                // its own, so a bare pluck('name') is an ambiguous-column error. The models'
                // docblocks carry the warning.
                'authors' => $audiobook->authors()->orderBy('authors.name')->pluck('authors.name')->all(),
                'narrators' => $audiobook->narrators()->orderBy('narrators.name')->pluck('narrators.name')->all(),
                'year' => $audiobook->year,
                'chapters' => $totals['chapters'],
                // Floored to 1 for the reason the album page floors it: a rip whose files
                // carry no disc tag counts 0 distinct discs, and it is still one disc.
                'discs' => max(1, $totals['discs']),
                'duration' => $totals['duration'],
                'size' => $totals['size'],
                'modifiedAt' => $totals['modifiedAt'],
                // Null when the book has no art, so the hero draws its placeholder rather
                // than pointing an <img> at a 404.
                'coverUrl' => $covers->existsForAlbum($audiobook)
                    ? route('audiobooks.cover', $audiobook->id, absolute: false)
                    : null,
                // Sent unconditionally: working out whether the files are still there means
                // stat-ing every one of them, which on a 673-chapter book is a directory's
                // worth of syscalls per page view to pre-empt a 404 the route itself gives.
                'downloadUrl' => route('audiobooks.download', $audiobook->id, absolute: false),
            ],
        ]);
    }

    /**
     * The book's chapters, as a server-driven DataTable — the thing this page is for.
     *
     * Sorted disc-then-track by default, which is the book's READING ORDER and the only
     * ordering that makes sense to open on; expressing it needs DataTableService's
     * tiebreakers, since the frontend can only ask for one sort key. `name` trails both for
     * rips whose files carry no numbers, which would otherwise page non-deterministically.
     *
     * Author and narrator are left joins so they sort and search as columns. On an ordinary
     * book both repeat down the page and say little; on an anthology they are the two facts
     * that tell one story from the next, which is the case the columns exist for.
     *
     * @return array<string, mixed>
     */
    private function chapterTable(Request $request, Collection $audiobook, ?AudiobookBookmark $bookmark): array
    {
        // An explicit query rather than `$audiobook->tracks()`: a HasMany is not a Builder,
        // and FoldedSearch takes one — a TypeError that would only surface when somebody
        // typed in the search box. The album page carries the same note.
        $query = Track::query()
            ->where('tracks.collection_id', $audiobook->id)
            ->leftJoin('authors', 'tracks.author_id', '=', 'authors.id')
            ->leftJoin('narrators', 'tracks.narrator_id', '=', 'narrators.id')
            ->select([
                'tracks.id',
                'tracks.name',
                'tracks.disc',
                'tracks.track',
                'tracks.duration',
                'authors.name as author_name',
                'narrators.name as narrator_name',
            ])
            // The denominators behind "1/5" and "3/33". Correlated subqueries rather than a
            // per-row lookup, since the table is paginated and an N+1 would be a round trip
            // per row — on a book that is up to 673 of them.
            ->addSelect([
                'disc_total' => DB::table('tracks as sib')
                    ->selectRaw('count(distinct sib.disc)')
                    ->whereColumn('sib.collection_id', 'tracks.collection_id'),
                // NULL-safe, so untagged discs group together instead of reporting 0 tracks
                // for a whole book's worth of files. Spelled as the explicit OR rather than
                // `IS NOT DISTINCT FROM`, which SQLite does not have.
                'track_total' => DB::table('tracks as sib')
                    ->selectRaw('count(*)')
                    ->whereColumn('sib.collection_id', 'tracks.collection_id')
                    ->whereRaw('(sib.disc = tracks.disc or (sib.disc is null and tracks.disc is null))'),
            ]);

        return DataTableService::buildResponse(
            query: $query,
            request: $request,
            sortable: ['disc', 'track', 'name', 'author', 'narrator', 'duration'],
            sortColumnMap: [
                'disc' => 'tracks.disc',
                'track' => 'tracks.track',
                'name' => 'tracks.name',
                'author' => 'authors.name',
                'narrator' => 'narrators.name',
                'duration' => 'tracks.duration',
            ],
            defaultSort: 'disc',
            // All three text columns, through their `name_fold` companions so the search is
            // accent- and case-insensitive. It earns its place here more than anywhere: a
            // 673-chapter book is not something anybody scrolls.
            searchCallback: fn (Builder $q, string $search) => FoldedSearch::apply($q, $search, [
                'tracks.name', 'authors.name', 'narrators.name',
            ]),
            rowMapper: fn (Track $chapter): array => [
                'id' => $chapter->id,
                'disc' => $chapter->disc,
                'discTotal' => (int) $chapter->disc_total,
                'track' => $chapter->track,
                'trackTotal' => (int) $chapter->track_total,
                'name' => $chapter->name,
                'author' => $chapter->author_name,
                'narrator' => $chapter->narrator_name,
                // Raw seconds; the page clocks them against the viewer's locale.
                'duration' => $chapter->duration,
                // What the row's play button loads. The row is NOT a link — a chapter has no
                // page of its own, and the thing a reader wants from a row is to hear it.
                'streamUrl' => route('audiobooks.chapters.stream', $chapter->id, absolute: false),
            ],
            // Sort KEYS, not columns — the service maps them like the primary and hands the
            // survivors back, so the header can mark CD *and* Track as sorted rather than
            // pretending only the first one is.
            tiebreakers: ['disc', 'track', 'name'],
            // OPEN ON THE BOOKMARKED CHAPTER'S PAGE. The owner's question, and the answer is
            // yes: on a 673-chapter book the chapter you left off at is on page 12, which is
            // not somewhere anybody would find by paging.
            defaultPage: $this->pageOfBookmark($audiobook, $bookmark, DataTableService::pageSizeFor($request)),
        );
    }

    /**
     * Which page of the chapter table the bookmarked chapter falls on, or null when there is
     * no bookmark to open at.
     *
     * COUNTS THE ROWS ORDERED BEFORE IT rather than searching for it — one aggregate against
     * the `(collection_id, disc, track)` index, where finding its index by hand would mean
     * hydrating up to 673 rows to look at them.
     *
     * The page size comes from the SERVICE rather than a constant here, so the arithmetic
     * cannot disagree with the response it is aimed at when a reader picks 25 rows.
     *
     * Only meaningful under the DEFAULT ordering, which is why it is not attempted otherwise:
     * a reader who has sorted by narrator is asking a different question, and jumping them to
     * page 12 of that answer would read as the table being broken. The NULL-safe comparisons
     * are the same shape the row totals use, because an untagged chapter has to sort with the
     * other untagged ones rather than dropping out of the count.
     */
    private function pageOfBookmark(Collection $audiobook, ?AudiobookBookmark $bookmark, int $pageSize): ?int
    {
        if ($bookmark === null) {
            return null;
        }

        $chapter = Track::query()
            ->where('collection_id', $audiobook->id)
            ->whereKey($bookmark->track_id)
            ->first(['disc', 'track', 'name']);

        if ($chapter === null) {
            return null;
        }

        $before = Track::query()
            ->where('collection_id', $audiobook->id)
            ->where(fn (Builder $query) => $query
                ->whereRaw('coalesce(tracks.disc, 0) < ?', [$chapter->disc ?? 0])
                ->orWhere(fn (Builder $tie) => $tie
                    ->whereRaw('coalesce(tracks.disc, 0) = ?', [$chapter->disc ?? 0])
                    ->where(fn (Builder $inner) => $inner
                        ->whereRaw('coalesce(tracks.track, 0) < ?', [$chapter->track ?? 0])
                        ->orWhere(fn (Builder $byName) => $byName
                            ->whereRaw('coalesce(tracks.track, 0) = ?', [$chapter->track ?? 0])
                            ->where('tracks.name', '<', $chapter->name)
                        )
                    )
                )
            )
            ->count();

        return intdiv($before, $pageSize) + 1;
    }

    /**
     * The five numbers that describe the book rather than any one chapter.
     *
     * One aggregate query over the `(collection_id, disc, track)` index instead of five, and
     * instead of hydrating 673 rows to count them. `modified_at` stands in for a "modified"
     * date the collections table does not have.
     *
     * @return array{chapters: int, discs: int, duration: float|null, size: int|null, modifiedAt: string|null}
     */
    private function chapterTotals(Collection $audiobook): array
    {
        $totals = Track::query()
            ->where('collection_id', $audiobook->id)
            ->selectRaw('count(*) as chapters')
            ->selectRaw('count(distinct disc) as discs')
            ->selectRaw('sum(duration) as duration')
            ->selectRaw('sum(size) as size')
            ->selectRaw('max(modified_at) as modified_at')
            ->first();

        return [
            'chapters' => (int) $totals?->chapters,
            'discs' => (int) $totals?->discs,
            'duration' => $totals?->duration === null ? null : (float) $totals->duration,
            'size' => $totals?->size === null ? null : (int) $totals->size,
            // Parsed explicitly rather than left to a model cast: a raw aggregate on a select
            // is not the model's own attribute, so no cast applies and it arrives as whatever
            // string the driver formats a timestamp as.
            'modifiedAt' => $totals?->modified_at === null
                ? null
                : Carbon::parse($totals->modified_at)->toIso8601String(),
        ];
    }
}
