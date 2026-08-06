import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import PopOver from "./PopOver.vue";

/*
 * Eight components hang off this one — the site menu, the user menu, the queue menu, the player's
 * volume and settings panels, and the hero menu on every detail page. What it owns is small and
 * all of it is easy to break invisibly:
 *
 *   - the TRIGGER→DIALOG WIRING, which is a shared id. Two popovers with the same `reference`
 *     would form one control, and a trigger whose `popovertarget` misses its dialog does nothing
 *     at all — with no error anywhere.
 *   - the OPEN MIRROR. `isOpen` drives the trigger's lit modifier and comes from the dialog's own
 *     `toggle` event rather than from the click handler, so a light-dismiss or Escape leaves the
 *     glyph correct. Tracking it in the click handler is the obvious mistake and looks fine until
 *     you dismiss by clicking elsewhere.
 *
 * happy-dom has no Popover API, so `togglePopover` is stubbed and the `toggle` EVENT is dispatched
 * by hand: what is asserted is this component's reaction to the platform, not the platform. Where
 * the panel actually lands, and whether light-dismiss really fires, are Playwright's (the volume
 * and settings popovers both have specs there).
 */

/**
 * A `toggle` event the way the platform sends one.
 *
 * happy-dom implements neither the Popover API nor `ToggleEvent`, and the component reads exactly
 * one field off it — so this carries `newState` and nothing else pretends to be a real event.
 */
const toggleEvent = (newState: "open" | "closed"): Event =>
    Object.assign(new Event("toggle"), { newState, oldState: newState === "open" ? "closed" : "open" });

/** Mount a popover, defaulting the id so assertions can address the dialog. */
const popover = (props: Record<string, unknown> = {}) =>
    mount(PopOver, {
        props: { icon: "more", reference: "testMenu", ...props },
        slots: { default: '<ul class="popover-list"><li>an item</li></ul>' },
        attachTo: document.body
    });

describe("PopOver", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
        // Neither happy-dom nor jsdom implements the Popover API; the component only ever calls
        // this one method on the element.
        (HTMLElement.prototype as unknown as { togglePopover: () => void }).togglePopover = vi.fn();
    });

    it("points the trigger at its own dialog", () => {
        const wrapper = popover();

        expect(wrapper.find("button").attributes("popovertarget")).toBe("testMenu");
        expect(wrapper.find("dialog").attributes("id")).toBe("testMenu");
        // A native popover, not a hand-rolled div: light-dismiss, Escape and the top layer all
        // come from the attribute.
        expect(wrapper.find("dialog").attributes("popover")).toBeDefined();
    });

    it("gives two popovers different ids when neither was named", () => {
        // The default is random for exactly this reason: two menus sharing an id would toggle
        // each other, and the app now renders five popovers on one page.
        const first = mount(PopOver, { props: { icon: "more" } });
        const second = mount(PopOver, { props: { icon: "more" } });

        expect(first.find("dialog").attributes("id")).not.toBe(second.find("dialog").attributes("id"));
    });

    it("toggles the dialog when the trigger is pressed", async () => {
        const wrapper = popover();

        await wrapper.find("button").trigger("click");

        expect(document.getElementById("testMenu")!.togglePopover).toHaveBeenCalledTimes(1);
    });

    it("lights the trigger from the dialog's own state, not from the click", async () => {
        // The load-bearing half: closing by light-dismiss or Escape never runs the click handler,
        // so a modifier tracked there stays lit over a closed menu.
        const wrapper = popover();
        const dialog = document.getElementById("testMenu")!;

        expect(wrapper.find("button").classes()).not.toContain("popover-button--open");

        dialog.dispatchEvent(toggleEvent("open"));
        await nextTick();
        expect(wrapper.find("button").classes()).toContain("popover-button--open");

        dialog.dispatchEvent(toggleEvent("closed"));
        await nextTick();
        expect(wrapper.find("button").classes()).not.toContain("popover-button--open");
    });

    it("really unhooks itself on the way out", () => {
        /*
         * The queue's menu unmounts with its panel every time the queue empties, so the teardown
         * runs often. It used to re-query the dialog BY ID to remove the listener, which by then
         * returns null — the element is already detached — so the removal never happened and the
         * optional chain hid it. The component holds the element now; this is what says so.
         */
        const wrapper = popover();
        const dialog = document.getElementById("testMenu")!;
        const remove = vi.spyOn(dialog, "removeEventListener");

        wrapper.unmount();

        expect(remove).toHaveBeenCalledWith("toggle", expect.any(Function));
    });

    it("names the trigger, falling back rather than leaving it unnamed", () => {
        // Every trigger is icon-only, so the accessible name is the only name it has.
        expect(popover({ ariaLabel: "Warteschlangen-Aktionen" }).find("button").attributes("aria-label")).toBe(
            "Warteschlangen-Aktionen"
        );
        expect(popover().find("button").attributes("aria-label")).toBe("Open menu");
    });

    it("merges a caller's modifiers without dropping its own", async () => {
        // Callers pass `popover-button--rounded popover-button--subtle`; the open modifier has to
        // survive alongside them.
        const wrapper = popover({ classString: "popover-button--rounded popover-button--subtle" });
        document
            .getElementById("testMenu")!
            .dispatchEvent(toggleEvent("open"));
        await nextTick();

        expect(wrapper.find("button").classes()).toEqual(
            expect.arrayContaining([
                "popover-button",
                "popover-button--rounded",
                "popover-button--subtle",
                "popover-button--open"
            ])
        );
    });

    it("renders what it was handed, and only when handed it", () => {
        expect(popover().find(".popover-list").exists()).toBe(true);
        expect(popover().find("dialog").text()).toContain("an item");
    });
});
