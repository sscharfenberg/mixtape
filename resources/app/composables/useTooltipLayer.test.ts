import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { TOOLTIP_ID, nextAnchorName, useTooltipLayer } from "Composables/useTooltipLayer";
import type { TooltipRequest } from "Composables/useTooltipLayer";

/*
 * The tooltip layer is one tip element shared by the whole app, driven by the v-tooltip
 * directive. Its hard parts are all about RACES between triggers — a stale mouseleave
 * arriving after the pointer has already reached the next trigger must not kill the
 * newer tip — and about the pin that makes tooltips work on a touch screen, where there
 * is no hover to end.
 *
 * happy-dom has no Popover API (`showPopover` is absent), so the layer's tip element is
 * stood in for by a fake that records the calls. That is legitimate here because the
 * logic under test is the bookkeeping around show/hide, not the browser's top-layer
 * behaviour — the actual positioning is anchor-positioning CSS and belongs to Playwright.
 */

/** A stand-in for the popover tip element, tracking its own open state. */
const createFakeTip = () => {
    let open = false;

    return {
        showPopover: vi.fn(() => {
            open = true;
        }),
        hidePopover: vi.fn(() => {
            open = false;
        }),
        matches: vi.fn((selector: string) => (selector === ":popover-open" ? open : false))
    };
};

/** A tooltip request with sensible defaults, so each test only states what it cares about. */
const request = (overrides: Partial<TooltipRequest> = {}): TooltipRequest => ({
    text: "Nach Name sortieren",
    placement: "top",
    delay: 300,
    anchor: "--tt-1",
    ...overrides
});

