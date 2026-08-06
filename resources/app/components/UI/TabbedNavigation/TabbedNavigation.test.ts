import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetInertia } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import TabbedNavigation from "./TabbedNavigation.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * Every detail page in the app switches its content with this strip, and three of its decisions
 * are worth holding still:
 *
 *   - it is SELF-HEALING. The open tab is a computed over the bound value, so an unset id, a typo,
 *     or a tab that vanished when the data changed all fall back to a real tab with no watcher to
 *     keep in step. Copying the id into state instead is the version that strands a page on a
 *     panel that no longer exists — and the genre page really does gain and lose tabs.
 *   - SELECTION FOLLOWS FOCUS on the arrow keys, which is the APG behaviour for tabs whose panels
 *     are already in the DOM (the server sends them together). Arrowing has to change the panel,
 *     not merely move a focus ring, and it has to WRAP at both ends.
 *   - the ids tying tab↔panel are derived from `name`, so two strips on one page cannot
 *     cross-wire. That is invisible until a second strip appears and clicking one moves the other.
 *
 * `v-model:selected-tab` is exercised through the emitted updates rather than a live parent: what
 * the component owes a caller is the event, and the URL state built on top of it belongs to
 * `useTabParam` (tested) and `tabs.spec.ts` (in a browser, with the address bar).
 */

const TABS = [
    { id: "albums", label: "Alben" },
    { id: "songs", label: "Songs" },
    { id: "artists", label: "Künstler" }
];

/** Mount a strip with three tabs and a panel each. */
const strip = (props: Record<string, unknown> = {}) =>
    mountApp(TabbedNavigation, {
        props: { name: "genre", tabs: TABS, label: "Genre-Inhalte", ...props },
        slots: {
            albums: "<p>albums panel</p>",
            songs: "<p>songs panel</p>",
            artists: "<p>artists panel</p>"
        },
        attachTo: document.body
    });

/** Which tab reports itself as selected. */
const selected = (wrapper: ReturnType<typeof strip>) =>
    wrapper.findAll('[role="tab"]').find(tab => tab.attributes("aria-selected") === "true");

describe("TabbedNavigation", () => {
    beforeEach(() => {
        resetInertia();
        document.body.innerHTML = "";
    });

    it("opens the first tab when nothing was selected", () => {
        expect(selected(strip())?.text()).toContain("Alben");
    });

    it("opens the tab it was given", () => {
        expect(selected(strip({ selectedTab: "songs" }))?.text()).toContain("Songs");
    });

    it("falls back to a real tab when the bound id names none", () => {
        // The self-healing case: a stale URL parameter, or a tab that disappeared with the data.
        expect(selected(strip({ selectedTab: "nonsense" }))?.text()).toContain("Alben");
    });

    it("reports a change, and says nothing when the OPEN tab is pressed again", async () => {
        /*
         * A no-op re-click must not emit: a bound page would otherwise push a history entry — or a
         * partial reload — for a tab the reader is already on.
         *
         * The second press is on the SAME tab as the first, deliberately. `defineModel` keeps a
         * local value in step even with no parent listening, so after the first click the strip
         * really is on "songs" — pressing a third tab would be an ordinary change, not a re-click.
         */
        const wrapper = strip({ selectedTab: "albums" });

        await wrapper.findAll('[role="tab"]')[1].trigger("click");
        expect(wrapper.emitted("update:selectedTab")).toStrictEqual([["songs"]]);

        await wrapper.findAll('[role="tab"]')[1].trigger("click");
        expect(wrapper.emitted("update:selectedTab")).toStrictEqual([["songs"]]);
    });

    it("changes the panel as the arrows move, not just the focus ring", async () => {
        // Selection follows focus. Arrowing that moved focus without switching panels would leave
        // the strip pointing at content the reader cannot see.
        const wrapper = strip({ selectedTab: "albums" });
        const tabs = wrapper.findAll('[role="tab"]');

        await tabs[0].trigger("keydown", { key: "ArrowRight" });

        expect(wrapper.emitted("update:selectedTab")).toStrictEqual([["songs"]]);
        expect(document.activeElement?.textContent).toContain("Songs");
    });

    it("wraps around both ends", async () => {
        const forward = strip({ selectedTab: "artists" });
        await forward.findAll('[role="tab"]')[2].trigger("keydown", { key: "ArrowRight" });
        expect(forward.emitted("update:selectedTab")).toStrictEqual([["albums"]]);

        const backward = strip({ selectedTab: "albums" });
        await backward.findAll('[role="tab"]')[0].trigger("keydown", { key: "ArrowLeft" });
        expect(backward.emitted("update:selectedTab")).toStrictEqual([["artists"]]);
    });

    it("jumps to the ends with Home and End", async () => {
        // And these have to `preventDefault`, or the page scrolls away from the tabs in use.
        const wrapper = strip({ selectedTab: "songs" });
        const tabs = wrapper.findAll('[role="tab"]');

        await tabs[1].trigger("keydown", { key: "End" });
        expect(wrapper.emitted("update:selectedTab")).toStrictEqual([["artists"]]);

        await tabs[2].trigger("keydown", { key: "Home" });
        expect(wrapper.emitted("update:selectedTab")).toStrictEqual([["artists"], ["albums"]]);
    });

    it("leaves keys it does not handle alone", async () => {
        const wrapper = strip({ selectedTab: "albums" });

        await wrapper.findAll('[role="tab"]')[0].trigger("keydown", { key: "a" });

        expect(wrapper.emitted("update:selectedTab")).toBeUndefined();
    });

    it("points every tab at its own panel, namespaced so two strips cannot cross-wire", async () => {
        const wrapper = strip();
        await nextTick();

        for (const tab of wrapper.findAll('[role="tab"]')) {
            const panelId = tab.attributes("aria-controls")!;
            const panel = wrapper.find(`#${panelId}`);

            expect(panelId.startsWith("tabpanel-genre-")).toBe(true);
            expect(panel.exists()).toBe(true);
            // …and back the other way, which is what a screen reader follows to announce the tab
            // a panel belongs to.
            expect(panel.attributes("aria-labelledby")).toBe(tab.attributes("id"));
        }
    });

    it("keeps only the open tab reachable by Tab, the way a tablist should", () => {
        // One stop for the whole strip: arrows move within it, Tab moves past it.
        const wrapper = strip({ selectedTab: "songs" });
        const stops = wrapper.findAll('[role="tab"]').map(tab => tab.attributes("tabindex"));

        expect(stops).toStrictEqual(["-1", "0", "-1"]);
    });

    it("names the tablist, and does not leave it unnamed when the caller forgets", () => {
        expect(strip().find('[role="tablist"]').attributes("aria-label")).toBe("Genre-Inhalte");
        expect(strip({ label: undefined }).find('[role="tablist"]').attributes("aria-label")).toBeTruthy();
    });
});
