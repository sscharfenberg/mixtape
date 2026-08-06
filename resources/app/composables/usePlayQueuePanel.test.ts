import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetPlayQueuePanelForTests, usePlayQueuePanel } from "Composables/usePlayQueuePanel";

/*
 * A three-line composable, and worth a spec for exactly two reasons — both of which are about
 * what it must NOT do.
 *
 * It is a SINGLETON, so the header's toggle and the panel in the layout body have to see one flag
 * between them. Two consumers each getting their own state is the failure this shape exists to
 * prevent, and it looks identical in a screenshot: press the toggle, nothing appears.
 *
 * And `isOpen` is exposed READ-ONLY. A caller that could assign to it would be a second way to
 * change a piece of shared view state, which is how a panel ends up open with the toggle showing
 * shut. The two functions are the whole API on purpose.
 *
 * Not asserted here: that the panel is only consulted below `landscape`. That is a media query in
 * PlayQueue's styles, so it belongs to Playwright — `queue.spec.ts` covers both layouts.
 */

describe("usePlayQueuePanel", () => {
    beforeEach(() => {
        resetPlayQueuePanelForTests();
    });

    it("starts shut, so a visit begins with the content unobstructed", () => {
        // Deliberately not persisted: a panel left open last week is not a preference, and on a
        // phone it covers a good part of the screen.
        expect(usePlayQueuePanel().isOpen.value).toBe(false);
    });

    it("flips open and shut again", () => {
        const panel = usePlayQueuePanel();

        panel.toggle();
        expect(panel.isOpen.value).toBe(true);

        panel.toggle();
        expect(panel.isOpen.value).toBe(false);
    });

    it("closes whatever state it was in", () => {
        const panel = usePlayQueuePanel();

        panel.close();
        expect(panel.isOpen.value).toBe(false);

        panel.toggle();
        panel.close();
        expect(panel.isOpen.value).toBe(false);
    });

    it("is ONE panel, however many callers ask for it", () => {
        // The header's toggle and the panel in the layout have no path between them; this is the
        // only thing that connects them.
        const header = usePlayQueuePanel();
        const panel = usePlayQueuePanel();

        header.toggle();

        expect(panel.isOpen.value).toBe(true);
    });

    it("cannot be opened by assignment", () => {
        /*
         * `isOpen` is a computed over the private ref, so the two functions are the only way in —
         * a writable flag would be a second source of truth for one panel.
         *
         * Vue does not THROW on a write to a read-only computed; it warns and ignores. So the
         * guarantee to assert is the ignoring, with the warning as corroboration.
         */
        const panel = usePlayQueuePanel();
        const warn = vi.spyOn(console, "warn").mockImplementation(() => {});

        (panel.isOpen as unknown as { value: boolean }).value = true;

        expect(panel.isOpen.value).toBe(false);
        expect(warn).toHaveBeenCalled();
        warn.mockRestore();
    });
});
