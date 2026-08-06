import { beforeEach, describe, expect, it, vi } from "vitest";
import { ref } from "vue";
import { resetInertia } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import type { ColumnDef, SortEntry } from "Types/dataTable";
import { DATA_TABLE_KEY } from "Types/dataTable";
import DataTableHead from "./DataTableHead.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * Sorting is what a reader does to a table most, and this header is where every part of it is
 * decided except the navigation. Four things here are subtle enough to be worth pinning, and all
 * four are invisible in a screenshot:
 *
 *   - `aria-sort`, which is the only way the sort state reaches a screen reader. "none" on a
 *     sortable column is not the same as absent, and absent is right for a column that cannot
 *     sort at all.
 *   - TIEBREAKERS get the same ascending marker as the sorted column, because on screen they are
 *     just as true (an album's tracks really are ordered by disc and then track) — but they are
 *     deliberately kept OUT of the sort state, so a first click on one sorts ascending rather
 *     than jumping to descending. Folding the two together is the mistake the component's own
 *     docblock warns about, and it would only show up as "why did clicking disc sort it
 *     backwards".
 *   - the HINT promises the direction the next click applies, mirroring DataTable's toggle. A
 *     hint that lies is worse than no hint.
 *   - the header checkbox is THREE-state, and "some" has to be distinguishable from "none".
 *
 * Row rendering and the sticky behaviour are not here: the first is DataTableBody's, the second
 * needs an IntersectionObserver and a scroll position, which is Playwright's (`datatable.spec.ts`).
 */

type Row = { id: string; name: string; disc: string; track: string; size: string };

const COLUMNS: ColumnDef<Row>[] = [
    { key: "name", label: "Titel", sortable: true },
    { key: "disc", label: "CD", sortable: true },
    { key: "track", label: "Nr.", sortable: true },
    { key: "size", label: "Größe" } // not sortable
];

/** Mount the header with a sort state, and whatever the server called a tiebreaker. */
const head = (sort: SortEntry[] = [], tiebreakers: string[] = [], overrides: Record<string, unknown> = {}) => {
    const selectedIds = ref<string[]>([]);
    const togglePageSelection = vi.fn();

    const wrapper = mountApp(DataTableHead, {
        props: {
            // Cast because the component is generic over the ROW type and that parameter cannot
            // flow through the mount helper, which types props against `{ id: string }`. The
            // columns themselves are exactly what a caller passes.
            columns: COLUMNS as unknown as ColumnDef<{ id: string }>[],
            sort,
            tiebreakers,
            selectable: false,
            hasActions: false,
            rowIds: ["a", "b", "c"],
            stuck: false,
            ...overrides
        },
        global: {
            provide: {
                [DATA_TABLE_KEY as unknown as string]: {
                    selectedIds,
                    toggleSelection: vi.fn(),
                    togglePageSelection
                }
            }
        }
    });

    return { wrapper, selectedIds, togglePageSelection };
};

/** The `aria-sort` of every header cell, in column order. */
const sortStates = (wrapper: ReturnType<typeof head>["wrapper"]) =>
    wrapper.findAll("th").map(cell => cell.attributes("aria-sort"));

describe("DataTableHead", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("reports the sorted column and its direction to assistive tech", () => {
        const ascending = head([{ key: "name", direction: "asc" }]);
        const descending = head([{ key: "name", direction: "desc" }]);

        expect(sortStates(ascending.wrapper)[0]).toBe("ascending");
        expect(sortStates(descending.wrapper)[0]).toBe("descending");
    });

    it("says 'none' on a sortable column and nothing at all on one that cannot sort", () => {
        // The distinction matters: "none" advertises that sorting is available here.
        const { wrapper } = head([{ key: "name", direction: "asc" }]);
        const states = sortStates(wrapper);

        expect(states[1]).toBe("none");
        expect(states[3]).toBeUndefined();
    });

    it("emits the column key when a sortable header is pressed", async () => {
        const { wrapper } = head();

        await wrapper.findAll("th button")[1].trigger("click");

        expect(wrapper.emitted("sort")).toStrictEqual([["disc"]]);
    });

    it("gives a column that cannot sort no button to press", () => {
        // A `<th>` whose whole cell is a button reads as pressable; the unsortable one must not.
        const { wrapper } = head();

        expect(wrapper.findAll("th")[3].find("button").exists()).toBe(false);
    });

    it("marks a tiebreaker like the sorted column, without putting it IN the sort state", async () => {
        // An album's tracks are ordered by disc then track, so both carry the ascending marker.
        const { wrapper } = head([{ key: "disc", direction: "asc" }], ["track"]);

        expect(sortStates(wrapper)[1]).toBe("ascending");
        // …and the tiebreak column still reads as unsorted to assistive tech, which is what keeps
        // its first click ascending rather than descending.
        expect(sortStates(wrapper)[2]).toBe("none");

        await wrapper.findAll("th button")[2].trigger("click");
        expect(wrapper.emitted("sort")).toStrictEqual([["track"]]);
    });

    it("announces a tiebreaker's ordering in words, since its marker is only a glyph", () => {
        /*
         * The visible marker is an arrow, so the sr-only line is the whole of what a screen
         * reader gets: "Nach CD aufsteigend sortiert". Asserted for a TIEBREAKER because that is
         * the case with no `aria-sort` to fall back on — the column deliberately reads as
         * unsorted (see above), so without this sentence its ordering is invisible.
         *
         * The hover HINT that promises the next click's direction is not assertable here: it is
         * a `v-tooltip` directive value, and the directive keeps its text in a module WeakMap
         * rather than in the DOM. That one belongs to Playwright.
         */
        const { wrapper } = head([{ key: "name", direction: "asc" }], ["disc"]);
        const announcements = wrapper.findAll(".sr-only").map(node => node.text());

        expect(announcements).toContain("Nach CD aufsteigend sortiert");
        // And no such line for a column that is neither sorted nor a tiebreaker.
        expect(announcements.join(" ")).not.toContain("Nr.");
    });

    it("keeps the select-all box three-state", async () => {
        /*
         * Read off the CHECKBOX COMPONENT's props rather than a DOM input: the header hands it
         * `model-value` and `indeterminate` separately, and those two props are the state. What
         * the component then renders is its own spec's business.
         */
        const state = async (selected: string[]) => {
            const mounted = head([], [], { selectable: true });
            mounted.selectedIds.value = selected;
            await mounted.wrapper.vm.$nextTick();
            const box = mounted.wrapper.findComponent({ name: "Checkbox" });

            return { checked: box.props("modelValue"), indeterminate: box.props("indeterminate") };
        };

        expect(await state([])).toStrictEqual({ checked: false, indeterminate: false });
        // "Some" must not read as "none" — that is the state a reader acts on when they meant to
        // extend a selection rather than start one.
        expect(await state(["a"])).toStrictEqual({ checked: false, indeterminate: true });
        expect(await state(["a", "b", "c"])).toStrictEqual({ checked: true, indeterminate: false });
    });

    it("hands the whole page's ids to the table when select-all is pressed", async () => {
        const { wrapper, togglePageSelection } = head([], [], { selectable: true });

        await wrapper.findComponent({ name: "Checkbox" }).vm.$emit("update:modelValue", true);

        expect(togglePageSelection).toHaveBeenCalledWith(["a", "b", "c"]);
    });

    it("renders an actions column only when there are actions", () => {
        const without = head();
        const with_ = head([], [], { hasActions: true });

        expect(without.wrapper.findAll("th")).toHaveLength(COLUMNS.length);
        expect(with_.wrapper.findAll("th")).toHaveLength(COLUMNS.length + 1);
    });
});
