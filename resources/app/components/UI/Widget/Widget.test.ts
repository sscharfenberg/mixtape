import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetInertia } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import Widget from "./Widget.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The browse pages' card, and the only thing on it with any state: `refreshing`, raised by
 * the footer's partial reload and spent swapping the body for a skeleton.
 *
 * That swap exists for one reason, and it is a layout reason rather than a cosmetic one.
 * The cards sit in a subgrid whose title / body / footer bands share a height across a row,
 * so a body that empties mid-refresh drags every card on the line up with it and the whole
 * page jumps. The skeleton holds the height. `widgets.spec.ts` proves it in pixels; what is
 * left for this layer is that the swap happens at all, and that it goes BOTH ways — a
 * skeleton that never leaves is the same bug with the opposite symptom.
 *
 * The three optional pieces (title strip, footer, loader) are conditionals over slots, and
 * the footer's is the interesting one: it renders for a `refresh` key even with no footer
 * slot, because that key IS the refresh button. Reading it as "only when there is footer
 * content" is the plausible simplification that removes every refresh button in the app.
 */

/** Mount a widget with a body and whatever else the case needs. */
const widget = (props: Record<string, unknown> = {}, slots: Record<string, string> = {}) =>
    mountApp(Widget, { props, slots: { default: "<p>Inhalt</p>", ...slots } });

describe("Widget", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("shows a title strip only when it was given a title", () => {
        expect(widget().find(".widget__title").exists()).toBe(false);
        expect(widget({}, { title: "Alben" }).find(".widget__title").text()).toBe("Alben");
    });

    it("shows a footer for footer content OR for a refresh key, since that key is a button", () => {
        expect(widget().find(".widget__footer").exists()).toBe(false);
        expect(widget({}, { footer: "<a href='/music/albums'>Alle</a>" }).find(".widget__footer").exists()).toBe(true);
        // No footer slot at all — the refresh button is the only thing in the strip.
        expect(widget({ refresh: "albums" }).find(".widget__footer").exists()).toBe(true);
    });

    it("covers the whole card with the loader overlay while it is loading, and not otherwise", () => {
        expect(widget().find(".widget__loader").exists()).toBe(false);
        expect(widget({ loading: true }).find(".widget__loader").exists()).toBe(true);
    });

    it("swaps the body for a skeleton while a refresh is in flight, and swaps it back", async () => {
        const wrapper = widget({ refresh: "albums" });
        const footer = wrapper.findComponent({ name: "WidgetFooter" });

        expect(wrapper.text()).toContain("Inhalt");
        expect(wrapper.find(".widget-skeleton").exists()).toBe(false);

        footer.vm.$emit("refreshing", true);
        await nextTick();

        expect(wrapper.find(".widget-skeleton").exists()).toBe(true);
        expect(wrapper.text()).not.toContain("Inhalt");

        footer.vm.$emit("refreshing", false);
        await nextTick();

        expect(wrapper.find(".widget-skeleton").exists()).toBe(false);
        expect(wrapper.text()).toContain("Inhalt");
    });

    it("claims a row of its own only when told to, since a card that does stretches nothing", () => {
        // The class is all a unit test can see — the row it claims is `grid-column: 1 / -1`
        // against a real grid, which is Playwright's to measure and happy-dom's to ignore.
        expect(widget().classes()).not.toContain("widget--wide");
        expect(widget({ wide: true }).classes()).toContain("widget--wide");
    });
});
