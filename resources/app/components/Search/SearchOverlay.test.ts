import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetSearchOverlayForTests, useSearchOverlay } from "Composables/useSearchOverlay";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import SearchOverlay from "./SearchOverlay.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The header's overlay, and the one thing about it a fake DOM can answer.
 *
 * happy-dom has no top layer, so `showPopover` is a no-op stub here (see Testing/setup): whether the
 * panel is on screen, whether Escape and an outside click dismiss it, and whether the field takes
 * focus are Playwright's business and are covered in tests/e2e/app/search.spec.ts.
 *
 * WHAT IS LEFT IS WHO GETS ONE AT ALL, and it is worth its own file because the failure is a
 * security-shaped one rather than a cosmetic one: this component is mounted by the app-wide layout,
 * which renders the LOGIN page too — so an overlay that did not check for a reader would offer a
 * guest a search behind `auth`, and a magnifying glass that can only redirect to the form they are
 * already looking at. The check is also WATCHED rather than made once, because signing in is an
 * Inertia visit that leaves the layout mounted.
 */

/** Mount the overlay with or without a signed-in reader. */
const mountOverlay = (user: { id: string; name: string; email: string } | null) => {
    setPage({ props: { auth: { user } } });

    return mountApp(SearchOverlay);
};

const reader = { id: "u-1", name: "Ashaltiriak", email: "a@mixtape.test" };

describe("SearchOverlay", () => {
    beforeEach(() => {
        resetInertia();
        resetSearchOverlayForTests();
    });

    it("renders nothing and registers nothing for a guest", () => {
        const wrapper = mountOverlay(null);

        expect(wrapper.find(".search-layer").exists()).toBe(false);
        // Which is what keeps the header's trigger away too — it reads this registration.
        expect(useSearchOverlay().exists.value).toBe(false);
    });

    it("registers itself for a signed-in reader, so the header can offer the trigger", () => {
        const wrapper = mountOverlay(reader);

        expect(wrapper.find(".search-layer").exists()).toBe(true);
        expect(useSearchOverlay().exists.value).toBe(true);
    });

    /**
     * Signing in is an Inertia visit and the layout survives it, so a check made only on mount would
     * leave a reader without the feature until their next full page load.
     */
    it("appears when a guest signs in under a layout that stays mounted", async () => {
        const wrapper = mountOverlay(null);
        expect(useSearchOverlay().exists.value).toBe(false);

        setPage({ props: { auth: { user: reader } } });
        await nextTick();

        expect(wrapper.find(".search-layer").exists()).toBe(true);
        expect(useSearchOverlay().exists.value).toBe(true);
    });

    /** …and goes away again on the way out, taking any open panel with it. */
    it("withdraws on sign-out", async () => {
        const wrapper = mountOverlay(reader);
        useSearchOverlay().open();

        setPage({ props: { auth: { user: null } } });
        await nextTick();

        expect(wrapper.find(".search-layer").exists()).toBe(false);
        expect(useSearchOverlay().exists.value).toBe(false);
        expect(useSearchOverlay().isOpen.value).toBe(false);
    });
});
