import { mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h, nextTick, ref } from "vue";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetInertia } from "Testing/inertia";
import { useQueueReorder } from "./useQueueReorder";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * SortableJS is MOCKED here, and the mock is the point of this file.
 *
 * A drag is a stream of pointer events over elements with real geometry, and happy-dom
 * has no layout — so a "drag" driven here would be asserting the mock's own arithmetic.
 * The real gesture is a Playwright spec (tests/e2e/app/queue.spec.ts). What this layer
 * CAN prove is everything around the library, which is where the bugs actually live:
 * the options it is handed (drag by the grip only, no animation under reduced motion),
 * that a finished drop is undone in the DOM before it is applied to the queue, that it
 * goes through `reorder()` rather than mutating tracks, and that the instance is torn
 * down with the list. Those are contracts, not geometry.
 */
const mocks = vi.hoisted(() => ({
    /** Every `new Sortable(…)` this file caused, in order. */
    constructed: [] as { element: HTMLElement; options: Record<string, unknown> }[],
    destroy: vi.fn()
}));

vi.mock("sortablejs", () => ({
    default: class {
        destroy = mocks.destroy;

        constructor(element: HTMLElement, options: Record<string, unknown>) {
            mocks.constructed.push({ element, options });
        }
    }
}));

/** The options the one live instance was built with. */
const options = () => mocks.constructed[0].options;

/** Sortable's `onEnd`, as the library would call it after moving a row. */
const onEnd = (event: Record<string, unknown>): void =>
    (options().onEnd as (event: Record<string, unknown>) => void)(event);

/** A queue track with just enough shape to be identifiable. */
const track = (id: string, name: string): QueueTrack => ({
    id,
    name,
    artist: "Radiohead",
    album: "The Bends",
    coverUrl: null,
    duration: 120,
    href: `/music/songs/${id}`,
    streamUrl: `/music/songs/${id}/stream`
});

/**
 * A stand-in for PlayQueue: the same list element and row markup the real panel
 * renders, and nothing else.
 *
 * Deliberately NOT the panel itself — the point is to exercise the module against a
 * list whose rows do NOT re-render from the queue, so what `applyDrop` does to the
 * DOM is visible instead of being immediately overwritten by Vue.
 */
const host = defineComponent({
    setup() {
        const list = ref<HTMLOListElement | null>(null);
        const shown = ref(true);
        const { onRowKeydown, onGripPointerdown, shortcutLabel } = useQueueReorder(list);

        return { list, shown, onRowKeydown, onGripPointerdown, shortcutLabel };
    },
    render() {
        if (!this.shown) return h("div");

        return h(
            "ol",
            { ref: "list" },
            usePlayerQueue().tracks.value.map((entry, index) =>
                h("li", { key: entry.id, class: "play-queue__row", onKeydown: (event: KeyboardEvent) => this.onRowKeydown(event, index) }, [
                    h("button", { class: "play-queue__load" }),
                    h("button", { class: "play-queue__grip", onPointerdown: this.onGripPointerdown }),
                    h("button", { class: "play-queue__remove" })
                ])
            )
        );
    }
});

/**
 * Queue three tracks, then mount the host over them.
 *
 * Attached to the document because one case is about FOCUS, and a detached element
 * cannot hold any — `focus()` on one is a no-op and `activeElement` stays on <body>.
 */
const mountHost = async () => {
    usePlayerQueue().enqueue([track("a", "Airbag"), track("b", "Bones"), track("c", "Creep")]);
    const wrapper = mount(host, { attachTo: document.body });
    await nextTick();

    return wrapper;
};

/** Pretend the reader has (or has not) asked to reduce motion. */
const setMotion = (allowed: boolean): void => {
    vi.spyOn(window, "matchMedia").mockImplementation(
        query => ({ matches: allowed, media: query }) as MediaQueryList
    );
};

/** The queue's track names, in order. */
const names = (): string[] => usePlayerQueue().tracks.value.map(entry => entry.name);

/** The row texts' ids, read off the DOM rather than the queue. */
const domOrder = (wrapper: { element: Element }): string[] =>
    [...wrapper.element.querySelectorAll("li")].map(row => row.getAttribute("data-id") ?? "");

