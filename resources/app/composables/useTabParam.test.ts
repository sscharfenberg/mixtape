import { beforeEach, describe, expect, it, vi } from "vitest";
import { effectScope, nextTick } from "vue";
import { useTabParam } from "Composables/useTabParam";
import { emitRouterEvent, resetInertia } from "Testing/inertia";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * useTabParam mirrors a tab selection into the query string with history.replaceState.
 * The docblock on the composable is emphatic about WHY it is replaceState and not an
 * Inertia visit (a visit would flash DataTable's loading overlay over content that is
 * already on screen) and why it replaces rather than pushes (five tab flips must not put
 * five entries between the reader and where they came from). Those are the properties
 * under test — not "does the ref hold a string".
 */

/** Put the fake browser at a URL, since happy-dom starts every file at about:blank. */
const at = (url: string): void => {
    history.replaceState(null, "", url);
};

describe("useTabParam", () => {
    beforeEach(() => {
        resetInertia();
        at("/music/artists/radiohead");
        vi.restoreAllMocks();
    });

    it("reads the tab from the URL on load", () => {
        at("/music/artists/radiohead?tab=songs");

        expect(useTabParam().tab.value).toBe("songs");
    });

    it("is undefined when the URL names no tab", () => {
        expect(useTabParam().tab.value).toBeUndefined();
    });

    it("treats an empty param as naming no tab", () => {
        // `?tab=` is not a selection — the strip should show its own default.
        at("/music/artists/radiohead?tab=");

        expect(useTabParam().tab.value).toBeUndefined();
    });

    it("honours a custom param name, for a page with two strips", () => {
        at("/music/genres/rock?discography=albums&tab=songs");

        expect(useTabParam("discography").tab.value).toBe("albums");
    });

    it("writes the selection into the query string", async () => {
        const { tab } = useTabParam();

        tab.value = "albums";
        await nextTick();

        expect(window.location.search).toBe("?tab=albums");
    });

    it("replaces rather than pushes, so Back leaves the page instead of stepping tabs", async () => {
        const replaceSpy = vi.spyOn(history, "replaceState");
        const pushSpy = vi.spyOn(history, "pushState");
        const { tab } = useTabParam();

        tab.value = "albums";
        await nextTick();
        tab.value = "songs";
        await nextTick();

        expect(replaceSpy).toHaveBeenCalledTimes(2);
        expect(pushSpy).not.toHaveBeenCalled();
    });

    it("carries history.state through untouched, so Inertia's back button keeps working", async () => {
        // Inertia parks the current page object on history.state; replacing it with null
        // would break the back button for the whole app, not just this strip.
        const inertiaState = { inertia: { component: "Music/Artists/Artist/ArtistPage" } };
        history.replaceState(inertiaState, "", "/music/artists/radiohead");
        const replaceSpy = vi.spyOn(history, "replaceState");
        const { tab } = useTabParam();

        tab.value = "albums";
        await nextTick();

        expect(replaceSpy).toHaveBeenCalledExactlyOnceWith(inertiaState, "", "/music/artists/radiohead?tab=albums");
    });

    it("never routes a tab change through Inertia", async () => {
        // The load-bearing one: a visit would raise DataTable's overlay over a table
        // nobody asked to reload. Assert nothing at all reached the router.
        const { routerCalls } = await import("Testing/inertia");
        const { tab } = useTabParam();

        tab.value = "albums";
        await nextTick();

        expect(routerCalls).toHaveLength(0);
    });

    it("preserves other query params and the hash", async () => {
        at("/music/artists/radiohead?sort=name&page=2#discography");
        const { tab } = useTabParam();

        tab.value = "albums";
        await nextTick();

        expect(window.location.search).toContain("sort=name");
        expect(window.location.search).toContain("page=2");
        expect(window.location.hash).toBe("#discography");
    });

    it("drops the param when the selection is cleared", async () => {
        at("/music/artists/radiohead?tab=albums");
        const { tab } = useTabParam();

        tab.value = undefined;
        await nextTick();

        expect(window.location.search).toBe("");
    });

    it("writes nothing on load, so an untouched page keeps a clean URL", async () => {
        const replaceSpy = vi.spyOn(history, "replaceState");

        useTabParam();
        await nextTick();

        expect(replaceSpy).not.toHaveBeenCalled();
    });

    it("skips the rewrite when the URL already says what the tab says", async () => {
        at("/music/artists/radiohead?tab=albums");
        const { tab } = useTabParam();
        const replaceSpy = vi.spyOn(history, "replaceState");

        // Re-selecting the tab the URL already names must not churn history.
        tab.value = "songs";
        await nextTick();
        tab.value = "albums";
        await nextTick();
        replaceSpy.mockClear();
        tab.value = "albums";
        await nextTick();

        expect(replaceSpy).not.toHaveBeenCalled();
    });

    it("re-reads the URL after an Inertia navigation", async () => {
        // What makes Back work across a table that sorts inside a tab: DataTable carries
        // the query string over, so stepping back has to put the strip where the URL says.
        const { tab } = useTabParam();
        expect(tab.value).toBeUndefined();

        at("/music/artists/radiohead?tab=songs");
        emitRouterEvent("navigate");

        expect(tab.value).toBe("songs");
    });

    it("unsubscribes from router events when its scope is disposed", () => {
        const scope = effectScope();
        const tab = scope.run(() => useTabParam().tab)!;

        scope.stop();
        at("/music/artists/radiohead?tab=songs");
        emitRouterEvent("navigate");

        // A disposed component must not keep reacting — otherwise every visited tabbed
        // page leaves a listener behind for the rest of the session.
        expect(tab.value).toBeUndefined();
    });
});
