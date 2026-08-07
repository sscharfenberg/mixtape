import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetPlayerVolumeForTests, usePlayerVolume } from "Composables/usePlayerVolume";
import { mountApp } from "Testing/mount";
import PlayerVolumeHud from "./PlayerVolumeHud.vue";

/*
 * The level readout that appears in the middle of the screen when the volume changes.
 *
 * What is worth pinning is WHEN it is on screen, not what it looks like — where the box
 * lands, that it is click-through, and that it really covers the page are engine facts and
 * live in tests/e2e/app/player.spec.ts. Three of the four cases below are about silence:
 * a page load must not raise it, a change to the same figure must not raise it, and it has
 * to go away on its own.
 *
 * The component teleports to <body>, so assertions read the document rather than the
 * wrapper — the same shape ToastContainer's spec uses.
 */

/** The box, or null while nothing is shown. */
const hud = (): Element | null => document.querySelector(".player-volume-hud");

describe("PlayerVolumeHud", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        resetPlayerVolumeForTests();
        document.body.innerHTML = "";
    });

    it("says nothing on a page load, where the level was merely restored", async () => {
        // The stored level arrives on the first bind, and a box announcing a change nobody
        // made would greet every visit.
        mountApp(PlayerVolumeHud);
        await nextTick();

        expect(hud()).toBeNull();
    });

    it("shows the new level as a percentage when it changes", async () => {
        mountApp(PlayerVolumeHud);

        usePlayerVolume().setVolume(0.79);
        await nextTick();

        expect(hud()?.textContent).toBe("79%");
    });

    it("shows 0% for a mute, which is the other way to change what is audible", async () => {
        // `M` has even less on screen to watch than the arrows do: no slider moves, and the
        // only other sign is a glyph swap in the bar.
        mountApp(PlayerVolumeHud);

        usePlayerVolume().toggleMute();
        await nextTick();

        expect(hud()?.textContent).toBe("0%");
    });

    it("goes away on its own, and a second change restarts the clock rather than stacking one", async () => {
        mountApp(PlayerVolumeHud);

        usePlayerVolume().setVolume(0.5);
        await nextTick();

        // Most of the way through the first box's life, a second press arrives.
        vi.advanceTimersByTime(1_500);
        usePlayerVolume().setVolume(0.55);
        await nextTick();

        // The first timer would have fired by now. The box is still up, because the second
        // change replaced it rather than queueing behind it.
        vi.advanceTimersByTime(1_000);
        await nextTick();
        expect(hud()?.textContent).toBe("55%");

        vi.advanceTimersByTime(2_000);
        await nextTick();
        expect(hud()).toBeNull();
    });

    it("stays quiet when a press changes nothing, as ↑ at full volume does", async () => {
        // `setVolume` clamps, so the figure is the one already showing — there is no change
        // to announce, and the box is not raised for a key that did nothing.
        mountApp(PlayerVolumeHud);

        usePlayerVolume().setVolume(1.5);
        await nextTick();

        expect(hud()).toBeNull();
    });
});