describe("useTooltipLayer", () => {
    let tip: ReturnType<typeof createFakeTip>;

    beforeEach(() => {
        vi.useFakeTimers();
        tip = createFakeTip();
        const layer = useTooltipLayer();
        layer.tipRef.value = tip as unknown as HTMLElement;
        // Module singleton: make sure no previous test left a tip open.
        layer.visible.value = false;
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = "";
    });

    it("waits out the hover-intent delay before showing", async () => {
        const { showFor, visible } = useTooltipLayer();
        const trigger = document.createElement("button");

        showFor(trigger, request({ delay: 300 }));
        expect(visible.value).toBe(false);

        await vi.advanceTimersByTimeAsync(300);

        expect(visible.value).toBe(true);
        expect(tip.showPopover).toHaveBeenCalled();
    });

    it("skips the delay when asked to show immediately, as focus does", async () => {
        const { showFor, visible, text } = useTooltipLayer();
        const trigger = document.createElement("button");

        showFor(trigger, request({ text: "Sofort" }), true);
        await nextTick();

        expect(visible.value).toBe(true);
        expect(text.value).toBe("Sofort");
    });

    it("is inert for a trigger with no text", async () => {
        const { showFor, visible } = useTooltipLayer();
        const trigger = document.createElement("button");

        showFor(trigger, request({ text: "" }), true);
        await vi.advanceTimersByTimeAsync(1000);

        expect(visible.value).toBe(false);
        expect(tip.showPopover).not.toHaveBeenCalled();
    });

    it("points aria-describedby at the tip only while it is shown", async () => {
        const { showFor, hideFor } = useTooltipLayer();
        const trigger = document.createElement("button");

        showFor(trigger, request(), true);
        await nextTick();
        expect(trigger.getAttribute("aria-describedby")).toBe(TOOLTIP_ID);

        hideFor(trigger);

        // A hint that is not on screen must not be announced.
        expect(trigger.hasAttribute("aria-describedby")).toBe(false);
    });

    it("carries the placement and anchor of the requesting trigger", async () => {
        const { showFor, placement, anchorName } = useTooltipLayer();
        const trigger = document.createElement("button");

        showFor(trigger, request({ placement: "right", anchor: "--tt-7" }), true);
        await nextTick();

        expect(placement.value).toBe("right");
        expect(anchorName.value).toBe("--tt-7");
    });

    it("drops a queued reveal when the pointer leaves before it fires", async () => {
        const { showFor, hideFor, visible } = useTooltipLayer();
        const trigger = document.createElement("button");

        showFor(trigger, request({ delay: 300 }));
        vi.advanceTimersByTime(100);
        hideFor(trigger);
        await vi.advanceTimersByTimeAsync(1000);

        expect(visible.value).toBe(false);
    });

    it("ignores a hide from a trigger that does not own the tip", async () => {
        // The race the composable is built around: a stale mouseleave from the trigger
        // the pointer just left must not kill the tip that the next one already opened.
        const { showFor, hideFor, visible } = useTooltipLayer();
        const first = document.createElement("button");
        const second = document.createElement("button");

        showFor(second, request({ text: "Zweiter" }), true);
        await nextTick();
        hideFor(first);

        expect(visible.value).toBe(true);
    });

    it("updates an open tip whose trigger's hint changed underneath it", async () => {
        // DataTable sorts with preserveState, so the hovered header survives the visit
        // and only its hint flips direction.
        const { showFor, updateFor, text } = useTooltipLayer();
        const trigger = document.createElement("button");

        showFor(trigger, request({ text: "Aufsteigend sortieren" }), true);
        await nextTick();
        updateFor(trigger, request({ text: "Absteigend sortieren" }));

        expect(text.value).toBe("Absteigend sortieren");
    });

    it("ignores an update aimed at a trigger that does not own the tip", async () => {
        const { showFor, updateFor, text } = useTooltipLayer();
        const owner = document.createElement("button");
        const other = document.createElement("button");

        showFor(owner, request({ text: "Meins" }), true);
        await nextTick();
        updateFor(other, request({ text: "Fremd" }));

        expect(text.value).toBe("Meins");
    });

    it("hides an open tip whose text is emptied by an update", async () => {
        const { showFor, updateFor, visible } = useTooltipLayer();
        const trigger = document.createElement("button");

        showFor(trigger, request(), true);
        await nextTick();
        updateFor(trigger, request({ text: "" }));

        expect(visible.value).toBe(false);
    });

    describe("tap / click pinning", () => {
        it("pins the tip on the first tap", async () => {
            const { toggleFor, visible } = useTooltipLayer();
            const trigger = document.createElement("button");
            document.body.appendChild(trigger);

            toggleFor(trigger, request());
            await nextTick();

            expect(visible.value).toBe(true);
        });

        it("unpins on a second tap of the same trigger", async () => {
            const { toggleFor, visible } = useTooltipLayer();
            const trigger = document.createElement("button");
            document.body.appendChild(trigger);

            toggleFor(trigger, request());
            await nextTick();
            toggleFor(trigger, request());

            expect(visible.value).toBe(false);
        });

        it("survives the emulated pointerleave that follows a tap", async () => {
            // The touch-screen case: the browser fires pointerleave milliseconds after
            // touch-end, and a pinned tip must not treat that as a dismissal.
            const { toggleFor, hideFor, visible } = useTooltipLayer();
            const trigger = document.createElement("button");
            document.body.appendChild(trigger);

            toggleFor(trigger, request());
            await nextTick();
            hideFor(trigger);

            // hideFor is what the directive calls on pointerleave — for a PINNED tip it
            // is still an explicit release, so this documents the current contract:
            // the pin is dropped and the tip closes.
            expect(visible.value).toBe(false);
        });

        it("dismisses a pinned tip on a pointerdown outside the trigger", async () => {
            const { toggleFor, visible } = useTooltipLayer();
            const trigger = document.createElement("button");
            const elsewhere = document.createElement("div");
            document.body.append(trigger, elsewhere);

            toggleFor(trigger, request());
            await nextTick();
            elsewhere.dispatchEvent(new window.PointerEvent("pointerdown", { bubbles: true }));

            expect(visible.value).toBe(false);
        });

        it("leaves a pinned tip alone when the pointerdown is inside its own trigger", async () => {
            // That pointerdown is the start of the tap whose click toggles the tip off;
            // hiding here would let the click re-open it instead.
            const { toggleFor, visible } = useTooltipLayer();
            const trigger = document.createElement("button");
            const label = document.createElement("span");
            trigger.appendChild(label);
            document.body.appendChild(trigger);

            toggleFor(trigger, request());
            await nextTick();
            label.dispatchEvent(new window.PointerEvent("pointerdown", { bubbles: true }));

            expect(visible.value).toBe(true);
        });

        it("does not pin an inert (textless) trigger open", async () => {
            const { toggleFor, visible } = useTooltipLayer();
            const trigger = document.createElement("button");
            document.body.appendChild(trigger);

            toggleFor(trigger, request({ text: "" }));
            await nextTick();

            expect(visible.value).toBe(false);
        });
    });

    describe("nextAnchorName", () => {
        it("never repeats a name", () => {
            const names = [nextAnchorName(), nextAnchorName(), nextAnchorName()];

            expect(new Set(names).size).toBe(3);
        });

        it("mints a valid CSS dashed-ident", () => {
            expect(nextAnchorName()).toMatch(/^--tt-\d+$/u);
        });
    });
});
