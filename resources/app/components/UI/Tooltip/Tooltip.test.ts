import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h, nextTick, ref } from "vue";
import { TOOLTIP_ID, useTooltipLayer } from "Composables/useTooltipLayer";
import { mountApp } from "Testing/mount";
import Tooltip from "./Tooltip.vue";

/*
 * The wrapper form of a tooltip, for a trigger that is a GROUP of markup rather than one
 * element you could hang `v-tooltip` on — a stat tile's value + label, a radio + its label.
 *
 * What it owes a caller is small but easy to get wrong in a way nothing visible catches:
 *
 *   - ONE wrapper node, and that node is the anchor. Rendering per child, or forwarding to
 *     the slot's root, would give a two-element trigger two anchors and hand the tip to
 *     whichever half the pointer happened to enter.
 *   - the props reach the SHARED layer, not a private popover. That is the whole reason
 *     this component exists rather than a per-instance tip, and the assertion is that the
 *     singleton's state changes.
 *   - `options` is a COMPUTED, so a changed prop reaches a tip that is ALREADY OPEN through
 *     the directive's `updated` hook. Pass a plain object instead and it still works
 *     everywhere except the one case it was written for: a live-updating hint (the volume
 *     readout, a mode toggle relabelled on a locale switch) freezes at its first value.
 *
 * No TooltipLayer is mounted here on purpose. Positioning is CSS anchor positioning and the
 * tip is a native popover, neither of which happy-dom has (docs/testing.md → traps) — that
 * half belongs to Playwright. What this layer can prove is that the right request reaches
 * the layer's state, which is the seam between the two.
 */

/** Read the shared layer state the wrapper is supposed to be driving. */
const layer = () => useTooltipLayer();

/** Mount a wrapper around two nodes, so "one wrapper, not one per child" is observable. */
const tooltip = (props: Record<string, unknown> = {}) =>
    mountApp(Tooltip, {
        props: { text: "Nach Album sortieren", ...props },
        slots: { default: "<span>1.234</span><span>Songs</span>" },
        attachTo: document.body
    });

/** Hover the wrapper with a real mouse and let the hover-intent delay elapse. */
const hover = async (el: Element, delay = 300): Promise<void> => {
    el.dispatchEvent(new window.PointerEvent("pointerenter", { pointerType: "mouse" }));
    await vi.advanceTimersByTimeAsync(delay);
};

describe("Tooltip", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = "";
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = "";
    });

    it("wraps the whole slot in one node, so a multi-part trigger has a single anchor", () => {
        const wrapper = tooltip();

        expect(wrapper.element.tagName).toBe("SPAN");
        expect(wrapper.classes()).toContain("tooltip");
        expect(wrapper.findAll(".tooltip")).toHaveLength(1);
        expect(wrapper.text()).toContain("1.234");
        expect(wrapper.text()).toContain("Songs");
    });

    it("is itself the CSS anchor the tip will be placed against", () => {
        // The directive stamps a unique `anchor-name` inline; without it the layer has
        // nothing to point `position-anchor` at and every tip lands in the same corner.
        expect((tooltip().element as HTMLElement).style.getPropertyValue("anchor-name")).toMatch(/^--/u);
    });

    it("hands the shared layer its text, its side and its own anchor on hover", async () => {
        const wrapper = tooltip({ placement: "bottom" });

        await hover(wrapper.element);

        expect(layer().text.value).toBe("Nach Album sortieren");
        expect(layer().placement.value).toBe("bottom");
        expect(layer().anchorName.value).toBe((wrapper.element as HTMLElement).style.getPropertyValue("anchor-name"));
        expect(layer().visible.value).toBe(true);
    });

    it("sits on top by default, and waits the hover-intent delay before showing", async () => {
        const wrapper = tooltip();

        wrapper.element.dispatchEvent(new window.PointerEvent("pointerenter", { pointerType: "mouse" }));
        await vi.advanceTimersByTimeAsync(299);
        expect(layer().visible.value).toBe(false);

        await vi.advanceTimersByTimeAsync(1);
        expect(layer().visible.value).toBe(true);
        expect(layer().placement.value).toBe("top");
    });

    it("honours a caller's own delay rather than the default", async () => {
        const wrapper = tooltip({ delay: 0 });

        await hover(wrapper.element, 0);

        expect(layer().visible.value).toBe(true);
    });

    it("describes the trigger only while the tip is on screen", async () => {
        const wrapper = tooltip();
        expect(wrapper.attributes("aria-describedby")).toBeUndefined();

        await hover(wrapper.element);
        expect(wrapper.attributes("aria-describedby")).toBe(TOOLTIP_ID);

        wrapper.element.dispatchEvent(new window.PointerEvent("pointerleave", { pointerType: "mouse" }));
        await nextTick();
        expect(wrapper.attributes("aria-describedby")).toBeUndefined();
    });

    it("follows a changed text into a tip that is already open", async () => {
        /*
         * The reason `options` is a computed. A parent whose hint depends on state — the
         * volume readout, a label re-translated on a locale switch — must not leave a stale
         * string floating over the page.
         */
        const text = ref("Lautstärke 40 %");
        const host = defineComponent({
            setup: () => () => h(Tooltip, { text: text.value }, () => h("span", "40")),
            name: "TooltipHost"
        });
        const wrapper = mountApp(host, { attachTo: document.body });

        await hover(wrapper.find(".tooltip").element);
        expect(layer().text.value).toBe("Lautstärke 40 %");

        text.value = "Lautstärke 70 %";
        await nextTick();

        expect(layer().text.value).toBe("Lautstärke 70 %");
    });

    it("stays inert when there is no hint to give, so a caller can switch one off", async () => {
        const wrapper = tooltip({ text: "" });

        await hover(wrapper.element);

        expect(layer().visible.value).toBe(false);
    });
});
