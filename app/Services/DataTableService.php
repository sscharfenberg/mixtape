<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;

/**
 * Builds the paginated / sorted / searchable payload the frontend DataTable
 * consumes (the TableResponse<T> contract in resources/app/types/dataTable.ts).
 * Ported from cantrip.me. The component is server-driven: sort/page/search live
 * in the URL query, the controller hands its base query here, and this validates
 * the inputs, applies them, and shapes the response — so the controller stays a
 * few lines and every table behaves identically.
 */
class DataTableService
{
    /** @var int[] The only page sizes the frontend Select offers; anything else is coerced. */
    private const ALLOWED_PAGE_SIZES = [25, 50, 100];

    private const DEFAULT_PAGE_SIZE = 50;

    private const DEFAULT_SORT_DIR = 'asc';

    /**
     * The value of `?searchIn=` that narrows a listing's search to the row's OWN NAME.
     *
     * WHY A LISTING NEEDS TWO SEARCHES AT ALL. The Songs listing matches title, artist, album AND
     * genre, which is the right default for somebody who has arrived to browse — but the cross-kind
     * search dropdown matches a row's own name only, so its hand-off says "show all 70 songs" and
     * lands on a table of 2,000+: "godspeed you black emperor" and every band filed under Black
     * Metal, none of which is a song called Black. Two numbers for one query, and the reader has no
     * way to tell which is lying.
     *
     * So the mode travels in the URL beside the query. The dropdown links with it, the wide search
     * stays the default for anyone who came to the listing directly, and both remain bookmarkable —
     * which a hidden preference or a session flag would not be.
     */
    public const SEARCH_IN_NAME = 'name';

    /**
     * The page size this request is in force with — its own, coerced to one the frontend
     * offers, or the default.
     *
     * Public because a caller sometimes has to reason about WHICH page a particular row falls
     * on: the audiobook page opens on the reader's bookmarked chapter, and working that out
     * means dividing by exactly the size the response will use. Asking here is what stops the
     * two drifting the moment somebody picks 25 rows in the Select.
     */
    public static function pageSizeFor(Request $request): int
    {
        $pageSize = (int) $request->input('pageSize', self::DEFAULT_PAGE_SIZE);

        return in_array($pageSize, self::ALLOWED_PAGE_SIZES) ? $pageSize : self::DEFAULT_PAGE_SIZE;
    }

    /**
     * Build a paginated, sorted, searchable table response array.
     *
     * The sort key is validated against the $sortable whitelist (never trust the
     * URL) and mapped to a real column via $sortColumnMap — needed because the
     * frontend sorts by e.g. `artist` while the DB column is `artists.name` after
     * a join; unmapped keys pass through as-is.
     *
     * @param  Builder|HasMany  $query  Base query, with any joins/selects already applied.
     * @param  Request  $request  Current request (reads sort, dir, page, pageSize, search).
     * @param  int|null  $defaultPage  page to open on when the request names none — see below
     * @param  string[]  $sortable  Whitelist of allowed sort keys.
     * @param  array<string, string>  $sortColumnMap  Maps a sort key to its actual DB column.
     * @param  string  $defaultSort  Fallback sort key when none/invalid is given.
     * @param  (callable(Builder, string): void)|null  $searchCallback  Applies search filtering; null disables search.
     * @param  (callable(Builder, string): void)|null  $nameSearchCallback  The NARROW search — the row's own name
     *                                                                      alone — used when the request carries
     *                                                                      `?searchIn=name`. Null where a listing has no
     *                                                                      narrower reading than its default (the Artists
     *                                                                      and Genres tables already match one column), and
     *                                                                      the mode is then ignored rather than faked.
     * @param  callable(mixed): array<string, mixed>  $rowMapper  Transforms each model into a plain row array (must include a string `id`).
     * @param  string[]  $tiebreakers  Sort KEYS appended after the chosen sort, always ascending;
     *                                 mapped through $sortColumnMap like the primary. Always
     *                                 applied; echoed back in `tiebreakers` only while the table
     *                                 is on its default sort — see there.
     * @param  array<string, string|list<string>>|null  $filters  What is narrowing this table BESIDES
     *                                                            the search, echoed back untouched — the caller applies it to
     *                                                            the query itself (only the caller knows what its filters mean).
     *                                                            See the response key for what the frontend does with it.
     * @return array{rows: array<int, array<string, mixed>>, total: int, totalUnfiltered: int, page: int, pageSize: int, sort: array{key: string, direction: string}, tiebreakers: string[], search: string|null, searchIn: string|null, filters: array<string, string|list<string>>|null}
     */
    public static function buildResponse(
        Builder|HasMany $query,
        Request $request,
        array $sortable,
        array $sortColumnMap,
        string $defaultSort,
        ?callable $searchCallback,
        callable $rowMapper,
        string $defaultDirection = self::DEFAULT_SORT_DIR,
        array $tiebreakers = [],
        ?callable $nameSearchCallback = null,
        ?int $defaultPage = null,
        ?array $filters = null,
    ): array {
        $sortKey = $request->input('sort', $defaultSort);
        $sortDir = $request->input('dir', $defaultDirection);

        if (! in_array($sortKey, $sortable)) {
            $sortKey = $defaultSort;
        }
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = self::DEFAULT_SORT_DIR;
        }

        $pageSize = self::pageSizeFor($request);

