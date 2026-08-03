import { beforeEach, describe, expect, it } from "vitest";
import { bindVolumeElement, resetPlayerVolumeForTests, usePlayerVolume } from "Composables/usePlayerVolume";

/*
 * The LEVEL, on its own — split out of usePlayerAudio's spec along with the module itself.
 *
 * Everything here is plain state plus two writes to an element, which is why it is a unit
 * test at all: whether the attenuation is audible needs a decoder and belongs to the
 * Playwright spec, and whether the CONTROL draws the right glyph belongs to
 * PlayerVolume.test.ts. What is left is the part with the traps in it — the clamp that
 * stops `element.volume` throwing, and the two ways of coming back from silence.
 *
 * No <audio> element is attached through usePlayerAudio here: this module never owns one,
 * it is handed one. Binding directly is both simpler and the honest shape of the contract.
 */

/** Hand the module an element, as usePlayerAudio does from `attach()`. */
const bindElement = (): HTMLAudioElement => {
    const element = document.createElement("audio");
    document.body.appendChild(element);
    bindVolumeElement(element);

    return element;
};

describe("usePlayerVolume", () => {
    beforeEach(() => {
        resetPlayerVolumeForTests();
        window.localStorage.clear();
    });

    describe("the output level", () => {
        it("writes the level onto the element, clamped to what it will accept", () => {
            const element = bindElement();
            const player = usePlayerVolume();

            player.setVolume(0.4);
            expect(element.volume).toBeCloseTo(0.4);

            // Assigning outside 0–1 THROWS on a real media element, so the clamp is not
            // cosmetic — an unclamped value from a slider, a stored payload or a keyboard
            // step would break playback rather than merely sound wrong.
            player.setVolume(3);
            expect(element.volume).toBe(1);
            player.setVolume(-2);
            expect(element.volume).toBe(0);
        });

        it("treats a level of zero and a mute as separate states that look the same", () => {
            bindElement();
            const player = usePlayerVolume();

            player.setVolume(0);
            expect(player.isMuted.value).toBe(false);
            // Silent without being muted — one glyph covers both, which is why the control
            // reads `isSilent` and not `isMuted`.
            expect(player.isSilent.value).toBe(true);

            player.setVolume(0.5);
            player.toggleMute();
            expect(player.volume.value).toBeCloseTo(0.5);
            expect(player.isSilent.value).toBe(true);
        });

        it("comes back to the level a mute interrupted", () => {
            const element = bindElement();
            const player = usePlayerVolume();

            player.setVolume(0.6);
            player.toggleMute();
            expect(element.muted).toBe(true);

            player.toggleMute();
            expect(element.muted).toBe(false);
            expect(player.volume.value).toBeCloseTo(0.6);
        });

        it("un-mutes to something audible when the level was dragged to zero", () => {
            bindElement();
            const player = usePlayerVolume();

            player.setVolume(0.8);
            player.setVolume(0);
            player.toggleMute();

            // The trap: clearing `muted` over a level of zero is still silence, so the
            // press would look like it did nothing at all.
            player.toggleMute();
            expect(player.isMuted.value).toBe(false);
            expect(player.volume.value).toBeGreaterThan(0);
        });

        it("lifts a mute when the slider is moved up", () => {
            bindElement();
            const player = usePlayerVolume();

            player.toggleMute();
            player.setVolume(0.3);

            // The slider is the more specific gesture; one that visibly moves while the
            // player stays silent is a control people press twice and then distrust.
            expect(player.isMuted.value).toBe(false);
            expect(player.isSilent.value).toBe(false);
        });

        it("remembers the level across a page load, and applies it to a fresh element", () => {
            bindElement();
            usePlayerVolume().setVolume(0.25);
            usePlayerVolume().toggleMute();

            // A new page: module state gone, storage kept — exactly what a reload leaves.
            resetPlayerVolumeForTests();
            const element = bindElement();

            expect(usePlayerVolume().volume.value).toBeCloseTo(0.25);
            expect(element.volume).toBeCloseTo(0.25);
            expect(element.muted).toBe(true);
        });

        it("clamps a stored level that would throw when assigned", () => {
            /*
             * Assigning `element.volume = -3` RAISES on a real media element, so a corrupt
             * or hand-edited entry has to be clamped rather than take the player down at
             * attach time.
             *
             * Deliberately asserted with a NEGATIVE value: clamping 5 lands on 1, which is
             * also the default, so that version of this test would pass with hydration
             * deleted outright. Zero is reachable only by the clamp actually running.
             */
            window.localStorage.setItem("mixtape.volume.v1", JSON.stringify({ volume: -3, muted: "yes" }));

            const element = bindElement();

            expect(element.volume).toBe(0);
            // A non-boolean `muted` is not adopted either.
            expect(usePlayerVolume().isMuted.value).toBe(false);
        });
    });
});
