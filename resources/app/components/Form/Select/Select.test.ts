import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetInertia } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import Select from "./Select.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The biggest component in the app with no spec until now, and thirteen callers — every DataTable's
 * page-size control among them. What is worth pinning is the part that decides things rather than
 * the part that draws them:
 *
 *   - ORDERING, including the one special case: "other" sinks to the bottom whatever its label,
 *     because a catch-all competing alphabetically with real options reads as a real option.
 *   - the ARIA wiring between trigger and listbox. It is icon-and-label only, so `aria-expanded`,
 *     `aria-controls` and `aria-selected` are the entire contract for anyone not using a mouse.
 *   - TYPEAHEAD, which is genuinely subtle: successive letters build a buffer inside a 500ms idle
 *     window, and the window resetting is what makes "Fo" then a pause then "B" jump to B rather
 *     than searching "fob".
 *   - CLEARING, which has to emit like any other change so a parent's `selected` can follow.
 *
 * Not here: where the panel lands and whether it flipped above the button. That is
 * `position-try: flip-block` plus a real measurement, so it needs a browser — the component reads
 * its own geometry to mirror the border radii, and happy-dom reports every box at zero.
 */

const OPTIONS = [
    { value: "beta", label: "Beta" },
    { value: "other", label: "Aardvark" }, // alphabetically first, must still sink
    { value: "alpha", label: "Alpha" }
];

/** Mount a select, open unless told otherwise. */
const select = (props: Record<string, unknown> = {}) =>
    mountApp(Select, { props: { options: OPTIONS, ...props }, attachTo: document.body });

/** Option labels in render order. */
const labels = (wrapper: ReturnType<typeof select>) =>
    wrapper.findAll("button[data-value]").map(node => node.text().trim());

/** Open the listbox the way a reader does. */
const open = async (wrapper: ReturnType<typeof select>) => {
    await wrapper.find(".form-select__button").trigger("click");
    await nextTick();
};

describe("Select", () => {
    beforeEach(() => {
        resetInertia();
        document.body.innerHTML = "";
    });

    it("sorts by label, but sinks the catch-all to the bottom", async () => {
        // "Aardvark" would otherwise sort first and read as an ordinary choice.
        const wrapper = select();
        await open(wrapper);

        expect(labels(wrapper)).toStrictEqual(["Alpha", "Beta", "Aardvark"]);
    });

    it("keeps the caller's order when asked not to sort", async () => {
        const wrapper = select({ sort: false });
        await open(wrapper);

        expect(labels(wrapper)).toStrictEqual(["Beta", "Aardvark", "Alpha"]);
    });

    it("shows the placeholder until something is selected, then the label", () => {
        // The fallback is translated, which is why the component takes no default string.
        expect(select().find(".form-select__button").text()).toContain("Bitte auswählen");
        expect(select({ placeholder: "Seitengröße" }).find(".form-select__button").text()).toContain("Seitengröße");
        expect(select({ selected: "alpha" }).find(".form-select__button").text()).toContain("Alpha");
    });

    it("ties the trigger to its listbox and reports whether it is open", async () => {
        // The whole non-mouse contract: a listbox nobody can find is a listbox nobody can use.
        const wrapper = select();
        const trigger = wrapper.find(".form-select__button");

        expect(trigger.attributes("aria-expanded")).toBe("false");
        // The listbox itself is not rendered until it opens, so the id is checked against the real
        // element once it exists — a trigger pointing at nothing is the failure worth catching.
        expect(trigger.attributes("aria-controls")).toBeTruthy();

        await open(wrapper);

        expect(wrapper.find(".form-select__button").attributes("aria-expanded")).toBe("true");
        expect(wrapper.find("[role='listbox']").attributes("id")).toBe(trigger.attributes("aria-controls"));
    });

    it("marks the selected option, and only that one", async () => {
        const wrapper = select({ selected: "alpha" });
        await open(wrapper);

        const states = wrapper.findAll("button[data-value]").map(node => node.attributes("aria-selected"));

        expect(states.filter(state => state === "true")).toHaveLength(1);
        expect(wrapper.find("button[data-value='alpha']").attributes("aria-selected")).toBe("true");
    });

    it("reports a choice and closes itself", async () => {
        const wrapper = select();
        await open(wrapper);

        await wrapper.find("button[data-value='beta']").trigger("click");
        await nextTick();

        expect(wrapper.emitted("change")).toStrictEqual([["beta"]]);
        expect(wrapper.find(".form-select__button").attributes("aria-expanded")).toBe("false");
    });

    it("clears through the same event, so a parent can follow either way", async () => {
        // Clearing is a change like any other; a separate event would leave callers wiring two.
        const wrapper = select({ selected: "beta", clearable: true });

        await wrapper.find(".form-select__clear").trigger("click");

        expect(wrapper.emitted("change")).toStrictEqual([[""]]);
    });

    it("offers no clear button when there is nothing to clear, or when told not to", () => {
        expect(select({ clearable: true }).find(".form-select__clear").exists()).toBe(false);
        expect(select({ selected: "beta", clearable: false }).find(".form-select__clear").exists()).toBe(false);
        expect(select({ selected: "beta", clearable: true }).find(".form-select__clear").exists()).toBe(true);
    });

    it("cannot be opened while disabled", async () => {
        const wrapper = select({ disabled: true });

        await open(wrapper);

        expect(wrapper.find(".form-select__button").attributes("aria-expanded")).toBe("false");
    });

    it("jumps to an option as letters are typed, and forgets the buffer when idle", async () => {
        /*
         * The two halves of typeahead. "b" reaches Beta; then "a" WITHIN the window makes "ba",
         * which matches nothing and must leave focus alone rather than jumping to Aardvark. After
         * the window lapses, a bare "a" searches afresh.
         */
        vi.useFakeTimers();
        try {
            const wrapper = select();
            await open(wrapper);
            const listbox = wrapper.find("[role='listbox']");

            await listbox.trigger("keydown", { key: "b" });
            expect(document.activeElement?.getAttribute("data-value")).toBe("beta");

            await listbox.trigger("keydown", { key: "a" });
            expect(document.activeElement?.getAttribute("data-value")).toBe("beta");

            vi.advanceTimersByTime(600);
            await listbox.trigger("keydown", { key: "a" });
            // The match is the first in RENDER order, which after sorting is Alpha — not Aardvark,
            // even though "Aardvark" also starts with an a and sorts first alphabetically. The
            // catch-all sits at the bottom, so typeahead reaches it last, which is consistent with
            // it not competing with named options anywhere else either.
            expect(document.activeElement?.getAttribute("data-value")).toBe("alpha");
        } finally {
            vi.useRealTimers();
        }
    });

    it("ignores keys that are not single printable characters", async () => {
        // Tab, Enter and the arrows have to keep their own meanings.
        const wrapper = select();
        await open(wrapper);
        const listbox = wrapper.find("[role='listbox']");

        await listbox.trigger("keydown", { key: "Tab" });
        await listbox.trigger("keydown", { key: "ArrowDown" });

        expect(document.activeElement?.getAttribute("data-value")).toBeFalsy();
    });
});
