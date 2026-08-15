import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { resetToastsForTests, useToast } from "Composables/useToast";

/*
 * useToast is a MODULE singleton — the no-Pinia shared store. That is the thing worth
 * testing and also the thing that makes it awkward: state survives between tests in the
 * same file, so every test drains the list first (see beforeEach). A leak here would
 * show up as a phantom extra toast in an unrelated assertion.
 *
 * The queue is the real logic: at most MAX_VISIBLE (5) on screen, the rest waiting, and
 * — the subtle part — a queued toast's auto-dismiss clock must not start until it is
 * actually visible, or a burst of 10 would silently expire half of them unseen.
 */


describe("useToast", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        resetToastsForTests();
    });

    afterEach(() => {
        resetToastsForTests();
        vi.useRealTimers();
    });

    it("shows a toast with the given message and type", () => {
        const { activeToasts, addToast } = useToast();

        addToast("Gespeichert!", "success");

        expect(activeToasts.value).toHaveLength(1);
        expect(activeToasts.value[0]).toMatchObject({ message: "Gespeichert!", type: "success" });
    });

    it("defaults to an info toast", () => {
        const { activeToasts, addToast } = useToast();

        addToast("Hinweis");

        expect(activeToasts.value[0].type).toBe("info");
    });

    it("gives every toast its own id", () => {
        const { activeToasts, addToast } = useToast();

        addToast("eins");
        addToast("zwei");

        expect(activeToasts.value[0].id).not.toBe(activeToasts.value[1].id);
    });

    it("auto-dismisses after the default 5000ms", () => {
        const { activeToasts, addToast } = useToast();

        addToast("verschwindet");
        vi.advanceTimersByTime(4999);
        expect(activeToasts.value).toHaveLength(1);

        vi.advanceTimersByTime(1);
        expect(activeToasts.value).toHaveLength(0);
    });

    it("honours a custom duration", () => {
        const { activeToasts, addToast } = useToast();

        addToast("acht Sekunden", "error", 8000);
        vi.advanceTimersByTime(5000);
        expect(activeToasts.value).toHaveLength(1);

        vi.advanceTimersByTime(3000);
        expect(activeToasts.value).toHaveLength(0);
    });

    it("keeps a zero-duration toast until it is dismissed by hand", () => {
        const { activeToasts, addToast, removeToast } = useToast();

        addToast("bleibt", "warning", 0);
        vi.advanceTimersByTime(60_000);
        expect(activeToasts.value).toHaveLength(1);

        removeToast(activeToasts.value[0].id);
        expect(activeToasts.value).toHaveLength(0);
    });

    it("shows at most five at once and queues the rest", () => {
        const { activeToasts, addToast } = useToast();

        for (let index = 1; index <= 8; index += 1) addToast(`toast ${index}`);

        expect(activeToasts.value).toHaveLength(5);
        expect(activeToasts.value.map(toast => toast.message)).toStrictEqual([
            "toast 1",
            "toast 2",
            "toast 3",
            "toast 4",
            "toast 5"
        ]);
    });

    it("promotes the next queued toast when a slot frees up", () => {
        const { activeToasts, addToast, removeToast } = useToast();

        for (let index = 1; index <= 7; index += 1) addToast(`toast ${index}`);
        removeToast(activeToasts.value[0].id);

        expect(activeToasts.value).toHaveLength(5);
        expect(activeToasts.value.map(toast => toast.message)).toContain("toast 6");
        expect(activeToasts.value.map(toast => toast.message)).not.toContain("toast 1");
    });

    it("only starts a queued toast's clock once it becomes visible", () => {
        const { activeToasts, addToast } = useToast();

        for (let index = 1; index <= 6; index += 1) addToast(`toast ${index}`, "info", 1000);

        // The first five expire together, which promotes the sixth — it must then get
        // its OWN full 1000ms rather than having quietly burned it while queued.
        vi.advanceTimersByTime(1000);
        expect(activeToasts.value.map(toast => toast.message)).toStrictEqual(["toast 6"]);

        vi.advanceTimersByTime(999);
        expect(activeToasts.value).toHaveLength(1);

        vi.advanceTimersByTime(1);
        expect(activeToasts.value).toHaveLength(0);
    });

    it("ignores a removal for an id that is not showing", () => {
        const { activeToasts, addToast, removeToast } = useToast();

        addToast("bleibt");
        removeToast("does-not-exist");

        expect(activeToasts.value).toHaveLength(1);
    });

    it("shares one list across separate useToast() calls", () => {
        // The whole point of the module singleton: a plain .ts file and a component
        // must not end up with two different toast lists.
        const first = useToast();
        const second = useToast();

        first.addToast("von woanders");

        expect(second.activeToasts.value).toHaveLength(1);
        expect(second.activeToasts).toBe(first.activeToasts);
    });
});
