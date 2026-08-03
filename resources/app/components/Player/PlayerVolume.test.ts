import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetPlayerVolumeForTests, usePlayerVolume } from "Composables/usePlayerVolume";
import { resetInertia } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import PlayerVolume from "./PlayerVolume.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * What this control DRAWS, given the level — the half usePlayerVolume's own spec cannot
 * see, and the half the requirement was written in terms of ("if the volume is reduced
 * to zero, switch the volume icon to a mute icon").
 *
 * Not tested here, on purpose: that the popover opens upward, which is layout and
 * belongs to Playwright, and that the level actually attenuates output, which needs a
 * decoder and belongs to the same place.
 */

/**
 * The two glyphs, read separately — they answer different questions and are SUPPOSED to
 * differ (the trigger reports audibility, the inner button reports the mute flag), so
 * asserting them as one set would hide exactly the case worth checking.
 *
 * Read as plain `href` even though Icon's template writes `xlink:href`: the attribute
 * SERIALIZES under the namespaced name but the DOM exposes it under the bare one, which
 * is the trap Icon.test.ts already documents. (The reverse of the E2E specs, where a real
 * browser insists on the qualified read.)
 */
const triggerGlyph = (wrapper: ReturnType<typeof mountApp>): string | undefined =>
    wrapper.find(".popover-button use").attributes("href");

const muteGlyph = (wrapper: ReturnType<typeof mountApp>): string | undefined =>
    wrapper.find(".player-volume__mute use").attributes("href");

describe("PlayerVolume", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerVolumeForTests();
        window.localStorage.clear();
    });

    it("shows volume on the trigger and mute in the panel while there is something to hear", () => {
        const wrapper = mountApp(PlayerVolume);

        expect(triggerGlyph(wrapper)).toBe("#volume");
        expect(muteGlyph(wrapper)).toBe("#mute");
    });

    it("switches the trigger to volume_off when the level is turned to zero", async () => {
        const wrapper = mountApp(PlayerVolume);

        usePlayerVolume().setVolume(0);
        await nextTick();

        expect(triggerGlyph(wrapper)).toBe("#volume_off");
        // …while the inner button still OFFERS a mute, because none has been applied.
        // This is the pair that would collapse if both glyphs came from one condition.
        expect(muteGlyph(wrapper)).toBe("#mute");
    });

    it("switches both when muted at an audible level", async () => {
        const wrapper = mountApp(PlayerVolume);

        usePlayerVolume().setVolume(0.7);
        usePlayerVolume().toggleMute();
        await nextTick();

        // The trigger reports audibility, so a mute silences it out in the bar too.
        expect(triggerGlyph(wrapper)).toBe("#volume_off");
        // The inner button reports the flag, which is now on.
        expect(muteGlyph(wrapper)).toBe("#volume_off");
    });

    it("shows the level as a percentage, and moves the drawn fill with it", async () => {
        const wrapper = mountApp(PlayerVolume);

        usePlayerVolume().setVolume(0.35);
        await nextTick();

        expect(wrapper.find(".player-volume__readout").text()).toBe("35%");
        // The fill is drawn rather than asked of the native track, because Chromium has no
        // equivalent of Firefox's ::-moz-range-progress — so its height is real markup and
        // can be asserted here.
        expect(wrapper.find(".player-volume__level").attributes("style")).toContain("height: 35%");
    });

    it("sets the level from the slider as it is dragged, not only on release", async () => {
        const wrapper = mountApp(PlayerVolume);
        const slider = wrapper.find<HTMLInputElement>(".player-volume__input");

        slider.element.value = "0.2";
        await slider.trigger("input");

        // `input`, not `change`: a volume that only moves on release cannot be found by ear.
        expect(usePlayerVolume().volume.value).toBeCloseTo(0.2);
    });

    it("mutes and un-mutes from the button, and says which it will do next", async () => {
        const wrapper = mountApp(PlayerVolume);
        const button = wrapper.find(".player-volume__mute");

        expect(button.attributes("aria-pressed")).toBe("false");

        await button.trigger("click");
        expect(usePlayerVolume().isMuted.value).toBe(true);
        expect(button.attributes("aria-pressed")).toBe("true");

        await button.trigger("click");
        expect(usePlayerVolume().isMuted.value).toBe(false);
    });
});
