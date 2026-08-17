import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import type { HistoryDay } from "./HistoryPage.vue";
import HistoryPage from "./HistoryPage.vue";
import type { HistoryPlay } from "./HistoryRow.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The listening history, at /history — a day per accordion section, its listens inside.
 *
 * WHAT IS TESTED HERE IS WHAT PHP CANNOT SEE, per the repo's layer rule. The grouping, the
 * ordering, the scoping to one reader and the twenty-five-day page are all the server's, and
 * are pinned by `assertInertia` in tests/Feature/History/HistoryPageTest.php. What belongs to
 * this page:
 *
 *   - THE DAY IN THE READER'S LOCALE, from a `YYYY-MM-DD` that is a DAY rather than an
 *     instant. Formatted the obvious way — `new Date("2026-08-16")` — it parses as UTC midnight
 *     and names the day before for every reader west of Greenwich, which is a heading
 *     disagreeing with the rows under it.
 *   - THE SECTION ID STAYING RAW while its label is translated: the id names the panel's slot,
 *     so a localised one would change a section's identity with the language switcher.
 *   - THE PAGER BEING CONDITIONAL, and having no page-size control when it is drawn. A reader
 *     with one day of listening should not meet "1–1 / 1" and a Select.
 *   - THE EMPTY STATE, which is reachable by typing the URL — the menu hides the way in.
 */

/** One listen; tests override only what they are about. */
const play = (overrides: Partial<HistoryPlay> = {}): HistoryPlay => ({
    id: "play-1",
    playedAt: "2026-08-16T21:30:00+00:00",
    kind: "music",
    name: "Paranoid Android",
    creator: "Radiohead",
    container: "OK Computer",
    href: "/music/songs/song-1",
    ...overrides
});

/** One day of listening. */
const day = (overrides: Partial<HistoryDay> = {}): HistoryDay => ({
    date: "2026-08-16",
    count: 1,
    plays: [play()],
    ...overrides
});

/** Mount the page with sensible defaults, overriding only what a test is about. */
const page = (props: Partial<InstanceType<typeof HistoryPage>["$props"]> = {}) =>
    mountApp(HistoryPage, {
        props: { days: [day()], page: 1, perPage: 25, totalDays: 1, ...props }
    });

describe("HistoryPage", () => {
    beforeEach(() => {
        resetInertia();
        setPage({ props: { auth: { user: { id: "user-1", name: "Ash", email: "a@b.c" } } } });
    });

    it("names each day in the reader's own locale rather than as a raw date", () => {
        // The catalogue is German in tests, so this is the German full date — weekday included,
        // because that is the half a reader recognises an evening by.
        const wrapper = page();

        expect(wrapper.find(".accordion__label").text()).toBe("Sonntag, 16. August 2026");
    });

    it("reads a day as a DAY, not as an instant", () => {
        /*
         * THE TRAP THIS PAGE WOULD OTHERWISE HAVE. `new Date("2026-08-16")` is UTC midnight, so
         * anywhere west of Greenwich the heading would say the 15th while every row under it
         * was played on the 16th. The date is split and handed to the local-time constructor
         * instead, which is what this asserts: the day survives whatever zone the reader is in.
         */
        const wrapper = page({ days: [day({ date: "2026-01-01" })] });

        expect(wrapper.find(".accordion__label").text()).toContain("1. Januar 2026");
    });

    it("keeps the section id raw, so the language switcher cannot change what a section is", () => {
        const wrapper = page();

        expect(wrapper.find(".accordion__trigger").attributes("aria-controls")).toBe(
            "history-accordion-panel-2026-08-16"
        );
    });

    it("counts the day's listens in its header, pluralised", () => {
        const wrapper = page({ days: [day({ count: 12, plays: [play(), play({ id: "play-2" })] })] });

        expect(wrapper.find(".accordion__fact").text()).toContain("12");
        expect(wrapper.find(".accordion__fact").attributes("title")).toBe(
            translate("history.playsTitle").split(" | ")[1].replace("{n}", "12")
        );
    });

    it("opens closed, and shows a day's rows once it is opened", async () => {
        // Twenty-five days opened at once is a page nobody can find their way back up, and the
        // first thing worth seeing here is the run of days itself.
        const wrapper = page();

        expect(wrapper.findAll(".history-row")).toHaveLength(0);

        await wrapper.find(".accordion__trigger").trigger("click");

        expect(wrapper.findAll(".history-row")).toHaveLength(1);
    });

    it("labels each day's list with the day it belongs to", async () => {
        // Every panel on the page is otherwise "list of plays", which tells a screen-reader user
        // nothing about which one they have opened.
        const wrapper = page();
        await wrapper.find(".accordion__trigger").trigger("click");

        expect(wrapper.find(".history__day").attributes("aria-label")).toBe("Sonntag, 16. August 2026");
    });

    it("draws no pager for a reader whose listening fits on one page", () => {
        const wrapper = page({ totalDays: 25 });

        expect(wrapper.find(".dt-pagination").exists()).toBe(false);
    });

    it("draws a pager once there is a second page, with no page-size control on it", () => {
        // The number of days is the server's, not the reader's: a size Select on a list with one
        // column is a setting nobody came here to make.
        const wrapper = page({ totalDays: 63 });

        expect(wrapper.find(".dt-pagination").exists()).toBe(true);
        expect(wrapper.find(".dt-pagination__info").text()).toBe("1–25 / 63");
        expect(wrapper.find(".select").exists()).toBe(false);
    });

    it("says so when nothing has been listened to, rather than showing an empty stack", () => {
        const wrapper = page({ days: [], totalDays: 0 });

        expect(wrapper.text()).toContain(translate("history.empty"));
        expect(wrapper.find(".accordion").exists()).toBe(false);
    });
});
