import { beforeEach, describe, expect, it, vi } from "vitest";
import { noteSearchOverlay, resetSearchOverlayForTests, useSearchOverlay } from "Composables/useSearchOverlay";
import { resetInertia } from "Testing/inertia";
import { iconNames, mountApp, translate } from "Testing/mount";
import SearchToggle from "./SearchToggle.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The header's search trigger. What it LOOKS like is CSS and belongs to Playwright; what is left
 * here is the part CSS cannot express, and the first case is the one that matters:
 *
 * THE BUTTON MUST BE ABSENT WHERE NO OVERLAY IS MOUNTED. That is the guest share space, where a
 * library search would be an invitation to a login form — and it is also every page a guest sees,
 * since the overlay only mounts in the signed-in layout. The condition is deliberately NOT "am I
 * on a share page" or "is anybody signed in": it is the overlay saying it exists
 * (`noteSearchOverlay`), because a layout's decision restated in the header is a copy that
 * eventually disagrees. A regression here is a round button that opens nothing at all.
 *
 * The rest is the glyph and the announced state staying in step with the panel, which is the same
 * pairing PlayQueueToggle keeps.
 */

describe("SearchToggle", () => {
    beforeEach(() => {
        resetInertia();
        resetSearchOverlayForTests();
    });

    it("renders nothing where no overlay is mounted", () => {
        const wrapper = mountApp(SearchToggle);

        expect(wrapper.find("button").exists()).toBe(false);
    });

    it("appears once an overlay has registered itself", () => {
        noteSearchOverlay(true);

        expect(mountApp(SearchToggle).find("button").exists()).toBe(true);
    });

    it("opens the overlay when pressed", async () => {
        noteSearchOverlay(true);
        const wrapper = mountApp(SearchToggle);

        await wrapper.find("button").trigger("click");

        expect(useSearchOverlay().isOpen.value).toBe(true);
    });

    /** The glyph doubles as the state, so the one button reads for both directions. */
    it("swaps its glyph and says what the next press will do", async () => {
        noteSearchOverlay(true);
        const wrapper = mountApp(SearchToggle);

        expect(iconNames(wrapper)).toEqual(["search"]);
        expect(wrapper.find("button").attributes("aria-label")).toBe(translate("search.open"));
        expect(wrapper.find("button").attributes("aria-expanded")).toBe("false");

        await wrapper.find("button").trigger("click");

        expect(iconNames(wrapper)).toEqual(["close"]);
        expect(wrapper.find("button").attributes("aria-label")).toBe(translate("search.close"));
        expect(wrapper.find("button").attributes("aria-expanded")).toBe("true");
    });
});