        /*
         * NORMALISED, NOT VALIDATED, like the three parameters above it — and `search` is the
         * one that was neither. `?search[]=a` arrives as an ARRAY and went straight to the
         * caller's callback, which hands it to `FoldedSearch::fold(?string)`: a TypeError, i.e.
         * a 500 on six listings from a hand-written URL.
         *
         * A FormRequest is the house rule for input (CLAUDE.md), and it is deliberately not
         * used here: these four parameters answer a bad value by FALLING BACK, not by refusing.
         * A shared `/music/songs?sort=bogus` should render the default sort, not a 422 — the URL
         * is the table's state and readers pass it around. Validation that rejected would turn
         * every stale or hand-edited link into an error page.
         */
        $search = $request->input('search');
        $search = is_string($search) ? $search : null;

        /*
         * Which of the two searches this request wants — see SEARCH_IN_NAME for why there are two.
         *
         * The narrow one is used only when the caller HAS one: a listing whose wide search is
         * already a single column has nothing to narrow, so `?searchIn=name` there is silently the
         * same search rather than a mode the response would claim to be in and the toolbar would
         * offer a way out of.
         */
        $narrowed = $nameSearchCallback !== null && $request->input('searchIn') === self::SEARCH_IN_NAME;
        $applySearch = $narrowed ? $nameSearchCallback : $searchCallback;

        /*
         * How big the table is with NO search applied, which is what decides whether the
         * pager is worth drawing at all (see `totalUnfiltered` in the response).
         *
         * Counted BEFORE the search callback runs, and only when there is a search to
         * matter: with no search the filtered total already IS the unfiltered one, so the
         * common request pays nothing. When there is one, this is a second COUNT — the
         * price of a pager that does not appear and vanish as somebody types.
         */
        $totalUnfiltered = $search && $applySearch ? (clone $query)->toBase()->count() : null;

        if ($search && $applySearch) {
            $applySearch($query, $search);
        }

        $sortColumn = $sortColumnMap[$sortKey] ?? $sortKey;

        $query->orderBy($sortColumn, $sortDir);

        // Tiebreakers, and they do two jobs. The obvious one is a multi-column natural
        // order the frontend cannot express with a single sort key: an album's tracks read
        // disc-then-track, not "disc, then whatever the engine returns". The quiet one
        // matters to every table — SQL guarantees no order at all between rows the sort
        // column can't separate, so with hundreds of albums sharing a year, or two songs
        // sharing a title, a row can appear on page 1 AND page 2 across two requests.
        // Appending a unique-ish column makes paging deterministic.
        //
        // Always ascending: a tiebreak is about determinism, not about the reader's intent,
        // and tracks within a disc still read 1, 2, 3 when the discs are reversed. The
        // chosen column is skipped, since ordering by it twice is noise in the SQL.
        $applied = [];

        foreach ($tiebreakers as $tiebreakKey) {
            $tiebreakColumn = $sortColumnMap[$tiebreakKey] ?? $tiebreakKey;

            if ($tiebreakColumn === $sortColumn) {
                continue;
            }

            $query->orderBy($tiebreakColumn);
            $applied[] = $tiebreakKey;
        }

        /*
         * WHICH PAGE TO OPEN ON when the reader has not asked for one.
         *
         * Normally the first, and `paginate()` reads `?page=` for itself. `$defaultPage` is
         * for a caller that knows something better: the audiobook page opens on the chapter
         * the reader left off at, which on a 673-chapter book is page 12 and not somewhere a
         * reader would ever find by paging.
         *
         * It applies ONLY when the request carries no page of its own — asking for page 1
         * explicitly has to mean page 1, or the pager's first button would bounce back to the
         * bookmark and the table would be unusable.
         */
        $page = $request->has('page') ? null : $defaultPage;

        $paginator = $query->paginate($pageSize, ['*'], 'page', $page);

        return [
            'rows' => $paginator->map($rowMapper)->values()->all(),
            'total' => $paginator->total(),
            /*
             * The same number as `total` unless a search is narrowing the table, and the one
             * the frontend hides the pager by. Deliberately the UNFILTERED count: a search
             * that leaves one row out of five hundred still wants its pager, both to say so
             * and to offer a page size — and deciding on the filtered count instead would
             * make the whole control appear and disappear while the reader types.
             */
            'totalUnfiltered' => $totalUnfiltered ?? $paginator->total(),
            'page' => $paginator->currentPage(),
            'pageSize' => $pageSize,
            'sort' => ['key' => $sortKey, 'direction' => $sortDir],
            // Which tiebreakers to ADVERTISE, which is not the same as which are applied
            // (they all are, always — paging stability doesn't get to be optional).
            //
            // Only while the table sits on its DEFAULT sort. There, the extra keys are the
            // natural order a reader is looking at — an album's tracks really are disc,
            // then track, and marking only "disc" understates it. Once someone sorts by
            // something else, those same keys are invisible plumbing: durations are near
            // unique, so disc/track almost never separates two rows, and four columns
            // wearing an ascending arrow while the reader picked one is noise that makes
            // the marking mean less rather than more.
            'tiebreakers' => $sortKey === $defaultSort ? $applied : [],
            'search' => $search,
            /*
             * Which search ran, so the toolbar can say so and offer the way out. Null unless the
             * narrow one actually applied — a mode the table is not really in is worse than no
             * mode at all, because the chip announcing it would be a lie the reader can click.
             */
            'searchIn' => $narrowed ? self::SEARCH_IN_NAME : null,
            /*
             * WHAT ELSE IS NARROWING THIS TABLE, straight back out of the caller's hands.
             *
             * This service does not apply it and cannot: a filter is a predicate only the
             * listing understands (SongFilter is four different `where`s). What it is echoed
             * for is the FRONTEND — DataTable watches the serialised sort/search/filters and
             * drops its row selection when any of them changes, which a filtered table needs
             * as much as a searched one: the rows underneath a set of ticks are no longer the
             * same rows.
             */
            'filters' => $filters,
        ];
    }
}
