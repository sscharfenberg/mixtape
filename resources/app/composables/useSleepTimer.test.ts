import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
    bindVolumeElement,
    resetPlayerVolumeForTests,
    usePlayerVolume
} from "Composables/usePlayerVolume";
import {
    SLEEP_CHAPTER,
    SLEEP_FADE_MINUTES,
    SLEEP_MINUTES,
    SLEEP_OFF,
    bindSleepStop,
    consumeTrackEndStop,
    noteSleepProgress,
    resetSleepTimerForTests,
    useSleepTimer
} from "Composables/useSleepTimer";

/*
 * The sleep timer, and the two things it must not do.
 *
 * IT MUST NOT WRITE THE LEVEL. The fade is an attenuation applied on the way to the
 * element, so `volume` — which is persisted, and which the slider draws — never moves.
 * Get that wrong and a listener wakes to a player stored at 2% with nothing on screen to
 * explain it, and to the volume HUD having popped up once a second for five minutes.
 * Several tests below are that one claim from different angles.
 *
 * IT MUST NOT COUNT TICKS. Everything is derived from a deadline against `Date.now()`,
 * because both things that drive it are throttled in a hidden tab — which is exactly the
 * case a phone playing with the screen off is in. The clock-jump test is the one that
 * pins it: one tick after twenty minutes of silence has to know twenty minutes passed.
 *
 * Fake timers throughout, and they carry the system clock with them, so
 * `advanceTimersByTime` moves the deadline and runs the interval in one gesture.
 *
 * What is NOT here: that the fade sounds gradual. The curve is squared so the fade spends
 * itself evenly in decibels rather than in amplitude, which is a claim about ears — what
 * can be asserted is that it starts at full, ends at nothing, and is monotonic.
 */

/** The element the level is applied to, standing in for the one PlayerBar renders. */
let element: HTMLAudioElement;

/** Bind a fresh element to the volume module, as usePlayerAudio's `attach` does. */
const bind = (): void => {
    element = document.createElement("audio");
    bindVolumeElement(element);
};

