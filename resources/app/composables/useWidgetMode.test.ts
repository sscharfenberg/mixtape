import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { useWidgetMode } from "Composables/useWidgetMode";
import type { WidgetMode } from "Types/music";

/*
 * useWidgetMode persists a widget's mode to localStorage. Two behaviours matter beyond
 * the obvious round-trip, and both are defensive:
 *
 *  - a STALE stored value (a mode the widget no longer supports, e.g. after "popular"
 *    was removed from a widget's list) must be rejected, not rendered — otherwise a
 *    widget comes back in a mode it cannot handle
 *  - localStorage can THROW outright (Safari private mode, storage disabled), and a
 *    toggle that explodes because persistence failed is worse than one that forgets
 */

const ALLOWED: WidgetMode[] = ["latest", "popular", "random"];

describe("useWidgetMode", () => {
    beforeEach(() => {
        localStorage.clear();
        vi.restoreAllMocks();
    });

    it("falls back when nothing is stored", () => {
        expect(useWidgetMode("artists", "latest", ALLOWED).value).toBe("latest");
    });

    it("restores a previously stored mode synchronously, with no default-then-swap flash", () => {
        localStorage.setItem("mixtape:widget-mode:artists", "popular");

        // Synchronous on the very first read — the app mounts client-side, so the stored
        // mode has to be there for the first paint.
        expect(useWidgetMode("artists", "latest", ALLOWED).value).toBe("popular");
    });

    it("namespaces the key per widget", () => {
        localStorage.setItem("mixtape:widget-mode:artists", "popular");

        expect(useWidgetMode("albums", "latest", ALLOWED).value).toBe("latest");
    });

    it("persists a change", async () => {
        const mode = useWidgetMode("albums", "latest", ALLOWED);

        mode.value = "random";
        await nextTick();

        expect(localStorage.getItem("mixtape:widget-mode:albums")).toBe("random");
    });

    it("rejects a stored mode the widget no longer supports", () => {
        localStorage.setItem("mixtape:widget-mode:genres", "popular");

        // This widget only offers latest/random — the stale "popular" must not win.
        expect(useWidgetMode("genres", "latest", ["latest", "random"]).value).toBe("latest");
    });

    it("rejects a stored value that was never a mode at all", () => {
        localStorage.setItem("mixtape:widget-mode:songs", "garbage");

        expect(useWidgetMode("songs", "latest", ALLOWED).value).toBe("latest");
    });

    it("falls back silently when reading storage throws", () => {
        // Safari private mode / storage disabled. Spied on the instance rather than
        // Storage.prototype: localStorage here is the setup file's polyfill, which is
        // not built on the global Storage.prototype.
        vi.spyOn(localStorage, "getItem").mockImplementation(() => {
            throw new Error("storage disabled");
        });

        expect(() => useWidgetMode("artists", "random", ALLOWED)).not.toThrow();
        expect(useWidgetMode("artists", "random", ALLOWED).value).toBe("random");
    });

    it("keeps working when writing to storage throws", async () => {
        vi.spyOn(localStorage, "setItem").mockImplementation(() => {
            throw new Error("quota exceeded");
        });
        const mode = useWidgetMode("artists", "latest", ALLOWED);

        mode.value = "popular";
        await nextTick();

        // Persistence is best-effort; the in-memory toggle must still have happened.
        expect(mode.value).toBe("popular");
    });
});
