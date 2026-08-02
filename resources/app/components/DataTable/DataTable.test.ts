import { beforeEach, describe, expect, it, vi } from "vitest";
import type { Component } from "vue";
import { resetInertia } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import type { ColumnDef, TableResponse } from "Types/dataTable";
import DataTable from "./DataTable.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * When the pagination bar is worth drawing.
 *
 * It is not, for a table whose contents fit on one page at EVERY page size the Select
 * offers — the control collapses to "1–7 / 7" beside a dropdown that cannot change
 * anything. A genre with three albums or an EP with four tracks is the common case.
 *
 * The decision is made on the UNFILTERED count, which is the part worth pinning: deciding
 * on the filtered one would make the whole bar appear and vanish as somebody types in the
 * search box, moving everything below it on every keystroke. So a big table keeps its pager
 * even when a search has narrowed it to a single row.
 */

/** One row of the throwaway table. */
interface Row {
    id: string;
    name: string;
}

const columns: ColumnDef<Row>[] = [{ key: "name", label: "Name", sortable: true }];

/** A table response with `n` rows, and whatever totals a test needs. */
const response = (overrides: Partial<TableResponse<Row>> = {}): TableResponse<Row> => ({
    rows: [{ id: "row-1", name: "Only Row" }],
    total: 1,
    totalUnfiltered: 1,
    page: 1,
    pageSize: 50,
    sort: { key: "name", direction: "asc" },
    search: null,
    filters: null,
    ...overrides
});

/**
 * Mount a table over that response.
 *
 * The cast is unavoidable: DataTable is a GENERIC SFC (`<script setup generic="T …">`), and
 * such a component's type is a generic function rather than the plain `Component` that
 * @vue/test-utils (and so mountApp) accepts. Casting here keeps the widening local to the
 * one caller that needs it; the values passed are still typed, because `columns` and
 * `response()` are.
 */
const table = (overrides: Partial<TableResponse<Row>> = {}) =>
    mountApp(DataTable as unknown as Component, {
        props: { columns, response: response(overrides), hasActions: false }
    });

/** Is the pagination bar in the DOM at all? */
const hasPager = (wrapper: ReturnType<typeof table>): boolean => wrapper.find(".dt-pagination").exists();

describe("DataTable pagination visibility", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("hides the bar for a table that fits on one page at any page size", () => {
        // 7 rows: no page to go to, and 25/50/100 all show the lot.
        expect(hasPager(table({ total: 7, totalUnfiltered: 7 }))).toBe(false);
    });

    it("hides it at exactly the smallest page size, where nothing could still page", () => {
        expect(hasPager(table({ total: 25, totalUnfiltered: 25 }))).toBe(false);
    });

    it("shows it one row later, where choosing 25 would make two pages", () => {
        // The boundary that matters: at 26 the default of 50 still fits everything, but the
        // reader can pick 25 — so the control has something to do.
        expect(hasPager(table({ total: 26, totalUnfiltered: 26 }))).toBe(true);
    });

    it("shows it for a large table", () => {
        expect(hasPager(table({ total: 500, totalUnfiltered: 500 }))).toBe(true);
    });

    it("keeps the bar when a search narrows a large table to a single row", () => {
        /*
         * The reason the decision is made on the unfiltered count. Judged on `total` this
         * bar would vanish mid-search and come back on backspace, shifting the page under
         * the reader on every keystroke — and it would also stop telling them that one row
         * of five hundred matched.
         */
        expect(hasPager(table({ total: 1, totalUnfiltered: 500, search: "narrowed" }))).toBe(true);
    });

    it("hides it for a small table even while searching", () => {
        // The mirror case: a table that never needed a pager does not grow one because
        // somebody typed.
        expect(hasPager(table({ total: 1, totalUnfiltered: 7, search: "narrowed" }))).toBe(false);
    });

    it("hides it when the server paginates nothing at all", () => {
        // `pageSize: null` is the server's "this table is not paginated" signal.
        expect(hasPager(table({ pageSize: null, total: 500, totalUnfiltered: 500 }))).toBe(false);
    });
});
