import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { nextTick } from "vue";
import { TOOLTIP_ID, useTooltipLayer } from "Composables/useTooltipLayer";
import { mountApp } from "Testing/mount";
import TooltipLayer from "./TooltipLayer.vue";

/*
 * The app's one floating tip. It has no logic of its own — it renders four values from the
 * useTooltipLayer singleton — which is exactly why it is worth a test: every one of those
 * four bindings fails SILENTLY.
 *
 *   - `tipRef` is how the composable reaches the popover node, and its two callers are both
 *     guarded (`if (el && !el.matches(":popover-open"))`). Rename the ref, or drop it in a
 *     refactor, and nothing throws anywhere: every tooltip in the app simply stops
 *     appearing, with a green suite and a clean console.
 *   - `--tooltip-anchor` and `--tooltip-area` are the whole positioning mechanism. Drop
 *     either and the tip still renders — in the wrong place, which only a person looking at
 *     the screen would notice.
 *   - the id has to be the one the directive points `aria-describedby` at, or the
 *     description it announces resolves to nothing.
 *
 * The refs are driven directly here rather than through a trigger: what this component owns
 * is state → DOM, and the trigger → state half already has its own tests (Tooltip.test.ts,
 * useTooltipLayer.test.ts). Where the tip actually LANDS is CSS anchor positioning in the
 * top layer, which happy-dom has neither of — that is Playwright's (docs/testing.md).
 */

/** The teleported tip node. */
const tip = (): HTMLElement => document.querySelector<HTMLElement>(`#${TOOLTIP_ID}`)!;

describe("TooltipLayer", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
        const { text, anchorName, placement } = useTooltipLayer();
        text.value = "";
        anchorName.value = "";
        placement.value = "top";
    });

    afterEach(() => {
        document.body.innerHTML = "";
    });

    it("hands the composable the node it needs, which nothing would report as missing", () => {
        mountApp(TooltipLayer);

        expect(useTooltipLayer().tipRef.value).toBe(tip());
    });

    it("teleports to <body>, so it follows every possible trigger in tree order", () => {
        // Anchor positioning only works backwards: a positioned element can anchor to an
        // element that PRECEDES it. Rendered in place, the tip could not anchor to anything
        // after it in the document.
        mountApp(TooltipLayer, { attachTo: document.body });

        expect(tip().parentElement).toBe(document.body);
    });

    it("announces itself as a tooltip in the top layer, under the id the directive points at", () => {
        mountApp(TooltipLayer);

        expect(tip().getAttribute("role")).toBe("tooltip");
        expect(tip().getAttribute("popover")).toBe("manual");
        expect(tip().id).toBe(TOOLTIP_ID);
    });

    it("writes the requested anchor and side onto the element as custom properties", async () => {
        mountApp(TooltipLayer);
        const { text, anchorName, placement } = useTooltipLayer();

        text.value = "Nach Album aufsteigend sortiert";
        anchorName.value = "--mt-tooltip-7";
        placement.value = "bottom";
        await nextTick();

        expect(tip().textContent).toBe("Nach Album aufsteigend sortiert");
        expect(tip().style.getPropertyValue("--tooltip-anchor")).toBe("--mt-tooltip-7");
        expect(tip().style.getPropertyValue("--tooltip-area")).toBe("bottom");
    });

    it("re-points at the next trigger rather than keeping the first one's anchor", async () => {
        // Moving between two adjacent triggers is the case: a stale anchor reads as the tip
        // staying put while its text changes underneath.
        mountApp(TooltipLayer);
        const { text, anchorName } = useTooltipLayer();

        anchorName.value = "--mt-tooltip-1";
        text.value = "Erster";
        await nextTick();

        anchorName.value = "--mt-tooltip-2";
        text.value = "Zweiter";
        await nextTick();

        expect(tip().style.getPropertyValue("--tooltip-anchor")).toBe("--mt-tooltip-2");
        expect(tip().textContent).toBe("Zweiter");
    });
});