/** Press a key on one of a row's controls, and report whether the handler consumed it. */
const press = (
    wrapper: ReturnType<typeof mount>,
    row: number,
    key: string,
    modifiers: { altKey?: boolean } = { altKey: true },
    control = ".play-queue__load"
): boolean => {
    const target = wrapper.element.querySelectorAll("li")[row].querySelector(control)!;
    const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...modifiers });
    target.dispatchEvent(event);

    return event.defaultPrevented;
};

/** Whatever machine the suite is really running on, so a faked platform cannot leak. */
const realPlatform = navigator.platform;

describe("useQueueReorder", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        window.localStorage.clear();
        mocks.constructed.length = 0;
        mocks.destroy.mockClear();
        setMotion(true);
    });

    afterEach(() => {
        Object.defineProperty(window.navigator, "platform", { value: realPlatform, configurable: true });
    });

    it("mounts Sortable on the list, and only lets the grip start a drag", async () => {
        const wrapper = await mountHost();

        expect(mocks.constructed).toHaveLength(1);
        expect(mocks.constructed[0].element).toBe(wrapper.find("ol").element);
        // Whole-row dragging would fight the play overlay, which covers every pixel of it.
        expect(options().handle).toBe(".play-queue__grip");
        expect(options().draggable).toBe(".play-queue__row");
        // One code path on every device, and the one a browser test can drive.
        expect(options().forceFallback).toBe(true);
        // A long-press on touch only, so a finger dragging the list still scrolls it.
        expect(options().delayOnTouchOnly).toBe(true);
        expect(options().delay).toBeGreaterThan(0);
        // Auto-scroll, or a track can only be moved as far as the visible rows.
        expect(options().scroll).toBe(true);
    });

    it("animates at the row's own speed, and not at all under reduced motion", async () => {
        // 150ms mirrors ti.$c-play-queue ("fast") — the row's hover transition. The pair
        // has to stay in step, and the reduced-motion half is the repo's motion rule
        // applied to an option that cannot live in a media query.
        await mountHost();
        expect(options().animation).toBe(150);

        mocks.constructed.length = 0;
        setMotion(false);
        await mountHost();

        expect(options().animation).toBe(0);
    });

    it("follows the list in and out of the DOM, not just the mount", async () => {
        /*
         * The panel is behind a v-if on `isEmpty`, so the <ol> leaves when the queue is
         * cleared and a NEW one arrives when something is queued again. That round trip is
         * why this is a watcher rather than onMounted: with onMounted, reordering would
         * work exactly once per page load and then silently stop after a clear.
         */
        const wrapper = await mountHost();
        expect(mocks.constructed).toHaveLength(1);

        wrapper.vm.shown = false;
        await nextTick();

        expect(mocks.destroy).toHaveBeenCalledTimes(1);

        wrapper.vm.shown = true;
        await nextTick();

        expect(mocks.constructed).toHaveLength(2);
        expect(mocks.constructed[1].element).toBe(wrapper.find("ol").element);
    });

    it("destroys the instance with the panel", async () => {
        const wrapper = await mountHost();

        wrapper.unmount();

        // Sortable listens on the document too, so a leaked instance keeps a detached
        // list alive and reacting.
        expect(mocks.destroy).toHaveBeenCalledTimes(1);
    });

    describe("a finished drag", () => {
        it("moves the track through the queue, keeping the loaded one loaded", async () => {
            const wrapper = await mountHost();
            usePlayerQueue().jumpTo(2); // "Creep" is playing
            const list = wrapper.find("ol").element;
            const item = list.children[0] as HTMLElement;

            // What Sortable does before onEnd: it moves the node itself.
            list.appendChild(item);
            onEnd({ oldIndex: 0, newIndex: 2, item, from: list });

            expect(names()).toStrictEqual(["Bones", "Creep", "Airbag"]);
            // Straight through reorder(), which is what carries the pointer with the
            // track that was loaded — a hand-rolled splice here would switch songs.
            expect(usePlayerQueue().current.value?.name).toBe("Creep");
            expect(usePlayerQueue().currentIndex.value).toBe(1);
        });

        it("puts the DOM back before applying the move, so Vue is the only writer", async () => {
            /*
             * Sortable has already moved the <li> by the time onEnd runs, which leaves two
             * writers on one list — its move and the re-render reorder() triggers. Undoing
             * it restores the state Vue's virtual DOM still believes in. Skipping this is
             * how a wrapper-less integration ends up with a duplicated or missing row.
             */
            const wrapper = await mountHost();
            const list = wrapper.find("ol").element;
            const rows = [...list.children] as HTMLElement[];
            rows.forEach((row, index) => row.setAttribute("data-id", ["a", "b", "c"][index]));

            // Drag the last row to the top, Sortable-style, then hand it over.
            list.insertBefore(rows[2], rows[0]);
            expect(domOrder(wrapper)).toStrictEqual(["c", "a", "b"]);

            onEnd({ oldIndex: 2, newIndex: 0, item: rows[2], from: list });

            // The rows this host renders do not follow the queue, so what is left in the
            // DOM is purely what applyDrop did: the pre-drag order, untouched.
            expect(domOrder(wrapper)).toStrictEqual(["a", "b", "c"]);
            expect(names()).toStrictEqual(["Creep", "Airbag", "Bones"]);
        });

        it("ignores a drop that ended where it started", async () => {
            const wrapper = await mountHost();
            const list = wrapper.find("ol").element;

            onEnd({ oldIndex: 1, newIndex: 1, item: list.children[1], from: list });

            expect(names()).toStrictEqual(["Airbag", "Bones", "Creep"]);
        });
    });

    describe("the grip as the shortcut's way in", () => {
        it("focuses itself when pressed, since a Mac browser may not", async () => {
            /*
             * The bug this fixes, reported from a Mac: the shortcut moves the FOCUSED row,
             * and on macOS Safari and Firefox leave a clicked <button> unfocused by platform
             * convention — so clicking the grip and pressing the keys did nothing there,
             * while Chrome (which does focus it) worked. The hint tells the reader to click
             * the grip first; this is what makes that true in every browser.
             */
            const wrapper = await mountHost();
            const grip = wrapper.element.querySelector(".play-queue__grip") as HTMLElement;

            grip.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true }));

            expect(document.activeElement).toBe(grip);
        });

        it("names the modifier for the keyboard in front of the reader", async () => {
            // The words only — the handler keeps reading `event.altKey`, which is one bit
            // with two names printed on it. See utils/platform.test.ts for the branch.
            Object.defineProperty(window.navigator, "platform", { value: "MacIntel", configurable: true });
            const mac = await mountHost();
            expect(mac.vm.shortcutLabel).toBe("⌥↑/↓");

            Object.defineProperty(window.navigator, "platform", { value: "Win32", configurable: true });
            const pc = await mountHost();
            expect(pc.vm.shortcutLabel).toBe("Alt+↑/↓");
        });
    });

    describe("Alt+↑/↓", () => {
        it("moves the row down, and reports the key as consumed", async () => {
            const wrapper = await mountHost();

            expect(press(wrapper, 0, "ArrowDown")).toBe(true);

            expect(names()).toStrictEqual(["Bones", "Airbag", "Creep"]);
        });

        it("moves the row up", async () => {
            const wrapper = await mountHost();

            press(wrapper, 2, "ArrowUp");

            expect(names()).toStrictEqual(["Airbag", "Creep", "Bones"]);
        });

        it("works from any control in the row, since keydown bubbles", async () => {
            const wrapper = await mountHost();

            press(wrapper, 0, "ArrowDown", { altKey: true }, ".play-queue__grip");
            press(wrapper, 1, "ArrowUp", { altKey: true }, ".play-queue__remove");

            expect(names()).toStrictEqual(["Airbag", "Bones", "Creep"]);
        });

        it("leaves a bare arrow alone, because that is how the panel scrolls", async () => {
            const wrapper = await mountHost();

            expect(press(wrapper, 0, "ArrowDown", { altKey: false })).toBe(false);

            expect(names()).toStrictEqual(["Airbag", "Bones", "Creep"]);
        });

        it("does not swallow the keystroke at either end of the queue", async () => {
            const wrapper = await mountHost();

            // Not merely "does nothing": the event must stay unconsumed, or the last row
            // becomes a place where Alt+↓ silently eats a keystroke.
            expect(press(wrapper, 0, "ArrowUp")).toBe(false);
            expect(press(wrapper, 2, "ArrowDown")).toBe(false);

            expect(names()).toStrictEqual(["Airbag", "Bones", "Creep"]);
        });

        it("ignores any other key held with Alt", async () => {
            const wrapper = await mountHost();

            expect(press(wrapper, 0, "ArrowLeft")).toBe(false);
            expect(press(wrapper, 0, "Enter")).toBe(false);

            expect(names()).toStrictEqual(["Airbag", "Bones", "Creep"]);
        });
    });
});