describe("useSleepTimer", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        window.localStorage.clear();
        resetSleepTimerForTests();
        resetPlayerVolumeForTests();
        bind();
    });

    afterEach(() => {
        resetSleepTimerForTests();
        bindVolumeElement(null);
        vi.useRealTimers();
    });

    it("starts with nothing armed", () => {
        const timer = useSleepTimer();

        expect(timer.isArmed.value).toBe(false);
        expect(timer.selection.value).toBe(SLEEP_OFF);
        expect(timer.remaining.value).toBe(0);
        expect(timer.isFading.value).toBe(false);
    });

    it("offers the durations the settings row is built from", () => {
        // Exported so the control and `arm`'s validation cannot disagree about what exists.
        expect([...SLEEP_MINUTES]).toStrictEqual([15, 30, 60]);
    });

    it("arms a duration, and says how long is left before the first tick", () => {
        // Published on arming rather than on the first tick, so the row reads "30:00" the
        // moment it is chosen instead of sitting at zero for a second.
        const timer = useSleepTimer();

        timer.arm("30");

        expect(timer.isArmed.value).toBe(true);
        expect(timer.selection.value).toBe("30");
        expect(timer.remaining.value).toBe(1800);
    });

    it("counts down, and stops the player when it runs out", () => {
        const stop = vi.fn();
        bindSleepStop(stop);
        const timer = useSleepTimer();

        timer.arm("15");
        vi.advanceTimersByTime(10 * 60 * 1000);

        expect(timer.remaining.value).toBeCloseTo(300, 0);
        expect(stop).not.toHaveBeenCalled();

        vi.advanceTimersByTime(5 * 60 * 1000);

        expect(stop).toHaveBeenCalledTimes(1);
        expect(timer.isArmed.value).toBe(false);
    });

    it("reads the clock rather than counting ticks, so a throttled tab still expires on time", () => {
        /*
         * THE TEST THIS MODULE EXISTS FOR. A hidden tab throttles `setInterval` to roughly
         * once a minute and `timeupdate` to a trickle, so a timer that accumulated ticks
         * would run a 15-minute countdown for an hour — on a phone with the screen off,
         * which is the only way this feature is ever used. One tick, twenty minutes later.
         */
        const stop = vi.fn();
        bindSleepStop(stop);
        const timer = useSleepTimer();

        timer.arm("15");
        vi.setSystemTime(Date.now() + 20 * 60 * 1000);
        noteSleepProgress();

        expect(stop).toHaveBeenCalledTimes(1);
        expect(timer.isArmed.value).toBe(false);
    });

    it("fades only inside the last few minutes, and never touches the stored level", () => {
        /*
         * The attenuation is a separate factor for exactly this reason: `setVolume` persists
         * and bumps `changes`, so a fade written as a level would store its way to silence
         * and flash the volume HUD over the page on every tick.
         */
        const { volume, changes } = usePlayerVolume();
        const timer = useSleepTimer();

        timer.arm("30");
        vi.advanceTimersByTime(20 * 60 * 1000);

        expect(timer.isFading.value).toBe(false);
        expect(element.volume).toBe(1);

        // Halfway through the fade: down about 12 dB, which is audibly quieter without being
        // gone — and the level itself has not moved a fraction.
        vi.advanceTimersByTime((5 + SLEEP_FADE_MINUTES / 2) * 60 * 1000);

        expect(timer.isFading.value).toBe(true);
        expect(element.volume).toBeCloseTo(0.25, 2);
        expect(volume.value).toBe(1);
        expect(changes.value).toBe(0);
    });

    it("fades from the listener's own level rather than from full", () => {
        // The factor multiplies what the slider says, so a player at 40% fades from 40%.
        const { setVolume } = usePlayerVolume();
        const timer = useSleepTimer();

        setVolume(0.4);
        timer.arm("15");
        vi.advanceTimersByTime((10 + SLEEP_FADE_MINUTES / 2) * 60 * 1000);

        expect(element.volume).toBeCloseTo(0.4 * 0.25, 2);
    });

    it("gives the level back when the timer is cancelled mid-fade", () => {
        // The "no, I'm still awake" press. Anything less than the full level here is the
        // listener left quieter than they were for reasons they cannot see.
        const timer = useSleepTimer();

        timer.arm("15");
        vi.advanceTimersByTime(13 * 60 * 1000);
        expect(element.volume).toBeLessThan(1);

        timer.cancel();

        expect(element.volume).toBe(1);
        expect(timer.isArmed.value).toBe(false);
        expect(timer.selection.value).toBe(SLEEP_OFF);
    });

    it("gives the level back when it expires, after stopping and not before", () => {
        /*
         * ORDER MATTERS, and it is the one thing a screenshot could never show: restoring
         * first would play the last instant of a five-minute fade at full volume — the exact
         * sound the whole feature exists to prevent.
         */
        const heardAtStop: number[] = [];
        bindSleepStop(() => heardAtStop.push(element.volume));
        const timer = useSleepTimer();

        timer.arm("15");
        vi.advanceTimersByTime(15 * 60 * 1000);

        expect(heardAtStop).toStrictEqual([expect.closeTo(0, 2)]);
        expect(element.volume).toBe(1);
    });

    it("cancels from the row's own off option", () => {
        const timer = useSleepTimer();

        timer.arm("30");
        timer.arm(SLEEP_OFF);

        expect(timer.isArmed.value).toBe(false);
        expect(timer.remaining.value).toBe(0);
    });

    it("re-arming restarts the countdown rather than adding to it", () => {
        const timer = useSleepTimer();

        timer.arm("15");
        vi.advanceTimersByTime(10 * 60 * 1000);
        timer.arm("15");

        expect(timer.remaining.value).toBe(900);
    });

    it("ignores a value that is not on offer, rather than coercing it", () => {
        // The only callers are a radiogroup built from SLEEP_MINUTES and this module's own
        // constants, so anything else is a bug to fail safe on — not a number to round.
        const timer = useSleepTimer();

        timer.arm("45");

        expect(timer.isArmed.value).toBe(false);
    });

    describe("the end-of-chapter mode", () => {
        it("waits for a boundary instead of a clock, and never fades", () => {
            /*
             * No fade on purpose: a chapter can be three minutes long, so "the last five" would
             * begin before the chapter did. A boundary is already the gentlest possible stop.
             */
            const timer = useSleepTimer();

            timer.arm(SLEEP_CHAPTER);
            vi.advanceTimersByTime(60 * 60 * 1000);

            expect(timer.isArmed.value).toBe(true);
            expect(timer.selection.value).toBe(SLEEP_CHAPTER);
            expect(timer.remaining.value).toBe(0);
            expect(timer.isFading.value).toBe(false);
            expect(element.volume).toBe(1);
        });

        it("answers the track boundary once, then disarms", () => {
            // Consumed rather than read: a flag left set would stop the queue again at the
            // next boundary, which on a 673-chapter book is a player that never advances.
            const timer = useSleepTimer();

            timer.arm(SLEEP_CHAPTER);

            expect(consumeTrackEndStop()).toBe(true);
            expect(consumeTrackEndStop()).toBe(false);
            expect(timer.isArmed.value).toBe(false);
        });

        it("says no when nothing is armed, so an ordinary track boundary advances", () => {
            expect(consumeTrackEndStop()).toBe(false);
        });

        it("does not answer the boundary while a DURATION is running", () => {
            // The two modes are separate: a 30-minute timer must not stop the player early
            // just because a track happened to end.
            const timer = useSleepTimer();

            timer.arm("30");

            expect(consumeTrackEndStop()).toBe(false);
            expect(timer.isArmed.value).toBe(true);
        });
    });

    it("cancels when the player lets go of the element", () => {
        // Which is what happens when the queue is emptied and the bar unmounts with it: the
        // mark that showed the timer has gone, so a timer still counting would be invisible
        // state with an interval behind it.
        const timer = useSleepTimer();

        bindSleepStop(vi.fn());
        timer.arm("30");
        bindSleepStop(null);

        expect(timer.isArmed.value).toBe(false);
        expect(vi.getTimerCount()).toBe(0);
    });

    it("stops ticking once it has expired", () => {
        // The interval is cleared by disarming, so an expired timer leaves nothing running
        // for the rest of the tab's life.
        bindSleepStop(vi.fn());
        useSleepTimer().arm("15");

        vi.advanceTimersByTime(15 * 60 * 1000);

        expect(vi.getTimerCount()).toBe(0);
    });
});
