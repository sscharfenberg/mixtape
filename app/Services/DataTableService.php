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
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pageSize: int, sort: array{key: string, direction: string}, search: string|null, filters: null}
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

        $paginator = $query
            ->orderBy($sortColumn, $sortDir)
            ->paginate($pageSize);

        return [
            'rows' => $paginator->map($rowMapper)->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'pageSize' => $pageSize,
            'sort' => ['key' => $sortKey, 'direction' => $sortDir],
            'search' => $search,
            'filters' => null,
        ];
    }
}
