import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import PlayerSettings from "./PlayerSettings.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The wiring between two bubble groups and the queue's two mode flags — which is where
 * this component can go wrong in a way neither the composable's spec nor OptionBubbles'
 * can see.
 *
 * The trap it exists for: the composable exposes TOGGLES, not setters, so a group that
 * reported its value on every change — including a click on the option already
 * chosen, which a radiogroup allows — would flip the mode straight back off while the
 * pill sat still. Both directions of both rows are asserted for that reason.
 *
 * Not tested here: that the popover opens upward, and that the pill actually slides.
 * Both are layout, and belong to Playwright.
 */

/** The two groups, in template order: play mode first, repeat second. */
const groups = (wrapper: ReturnType<typeof mountApp>) => wrapper.findAll(".option-bubbles");

/** Choose an option by index inside one group — what a click on a glyph does. */
const choose = async (wrapper: ReturnType<typeof mountApp>, group: number, option: number) => {
    await groups(wrapper)[group].findAll("input")[option].trigger("change");
    await nextTick();
};

describe("PlayerSettings", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        window.localStorage.clear();
        setPage({ props: { auth: { user: null } } });
    });

    it("offers exactly two settings, each a two-option choice", () => {
        const wrapper = mountApp(PlayerSettings);

        expect(groups(wrapper)).toHaveLength(2);
        expect(groups(wrapper).map(group => group.findAll("input").length)).toStrictEqual([2, 2]);
    });

    it("names the off state of each mode with its own glyph, not a dimmed one", () => {
        // The reason two icons had to be found at all: "shuffle off" is a mode called "in
        // order", and a lone unlit glyph does not say that. Scoped to the panel, since the
        // trigger's own gear is a `use` too.
        const wrapper = mountApp(PlayerSettings);

        expect(wrapper.findAll(".option-bubbles use").map(node => node.attributes("href"))).toStrictEqual([
            "#shuffle_off",
            "#shuffle",
            "#repeat_off",
            "#repeat"
        ]);
    });

    it("wears the gear in the bar, so it reads as settings rather than a fourth transport button", () => {
        expect(mountApp(PlayerSettings).find(".popover-button use").attributes("href")).toBe("#settings");
    });

    it("starts with both modes off, matching a fresh queue", () => {
        const wrapper = mountApp(PlayerSettings);

        expect(groups(wrapper).map(group => group.attributes("style"))).toStrictEqual([
            expect.stringContaining("--selected: 0"),
            expect.stringContaining("--selected: 0")
        ]);
    });

    it("turns shuffle on, and shows it", async () => {
        const wrapper = mountApp(PlayerSettings);

        await choose(wrapper, 0, 1);

        expect(usePlayerQueue().shuffle.value).toBe(true);
        expect(groups(wrapper)[0].attributes("style")).toContain("--selected: 1");
    });

    it("turns shuffle back off from the other option", async () => {
        const wrapper = mountApp(PlayerSettings);

        await choose(wrapper, 0, 1);
        await choose(wrapper, 0, 0);

        expect(usePlayerQueue().shuffle.value).toBe(false);
    });

    it("leaves a mode alone when the option already chosen is chosen again", async () => {
        // THE trap: a toggle called unconditionally would read this as "flip", turning off
        // the mode the listener just re-affirmed.
        const wrapper = mountApp(PlayerSettings);

        await choose(wrapper, 0, 1);
        await choose(wrapper, 0, 1);

        expect(usePlayerQueue().shuffle.value).toBe(true);
    });

    it("drives repeat from the second row, independently of the first", async () => {
        const wrapper = mountApp(PlayerSettings);

        await choose(wrapper, 1, 1);

        expect(usePlayerQueue().repeat.value).toBe(true);
        expect(usePlayerQueue().shuffle.value).toBe(false);
        expect(groups(wrapper)[1].attributes("style")).toContain("--selected: 1");
    });

    it("follows the queue when the mode is changed from somewhere else", async () => {
        // The panel is a view of shared state, not the owner of it: the composable is a
        // module singleton, and a keyboard shortcut or a restored queue can move these
        // flags with the popover open.
        const wrapper = mountApp(PlayerSettings);

        usePlayerQueue().toggleShuffle();
        await nextTick();

        expect(groups(wrapper)[0].attributes("style")).toContain("--selected: 1");
    });

    it("gives the two groups different radio names, or choosing in one would clear the other", () => {
        const wrapper = mountApp(PlayerSettings);
        const names = groups(wrapper).map(group => group.find("input").attributes("name"));

        expect(new Set(names).size).toBe(2);
    });
});
