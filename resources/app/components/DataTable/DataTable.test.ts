import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import type { Component } from "vue";
import { emitRouterEvent, resetInertia, routerCalls, routerListenerCount } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
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

    it("unsubscribes from the router when it goes away", () => {
        /*
         * `router.on` registers on the GLOBAL router, which outlives this component — and a
         * DataTable unmounts on every navigation away from a listing. Dropping the returned
         * unsubscribe therefore leaks a pair of handlers per visit, each still writing to a
         * dead ref, and nothing on screen ever says so.
         */
        const before = { start: routerListenerCount("start"), finish: routerListenerCount("finish") };

        const wrapper = table();
        expect(routerListenerCount("start")).toBe(before.start + 1);
        expect(routerListenerCount("finish")).toBe(before.finish + 1);

        wrapper.unmount();

        expect(routerListenerCount("start")).toBe(before.start);
        expect(routerListenerCount("finish")).toBe(before.finish);
    });
})

/*
 * THE NARROWING CHIP. A listing reached from the cross-kind search dropdown matches titles only
 * (`?searchIn=name`), because that is what the dropdown counted — and a table quietly showing fewer
 * rows than its own search would find is the same confusion the mode exists to fix, pointing the
 * other way. So the mode has to announce itself, and pressing the chip has to drop it.
 *
 * Asserted here rather than in Playwright because all of it is markup and one router call: whether
 * the chip is drawn, and what URL widening asks for.
 */
describe("DataTable narrowed search", () => {
    const chip = (wrapper: ReturnType<typeof table>) => wrapper.find(".dt-toolbar__mode");

    it("draws no chip for a listing running its own search", () => {
        expect(chip(table({ search: "black" })).exists()).toBe(false);
    });

    it("says so when the server narrowed the search to the name", () => {
        const wrapper = table({ search: "black", searchIn: "name" });

        expect(chip(wrapper).exists()).toBe(true);
        expect(chip(wrapper).text()).toContain(translate("components.datatable.search_in_name"));
    });

    /** A mode the server did not apply must not be announced — the chip would be a clickable lie. */
    it("ignores a mode the server did not echo back", () => {
        expect(chip(table({ search: "black", searchIn: null })).exists()).toBe(false);
    });

    /**
     * The URL is set for real here, because that is where `buildUrl` reads the state it PRESERVES
     * from — the assertion that matters is which parameters survive widening and which one does
     * not, and against a bare location every one of them would trivially be absent.
     */
    it("drops the mode and returns to page 1 when pressed, keeping the rest of the URL", async () => {
        const original = window.location.href;
        window.history.replaceState({}, "", "/music/songs?search=black&searchIn=name&sort=name&dir=desc");

        try {
            const wrapper = table({ search: "black", searchIn: "name", page: 3 });

            await chip(wrapper).trigger("click");

            // Indexed rather than `.at(-1)`: the project targets `lib: ES2020`, where that method
            // does not exist as far as vue-tsc is concerned (docs/testing.md → Traps).
            const visits = routerCalls.filter(call => call.method === "get");
            const visit = visits[visits.length - 1];
            expect(visit).toBeDefined();
            // Dropped rather than set to anything: ABSENT is the wide search.
            expect(visit.url).not.toContain("searchIn");
            expect(visit.url).toContain("page=1");
            // …and everything the chip has no opinion about survives — widening is about WHERE the
            // query looks, not what it is or how the table is sorted.
            expect(visit.url).toContain("search=black");
            expect(visit.url).toContain("sort=name");
            expect(visit.url).toContain("dir=desc");
        } finally {
            window.history.replaceState({}, "", original);
        }
    });
});
;
