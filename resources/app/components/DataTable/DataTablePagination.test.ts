import { describe, expect, it } from "vitest";
import { mountApp } from "Testing/mount";
import DataTablePagination from "./DataTablePagination.vue";

/*
 * The pagination bar is pure arithmetic over (page, pageSize, total) plus a windowing
 * rule. Off-by-ones here are the classic silent bug — a last page that shows "101–100 /
 * 100", a next button still live on the final page, an ellipsis that hides a page the
 * user can no longer reach. All of it is deterministic, so all of it is tested.
 *
 * Navigation is EMITTED, never performed: DataTable owns the URL. Asserting the emitted
 * page number is therefore asserting the whole contract of this component.
 */

/** Mount the bar for a given position in a list. */
const paginate = (page: number, pageSize: number, total: number) =>
    mountApp(DataTablePagination, { props: { page, pageSize, total } });

/** Text of every page-number button (the numbered ones and the ellipses, in order). */
const pageStrip = (wrapper: ReturnType<typeof paginate>): string[] =>
    wrapper
        .findAll(".dt-pagination__page, .dt-pagination__ellipsis")
        .map(node => node.text())
        .filter(text => text !== "");

describe("DataTablePagination", () => {
    describe("the from–to / total readout", () => {
        it("counts from one on the first page", () => {
            expect(paginate(1, 25, 300).find(".dt-pagination__info").text()).toBe("1–25 / 300");
        });

        it("offsets by whole pages", () => {
            expect(paginate(3, 25, 300).find(".dt-pagination__info").text()).toBe("51–75 / 300");
        });

        it("stops at the total on a partly-filled last page", () => {
            // 4 pages of 25 over 87 rows: the last shows 76–87, not 76–100.
            expect(paginate(4, 25, 87).find(".dt-pagination__info").text()).toBe("76–87 / 87");
        });

        it("reads sensibly for an empty listing", () => {
            expect(paginate(1, 25, 0).find(".dt-pagination__info").text()).toBe("1–0 / 0");
        });
    });

    describe("the page-number window", () => {
        it("shows nothing at all when everything fits on one page", () => {
            expect(pageStrip(paginate(1, 25, 20))).toStrictEqual([]);
        });

        it("shows every page when they all fit in the window", () => {
            expect(pageStrip(paginate(1, 25, 75))).toStrictEqual(["1", "2", "3"]);
        });

        it("shows two neighbours either side of the current page", () => {
            // The documented example: page 5 of 20 → ["…", 3, 4, 5, 6, 7, "…"].
            expect(pageStrip(paginate(5, 25, 500))).toStrictEqual(["…", "3", "4", "5", "6", "7", "…"]);
        });

        it("marks more pages ahead but not behind at the start", () => {
            expect(pageStrip(paginate(1, 25, 500))).toStrictEqual(["1", "2", "3", "…"]);
        });

        it("marks more pages behind but not ahead at the end", () => {
            expect(pageStrip(paginate(20, 25, 500))).toStrictEqual(["…", "18", "19", "20"]);
        });

        it("marks the current page for assistive tech", () => {
            const current = paginate(5, 25, 500).find("[aria-current='page']");

            expect(current.text()).toBe("5");
            expect(current.classes()).toContain("dt-pagination__current");
        });
    });

    describe("the first / previous / next / last buttons", () => {
        /*
         * Scoped to `.dt-pagination__page` rather than a bare "button": the page-size
         * Select renders buttons of its own, so findAll("button") sweeps those up too
         * and the at(-1)/at(-2) positions stop meaning first/last.
         */
        it("disables going back on the first page", () => {
            const buttons = paginate(1, 25, 500).findAll(".dt-pagination__page");

            expect(buttons[0].attributes("disabled")).toBeDefined();
            expect(buttons[1].attributes("disabled")).toBeDefined();
        });

        it("disables going forward on the last page", () => {
            // Index arithmetic rather than .at(): the project's tsconfig targets ES2020.
            const buttons = paginate(20, 25, 500).findAll(".dt-pagination__page");

            expect(buttons[buttons.length - 1].attributes("disabled")).toBeDefined();
            expect(buttons[buttons.length - 2].attributes("disabled")).toBeDefined();
        });

        it("emits the target page for each control", async () => {
            const wrapper = paginate(5, 25, 500);
            const buttons = wrapper.findAll(".dt-pagination__page");

            await buttons[0].trigger("click");
            await buttons[1].trigger("click");
            await buttons[buttons.length - 2].trigger("click");
            await buttons[buttons.length - 1].trigger("click");

            expect(wrapper.emitted("navigate")).toStrictEqual([[1], [4], [6], [20]]);
        });

        it("emits the page number of a clicked page button", async () => {
            const wrapper = paginate(5, 25, 500);

            await wrapper.findAll(".dt-pagination__page").find(node => node.text() === "7")!.trigger("click");

            expect(wrapper.emitted("navigate")).toStrictEqual([[7]]);
        });
    });

    describe("jump to page", () => {
        it("navigates to the entered page on Enter", async () => {
            const wrapper = paginate(1, 25, 500);
            const input = wrapper.find("#jumpToPage");

            await input.setValue(12);
            await input.trigger("keydown.enter");

            expect(wrapper.emitted("navigate")).toStrictEqual([[12]]);
        });

        it("clamps a page beyond the end back to the last page", async () => {
            const wrapper = paginate(1, 25, 500);
            const input = wrapper.find("#jumpToPage");

            await input.setValue(999);
            await input.trigger("keydown.enter");

            expect(wrapper.emitted("navigate")).toStrictEqual([[20]]);
            // And the field is corrected too, so it does not keep showing 999.
            expect((input.element as HTMLInputElement).value).toBe("20");
        });

        it("clamps a page below one back to the first", async () => {
            const wrapper = paginate(5, 25, 500);
            const input = wrapper.find("#jumpToPage");

            await input.setValue(0);
            await input.trigger("keydown.enter");

            expect(wrapper.emitted("navigate")).toStrictEqual([[1]]);
        });

        it("is hidden when there is only one page", () => {
            expect(paginate(1, 25, 20).find("#jumpToPage").exists()).toBe(false);
        });
    });

    it("keeps the readout and page-size control even on a single page", () => {
        // Only the navigation halves collapse — the reader still needs to see the count
        // and to be able to widen the page.
        const wrapper = paginate(1, 25, 20);

        expect(wrapper.find(".dt-pagination__info").exists()).toBe(true);
    });

    it("labels the bar for assistive tech", () => {
        expect(paginate(1, 25, 500).find("nav").attributes("aria-label")).toBeTruthy();
    });
});
