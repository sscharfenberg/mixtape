import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import type { Component } from "vue";
import { emitRouterEvent, resetInertia, routerCalls } from "Testing/inertia";
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

/** One row of the throwaway table. `href` makes it clickable, and so prefetchable. */
interface Row {
    id: string;
    name: string;
    href?: string;
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

/*
 * Hovering a row warms the page it leads to.
 *
 * A row is the app's main way into a detail page and it navigates through `router.visit`,
 * not a <Link>, so it gets none of Inertia's built-in link prefetching for free — this is
 * the code that stands in for it. What is worth pinning is the *intent delay*: without it,
 * running the pointer down a 25-row table on the way to the scrollbar would fire 25
 * requests at the server.
 */
describe("DataTable row prefetching", () => {
    beforeEach(() => {
        resetInertia();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    /** The clickable-row table these tests hover over. */
    const clickable = () => table({ rows: [{ id: "row-1", name: "Only Row", href: "/music/songs/row-1" }] });

    /** Every URL handed to router.prefetch so far. */
    const prefetched = (): (string | undefined)[] =>
        routerCalls.filter(call => call.method === "prefetch").map(call => call.url);

    it("fetches the row's page once the pointer has rested on it", async () => {
        const wrapper = clickable();

        await wrapper.find(".dt-body__row--clickable").trigger("mouseenter");
        vi.advanceTimersByTime(100);

        expect(prefetched()).toStrictEqual(["/music/songs/row-1"]);
    });

    it("fetches nothing while the pointer is only passing through", async () => {
        // The reason the delay exists: a pointer crossing the table is not a reader
        // choosing a row.
        const wrapper = clickable();
        const row = wrapper.find(".dt-body__row--clickable");

        await row.trigger("mouseenter");
        vi.advanceTimersByTime(50);
        await row.trigger("mouseleave");
        vi.advanceTimersByTime(100);

        expect(prefetched()).toStrictEqual([]);
    });

    it("fetches nothing for a row that leads nowhere", async () => {
        const wrapper = table({ rows: [{ id: "row-1", name: "Only Row" }] });

        await wrapper.find("tbody tr").trigger("mouseenter");
        vi.advanceTimersByTime(100);

        expect(prefetched()).toStrictEqual([]);
    });
});

/*
 * The loading overlay belongs to NAVIGATIONS, and a prefetch is not one.
 *
 * Inertia fires the same `start` / `finish` events for a hover prefetch as for a real visit,
 * so before this guard existed, running the pointer across the table — which is exactly what
 * arms the row prefetch above — flashed the spinner over rows nobody was going to. Caught in
 * the browser, pinned here because the events are indistinguishable without reading `prefetch`.
 */
describe("DataTable loading overlay", () => {
    beforeEach(() => {
        resetInertia();
    });

    /** An Inertia router event carrying a visit, as the real ones do. */
    const visitEvent = (prefetch: boolean) => ({ detail: { visit: { prefetch, only: [], completed: true } } });

    /** Is the spinner overlay on screen? */
    const hasOverlay = (wrapper: ReturnType<typeof table>): boolean => wrapper.find(".dt__overlay").exists();

    it("covers the table while a real visit is in flight", async () => {
        const wrapper = table();

        emitRouterEvent("start", visitEvent(false));
        await nextTick();

        expect(hasOverlay(wrapper)).toBe(true);
    });

    it("stays out of the way for a hover prefetch", async () => {
        const wrapper = table();

        emitRouterEvent("start", visitEvent(true));
        await nextTick();

        expect(hasOverlay(wrapper)).toBe(false);
    });

    it("is not lifted early by a prefetch finishing during a real visit", async () => {
        // The mirror case: guard only `start` and a prefetch settling mid-navigation clears
        // an overlay that should still be up.
        const wrapper = table();

        emitRouterEvent("start", visitEvent(false));
        emitRouterEvent("finish", visitEvent(true));
        await nextTick();

        expect(hasOverlay(wrapper)).toBe(true);
    });

    it("lifts when the real visit finishes", async () => {
        const wrapper = table();

        emitRouterEvent("start", visitEvent(false));
        emitRouterEvent("finish", visitEvent(false));
        await nextTick();

        expect(hasOverlay(wrapper)).toBe(false);
    });
});
