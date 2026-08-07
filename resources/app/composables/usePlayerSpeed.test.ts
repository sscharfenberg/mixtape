import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { PLAYER_SPEEDS, bindSpeedElement, resetPlayerSpeedForTests, usePlayerSpeed } from "Composables/usePlayerSpeed";

/*
 * Playback speed, and the reason it is two numbers rather than one.
 *
 * `speed` is the SETTING — what the popover shows and what is remembered. `effectiveRate` is
 * what the element is doing, which is the setting doubled while Space is held. Collapse them
 * and two things break at once, both silently: a hold persists 6× as the reader's stated
 * preference, and letting go at a 3× setting drops the player to 1× instead of back to 3×.
 * Nearly every test here is some form of that distinction.
 *
 * The other half is PERSISTENCE, which has one rule worth stating: a stored value is
 * validated against the offered list rather than merely range-checked. It is assigned
 * straight to `element.playbackRate`, and a `2.5` written by a future build would otherwise
 * come back as a speed this version does not offer — leaving the popover with no option lit
 * and no way to explain it.
 *
 * What is NOT here: whether 3× sounds any good. That was measured in a real browser
 * (Chromium honours 1×–6× exactly, keeps the level, and holds the pitch at 441 Hz), and the
 * part a measurement cannot settle — how a time-stretcher treats transients in real music —
 * is a matter for ears, which is why the speeds are a short chosen list and not a slider.
 */

/** An element to drive, standing in for the one PlayerBar renders. */
let element: HTMLAudioElement;

/** Bind a fresh element, as usePlayerAudio's `attach` does. */
const bind = (): void => {
    element = document.createElement("audio");
    bindSpeedElement(element);
};

describe("usePlayerSpeed", () => {
    beforeEach(() => {
        window.localStorage.clear();
        resetPlayerSpeedForTests();
    });

    afterEach(() => {
        bindSpeedElement(null);
    });

    it("starts at normal speed", () => {
        bind();

        expect(usePlayerSpeed().speed.value).toBe(1);
        expect(usePlayerSpeed().effectiveRate.value).toBe(1);
        expect(element.playbackRate).toBe(1);
    });

    it("offers the three speeds the settings row is built from", () => {
        // Exported so the control and the validation cannot disagree about what exists.
        expect([...PLAYER_SPEEDS]).toStrictEqual([1, 2, 3]);
    });

    it("puts a chosen speed on the element, with the pitch preserved", () => {
        /*
         * Without `preservesPitch` the samples merely play faster: every voice goes up an
         * octave and the feature is unusable rather than fast. Asserted from an element
         * explicitly set to FALSE first, because happy-dom (like a real browser) defaults it
         * to true — so an assertion made from the default passes whether or not this module
         * writes it at all, which is exactly the mutation that has to fail.
         */
        bind();
        element.preservesPitch = false;

        usePlayerSpeed().setSpeed(3);

        expect(element.playbackRate).toBe(3);
        expect(element.preservesPitch).toBe(true);
    });

    it("ignores a speed it does not offer, rather than rounding it into range", () => {
        bind();

        usePlayerSpeed().setSpeed(2);
        usePlayerSpeed().setSpeed(7);
        usePlayerSpeed().setSpeed(0);

        expect(usePlayerSpeed().speed.value).toBe(2);
    });

    describe("the skim on top of the setting", () => {
        it("doubles whatever is set, and comes back to it", () => {
            /*
             * The case that made this relative rather than an absolute 2×: at a 3× setting an
             * absolute skim would SLOW the listener down, and releasing would strand them at
             * normal speed instead of the speed they chose.
             */
            const speed = usePlayerSpeed();
            bind();
            speed.setSpeed(3);

            speed.setSkimming(true);
            expect(speed.effectiveRate.value).toBe(6);
            expect(element.playbackRate).toBe(6);

            speed.setSkimming(false);
            expect(speed.effectiveRate.value).toBe(3);
            expect(element.playbackRate).toBe(3);
        });

        it("leaves the SETTING alone while it doubles the rate", () => {
            // Or a hold would silently become the reader's stated preference.
            const speed = usePlayerSpeed();
            bind();
            speed.setSpeed(2);

            speed.setSkimming(true);

            expect(speed.speed.value).toBe(2);
            expect(speed.effectiveRate.value).toBe(4);
        });

        it("follows a speed changed mid-skim", () => {
            const speed = usePlayerSpeed();
            bind();
            speed.setSkimming(true);

            speed.setSpeed(2);

            expect(speed.effectiveRate.value).toBe(4);
            expect(element.playbackRate).toBe(4);
        });

        it("is dropped when a new element is bound, since a remount cannot inherit a held key", () => {
            const speed = usePlayerSpeed();
            bind();
            speed.setSpeed(2);
            speed.setSkimming(true);

            bind();

            expect(speed.isSkimming.value).toBe(false);
            expect(element.playbackRate).toBe(2);
        });
    });

    describe("remembering the choice", () => {
        it("survives a reload", () => {
            bind();
            usePlayerSpeed().setSpeed(2);

            // A fresh page: the module forgets, storage does not.
            resetPlayerSpeedForTests();
            bind();

            expect(usePlayerSpeed().speed.value).toBe(2);
            expect(element.playbackRate).toBe(2);
        });

        it("does NOT remember a skim, which lasts exactly as long as the key is down", () => {
            bind();
            usePlayerSpeed().setSpeed(2);
            usePlayerSpeed().setSkimming(true);

            resetPlayerSpeedForTests();
            bind();

            expect(usePlayerSpeed().effectiveRate.value).toBe(2);
        });

        it("refuses a stored speed it does not offer, rather than lighting no option at all", () => {
            // A value from a future build, or a hand-edited entry. It is assigned straight to
            // `element.playbackRate`, so adopting it unchecked is how a page arrives at a
            // speed the popover cannot show.
            window.localStorage.setItem("mixtape.speed.v1", JSON.stringify({ speed: 2.5 }));

            bind();

            expect(usePlayerSpeed().speed.value).toBe(1);
        });

        it("starts at normal after a corrupt or half-written entry, rather than throwing at boot", () => {
            window.localStorage.setItem("mixtape.speed.v1", "{not json");

            bind();

            expect(usePlayerSpeed().speed.value).toBe(1);
        });

        it("applies the stored speed BEFORE anything can be heard", () => {
            // On the bind, not on the first play: a fresh element starts at 1 whatever the
            // listener chose last visit, so a later application would let one track begin at
            // the wrong speed.
            window.localStorage.setItem("mixtape.speed.v1", JSON.stringify({ speed: 3 }));

            bind();

            expect(element.playbackRate).toBe(3);
        });
    });
});
