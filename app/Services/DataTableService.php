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
     * Build a paginated, sorted, searchable table response array.
     *
     * The sort key is validated against the $sortable whitelist (never trust the
     * URL) and mapped to a real column via $sortColumnMap — needed because the
     * frontend sorts by e.g. `artist` while the DB column is `artists.name` after
     * a join; unmapped keys pass through as-is.
     *
     * @param  Builder|HasMany  $query  Base query, with any joins/selects already applied.
     * @param  Request  $request  Current request (reads sort, dir, page, pageSize, search).
     * @param  string[]  $sortable  Whitelist of allowed sort keys.
     * @param  array<string, string>  $sortColumnMap  Maps a sort key to its actual DB column.
     * @param  string  $defaultSort  Fallback sort key when none/invalid is given.
     * @param  (callable(Builder, string): void)|null  $searchCallback  Applies search filtering; null disables search.
     * @param  callable(mixed): array<string, mixed>  $rowMapper  Transforms each model into a plain row array (must include a string `id`).
     * @param  string[]  $tiebreakers  Sort KEYS appended after the chosen sort, always ascending;
     *                                 mapped through $sortColumnMap like the primary. Always
     *                                 applied; echoed back in `tiebreakers` only while the table
     *                                 is on its default sort — see there.
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pageSize: int, sort: array{key: string, direction: string}, tiebreakers: string[], search: string|null, filters: null}
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
    ): array {
        $sortKey = $request->input('sort', $defaultSort);
        $sortDir = $request->input('dir', $defaultDirection);

        if (! in_array($sortKey, $sortable)) {
            $sortKey = $defaultSort;
        }
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = self::DEFAULT_SORT_DIR;
        }

        $pageSize = (int) $request->input('pageSize', self::DEFAULT_PAGE_SIZE);
        if (! in_array($pageSize, self::ALLOWED_PAGE_SIZES)) {
            $pageSize = self::DEFAULT_PAGE_SIZE;
        }

        $search = $request->input('search');

        if ($search && $searchCallback) {
            $searchCallback($query, $search);
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

        $paginator = $query->paginate($pageSize);

        return [
            'rows' => $paginator->map($rowMapper)->values()->all(),
            'total' => $paginator->total(),
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
            'filters' => null,
        ];
    }
}
