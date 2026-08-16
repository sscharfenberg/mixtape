import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetPlayerSpeedForTests, usePlayerSpeed } from "Composables/usePlayerSpeed";
import { resetSleepTimerForTests, useSleepTimer } from "Composables/useSleepTimer";
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

/** The four groups, in template order: play mode, repeat, speed, sleep timer. */
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
        resetSleepTimerForTests();
        window.localStorage.clear();
        setPage({ props: { auth: { user: null } } });
    });

    // A timer armed by a spec keeps an interval running for the rest of the file, and its
    // ticks would land inside whichever test came next.
    afterEach(() => {
        resetSleepTimerForTests();
    });

    it("offers four settings: two binary modes, the three speeds and the sleep timer", () => {
        // The sleep row is four wide on a song — off plus three durations. Its fifth option
        // exists only where a chapter boundary does; see the sleep-timer block below.
        const wrapper = mountApp(PlayerSettings);

        expect(groups(wrapper)).toHaveLength(4);
        expect(groups(wrapper).map(group => group.findAll("input").length)).toStrictEqual([2, 2, 3, 4]);
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

    it("starts with both modes off, normal speed and no sleep timer, matching a fresh player", () => {
        const wrapper = mountApp(PlayerSettings);

        expect(groups(wrapper).map(group => group.attributes("style"))).toStrictEqual([
            expect.stringContaining("--selected: 0"),
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

        expect(new Set(names).size).toBe(4);
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
            const live = () => wrapper.find(".player-settings__rate");

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

            expect(wrapper.find(".player-settings__rate").exists()).toBe(true);
        });

        it("does not announce the rate a second time, since the bar's badge already does", () => {
            // Two live regions for one change means hearing it twice.
            const wrapper = mountApp(PlayerSettings);

            expect(wrapper.find(".player-settings__rate").attributes("aria-hidden")).toBe("true");
        });
    });

    describe("the sleep-timer row", () => {
        /** A queue track, optionally an audiobook chapter — which is what grows the row. */
        const track = (isChapter = false): QueueTrack => ({
            id: "t1",
            name: "Track",
            artist: null,
            album: null,
            coverUrl: null,
            duration: 200,
            href: "/music/songs/t1",
            streamUrl: "/music/songs/t1/stream",
            ...(isChapter ? { isChapter: true as const } : {})
        });

        /** The sleep row's group and its readout — both the last of their kind in the panel. */
        const sleepGroup = (wrapper: ReturnType<typeof mountApp>) => groups(wrapper)[3];
        const readout = (wrapper: ReturnType<typeof mountApp>) => wrapper.find(".player-settings__countdown");
        /** The clock itself, which is laid over a hidden sizer holding the width open. */
        const clock = (wrapper: ReturnType<typeof mountApp>) => wrapper.find(".player-settings__countdown-value");

        it("offers off and the three durations as text, since no glyph means 'half an hour'", () => {
            const wrapper = mountApp(PlayerSettings);

            expect(sleepGroup(wrapper).findAll(".option-bubbles__text").map(node => node.text())).toStrictEqual([
                translate("player.settings.sleepOffShort"),
                "15",
                "30",
                "60"
            ]);
        });

        it("arms the timer from the row, and moves the pill onto the choice", async () => {
            const wrapper = mountApp(PlayerSettings);

            await choose(wrapper, 3, 2);

            expect(useSleepTimer().selection.value).toBe("30");
            expect(useSleepTimer().remaining.value).toBe(1800);
            expect(sleepGroup(wrapper).attributes("style")).toContain("--selected: 2");
        });

        it("cancels from the off option", async () => {
            const wrapper = mountApp(PlayerSettings);

            await choose(wrapper, 3, 2);
            await choose(wrapper, 3, 0);

            expect(useSleepTimer().isArmed.value).toBe(false);
            expect(sleepGroup(wrapper).attributes("style")).toContain("--selected: 0");
        });

        it("says how long is left, and only while a duration is running", async () => {
            const wrapper = mountApp(PlayerSettings);

            expect(readout(wrapper).classes()).not.toContain("player-settings__live--on");

            await choose(wrapper, 3, 3);

            expect(readout(wrapper).classes()).toContain("player-settings__live--on");
            // The marker is part of the readout, as it is on the speed row: without it the
            // clock reads as a fifth option rather than as an answer beside four.
            expect(clock(wrapper).text()).toBe("▸ 1:00:00");
        });

        it("holds the readout's width open with the longest clock it can show", () => {
            /*
             * Same trap as the speed row's, one row down and one turn harder. That readout is
             * always three characters, so reserving its box is enough; this one is four at
             * "4:12" and seven at "1:00:00", so a single span would resize the `width: auto`
             * panel when the timer was armed AND again as it crossed ten minutes — under a
             * reader who is looking at it, which is the whole thing the reserved box prevents.
             * A browser caught this: the panel narrowed by 26px the moment a 15-minute timer
             * was armed. So the sizer holds the longest form open and the clock lies over it.
             */
            const wrapper = mountApp(PlayerSettings);

            expect(readout(wrapper).exists()).toBe(true);
            expect(wrapper.find(".player-settings__countdown-sizer").text()).toBe("▸ 1:00:00");
        });

        it("marks the trigger with the moon while a timer runs, and not before", async () => {
            // The one ambient sign a timer exists. Deliberately not a countdown — see the
            // component banner: a clock on screen is a thing a listener lies there and watches.
            const wrapper = mountApp(PlayerSettings);

            expect(wrapper.find(".player-settings__mark").exists()).toBe(false);

            await choose(wrapper, 3, 1);

            expect(wrapper.find(".player-settings__mark").exists()).toBe(true);
            expect(wrapper.find(".player-settings__mark use").attributes("href")).toBe("#dark");
        });

        it("hides the mark from assistive tech, and renames the trigger instead", async () => {
            // A mark announced separately would be an unlabelled image beside a button; the
            // button saying what it now means is the same fact, in the place a name belongs.
            const wrapper = mountApp(PlayerSettings);

            expect(wrapper.find(".popover-button").attributes("aria-label")).toBe(translate("player.bar.settings"));

            await choose(wrapper, 3, 1);

            expect(wrapper.find(".player-settings__mark").attributes("aria-hidden")).toBe("true");
            expect(wrapper.find(".popover-button").attributes("aria-label")).toBe(
                translate("player.bar.settingsSleeping")
            );
        });

        it("offers the end of the chapter only when a chapter is what is playing", async () => {
            // For a three-minute song "stop at the end of this track" is a short timer wearing
            // a costume; on a book it is the one boundary worth waiting for.
            const wrapper = mountApp(PlayerSettings);
            expect(sleepGroup(wrapper).findAll("input")).toHaveLength(4);

            usePlayerQueue().enqueue([track(true)]);
            await nextTick();

            expect(sleepGroup(wrapper).findAll("input")).toHaveLength(5);
            expect(sleepGroup(wrapper).find("use").attributes("href")).toBe("#audiobook");
        });

        it("keeps the chapter option while it is armed, even once the queue moves on", async () => {
            /*
             * Otherwise the option vanishes under a running timer, the pill has nowhere to sit
             * and falls back to the first — a control reporting that the timer was cancelled
             * while it counts down behind it.
             */
            usePlayerQueue().enqueue([track(true)]);
            const wrapper = mountApp(PlayerSettings);
            await nextTick();

            await choose(wrapper, 3, 4);
            expect(useSleepTimer().isArmed.value).toBe(true);

            usePlayerQueue().clear();
            usePlayerQueue().enqueue([track(false)]);
            await nextTick();

            expect(sleepGroup(wrapper).findAll("input")).toHaveLength(5);
            expect(sleepGroup(wrapper).attributes("style")).toContain("--selected: 4");
        });

        it("follows the timer when it is cancelled from somewhere else", async () => {
            // The bar's pill cancels the same singleton this row draws from, so the row has to
            // come back to "off" without being told.
            const wrapper = mountApp(PlayerSettings);

            await choose(wrapper, 3, 1);
            useSleepTimer().cancel();
            await nextTick();

            expect(sleepGroup(wrapper).attributes("style")).toContain("--selected: 0");
            expect(wrapper.find(".player-settings__mark").exists()).toBe(false);
        });
    });
});
