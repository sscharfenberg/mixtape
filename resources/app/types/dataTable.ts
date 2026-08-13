import type { InjectionKey, Ref } from "vue";

/** Column definition for the DataTable component. */
export interface ColumnDef<T extends { id: string }> {
    /** Field name in row data — must match a key on T. */
    key: keyof T & string;
    /** Display label (already translated by the parent). */
    label: string;
    /** Whether this column can be sorted. Default false. */
    sortable?: boolean;
    /** CSS width value. Default 'auto'. */
    width?: string;
    /** Show this column in the mobile card layout. Default false. */
    visibleInCard?: boolean;
    /** Primary column shown at the top of the card view. Only the first wins. */
    cardPrimary?: boolean;
    /**
     * Render this column as the card's leading MEDIA — artwork beside the card's
     * heading — instead of as one of its label/value fields. Only the first wins, and
     * it needs a `#cell-{key}` slot to render (a bare value would be a URL string).
     * Independent of `visibleInCard`: a media column is positioned, not listed.
     */
    cardMedia?: boolean;
    /** Text alignment. Default 'left'. */
    align?: "left" | "center" | "right";
    /** Extra CSS class(es) applied to the <td> for this column. */
    cellClass?: string;
}

/** Server response shape for paginated, sortable table data. */
export interface TableResponse<T> {
    rows: T[];
    total: number;
    /**
     * How many rows the table holds with NO search applied — the same number as `total`
     * unless a search is narrowing it.
     *
     * What the pager's visibility is decided by, and deliberately not `total`: a search that
     * leaves one row out of five hundred still wants its pager, and deciding on the filtered
     * count would make the whole control appear and disappear as the reader types.
     */
    totalUnfiltered: number;
    page: number;
    /** null means no pagination. */
    pageSize: number | null;
    sort: { key: string; direction: "asc" | "desc" } | null;
    /**
     * Column keys to mark as also sorted ascending — an album's tracks are ordered
     * disc-then-track, and both headers should say so. Marked but not the sort: clicking
     * one sorts by it from scratch, so only `sort` drives the toggle.
     *
     * The server sends these only while the table is on its DEFAULT sort, where they are
     * the order being read. Under a chosen sort it sends none, even though they still
     * order the query — see DataTableService. Absent for tables that pass no tiebreakers.
     */
    tiebreakers?: string[];
    search: string | null;
    /**
     * Which search ran — `"name"` when the listing was narrowed to the row's own name, null for
     * its own (usually wider) default.
     *
     * It exists because two surfaces disagreed about one query: the cross-kind search dropdown
     * matches a row's own name, the Songs listing also matches artist, album and genre, so "show
     * all 70 songs" opened a table of 2,000+. The dropdown now links with `?searchIn=name` and this
     * is the server saying it applied — which is what lets the toolbar announce the narrowing and
     * offer the way out of it. See `App\Services\DataTableService::SEARCH_IN_NAME`.
     *
     * OPTIONAL like `tiebreakers`, and for the same reason: DataTableService always sends it, so
     * nothing can forget to — and every hand-written fixture in the test suite would otherwise have
     * to carry a field its assertions never look at.
     */
    searchIn?: string | null;
    /** Reserved for future column/faceted filtering. v1: always null. */
    filters: Record<string, string | string[]> | null;
}

/** Internal normalized sort entry. */
export interface SortEntry {
    key: string;
    direction: "asc" | "desc";
}

/** Selection state managed by DataTable. */
export interface SelectionState {
    ids: string[];
}

/** Shape provided by DataTable to child components. */
export interface DataTableProvide {
    /** Currently selected row IDs. */
    selectedIds: Ref<string[]>;
    /** Toggle selection for a single row. */
    toggleSelection: (id: string) => void;
    /** Select/deselect all visible rows on the current page. */
    togglePageSelection: (ids: string[]) => void;
}

/** Injection key for the DataTable provide/inject pattern. */
export const DATA_TABLE_KEY = Symbol("DataTable") as InjectionKey<DataTableProvide>;
