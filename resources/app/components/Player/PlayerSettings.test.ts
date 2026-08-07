import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetPlayerSpeedForTests, usePlayerSpeed } from "Composables/usePlayerSpeed";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
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

/** The three groups, in template order: play mode, repeat, speed. */
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
        resetPlayerSpeedForTests();
        window.localStorage.clear();
        setPage({ props: { auth: { user: null } } });
    });

    it("offers three settings: two binary modes and the three speeds", () => {
        const wrapper = mountApp(PlayerSettings);

        expect(groups(wrapper)).toHaveLength(3);
        expect(groups(wrapper).map(group => group.findAll("input").length)).toStrictEqual([2, 2, 3]);
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

    it("starts with both modes off and normal speed, matching a fresh player", () => {
        const wrapper = mountApp(PlayerSettings);

        expect(groups(wrapper).map(group => group.attributes("style"))).toStrictEqual([
            expect.stringContaining("--selected: 0"),
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

    it("gives every group its own radio name, or choosing in one would clear another", () => {
        const wrapper = mountApp(PlayerSettings);
        const names = groups(wrapper).map(group => group.find("input").attributes("name"));

        expect(new Set(names).size).toBe(3);
    });

    describe("the speed row", () => {
        it("draws its options as text, since no glyph means 'three times as fast'", () => {
            const wrapper = mountApp(PlayerSettings);

            expect(groups(wrapper)[2].findAll(".option-bubbles__text").map(node => node.text())).toStrictEqual([
                "1×",
                "2×",
                "3×"
            ]);
        });

        it("sets the speed, and moves the pill with it", async () => {
            const wrapper = mountApp(PlayerSettings);

            await choose(wrapper, 2, 2);

            expect(usePlayerSpeed().speed.value).toBe(3);
            expect(groups(wrapper)[2].attributes("style")).toContain("--selected: 2");
        });

        it("comes back to normal from the first option", async () => {
            const wrapper = mountApp(PlayerSettings);

            await choose(wrapper, 2, 1);
            await choose(wrapper, 2, 0);

            expect(usePlayerSpeed().speed.value).toBe(1);
        });

        it("leaves the speed alone when the option already chosen is chosen again", async () => {
            /*
             * The same shape as the trap above, and it does NOT bite here — this composable
             * exposes a setter rather than a toggle, so re-selecting writes the value it
             * already holds. Asserted anyway, because "which of these rows is a toggle and
             * which is a setter" is exactly the distinction a later refactor flattens.
             */
            const wrapper = mountApp(PlayerSettings);

            await choose(wrapper, 2, 2);
            await choose(wrapper, 2, 2);

            expect(usePlayerSpeed().speed.value).toBe(3);
        });

        it("spells the speed out for a screen reader, since '3×' is not a name", () => {
            const wrapper = mountApp(PlayerSettings);

            expect(groups(wrapper)[2].findAll("input").map(input => input.attributes("aria-label"))).toStrictEqual([
                translate("player.settings.speedOption").replace("{rate}", "1"),
                translate("player.settings.speedOption").replace("{rate}", "2"),
                translate("player.settings.speedOption").replace("{rate}", "3")
            ]);
        });

        it("follows the speed when it is changed from somewhere else", async () => {
            // A held Space does not move this row — the skim is not the setting — but the
            // settings themselves are shared state a restored session can have already set.
            const wrapper = mountApp(PlayerSettings);

            usePlayerSpeed().setSpeed(2);
            await nextTick();

            expect(groups(wrapper)[2].attributes("style")).toContain("--selected: 1");
        });

        it("keeps the PILL on the setting while a skim doubles what is actually playing", async () => {
            // The two numbers are separate on purpose: a hold must not look like a choice.
            // And it could not be shown by the pill anyway — 2× skims to 4×, which is not one
            // of the three options, so there is nowhere for the pill to go.
            const wrapper = mountApp(PlayerSettings);
            usePlayerSpeed().setSpeed(2);
            await nextTick();

            usePlayerSpeed().setSkimming(true);
            await nextTick();

            expect(usePlayerSpeed().effectiveRate.value).toBe(4);
            expect(groups(wrapper)[2].attributes("style")).toContain("--selected: 1");
        });

        it("says what is actually playing beside the pill, and only while it differs", async () => {
            const wrapper = mountApp(PlayerSettings);
            const live = () => wrapper.find(".player-settings__live");

            usePlayerSpeed().setSpeed(3);
            await nextTick();
            expect(live().classes()).not.toContain("player-settings__live--on");

            usePlayerSpeed().setSkimming(true);
            await nextTick();

            expect(live().classes()).toContain("player-settings__live--on");
            // The marker is part of the readout: without it "6× 1× 2× 3×" reads as four options.
            expect(live().text()).toBe("▸ 6×");

            usePlayerSpeed().setSkimming(false);
            await nextTick();
            expect(live().classes()).not.toContain("player-settings__live--on");
        });

        it("keeps the readout's box even when it has nothing to say", async () => {
            /*
             * Hidden, not removed. The popover is `width: auto`, so a readout that came and
             * went would resize the whole panel under a reader who is holding a key down —
             * and the moment it appears is exactly the moment they are least able to follow
             * the layout moving. `v-if` here would be the natural thing to write and the
             * wrong one; this is what stops it being written.
             */
            const wrapper = mountApp(PlayerSettings);

            expect(wrapper.find(".player-settings__live").exists()).toBe(true);
        });

        it("does not announce the rate a second time, since the bar's badge already does", () => {
            // Two live regions for one change means hearing it twice.
            const wrapper = mountApp(PlayerSettings);

            expect(wrapper.find(".player-settings__live").attributes("aria-hidden")).toBe("true");
        });
    });
});
