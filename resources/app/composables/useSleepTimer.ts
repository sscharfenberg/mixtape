/******************************************************************************
 * useSleepTimer
 * Stop the music after a while — the countdown, the fade that precedes it, and the
 * variant that waits for the end of the chapter instead of watching a clock.
 *
 * Module-level state, the same no-Pinia singleton pattern as the rest of the player:
 * the settings row arms it, the bar's pill cancels it and the audio element is what
 * carries the ticks, and all three have to be looking at one timer.
 *
 * TWO MODES, and they behave differently on purpose. A duration counts wall-clock
 * seconds and fades the last few minutes to nothing; `chapter` waits for the track to
 * end and stops there, with NO fade at all — a chapter can be three minutes long, so
 * fading "the last five" would start before it began, and a boundary is already the
 * gentlest possible stop. Only an audiobook offers it (see `QueueTrack.isChapter`),
 * because "stop at the end of this song" is a 90-second timer wearing a costume.
 *
 * IT DOES NOT PAUSE ANYTHING ITSELF. usePlayerAudio owns the element and the play
 * intent, so it hands a stop function over through `bindSleepStop()` — the same
 * handshake the queue uses for the play position, and for the same reason: that module
 * imports this one, so an import back would be a cycle.
 *
 * THE CLOCK IS THE AUTHORITY, NEVER A COUNT OF TICKS. Everything below is derived from
 * a deadline compared against `Date.now()`, because both things that drive it are
 * throttled in a backgrounded tab — `setInterval` to roughly once a minute, and
 * `timeupdate` to a trickle — and a phone with the screen off is precisely the case
 * this feature exists for. Accumulating ticks there would leave a "30 minutes" running
 * for an hour. Throttling now costs granularity (the pill's countdown stutters while
 * hidden, and the fade moves in steps) and nothing else: every reading is the truth at
 * the moment it is taken.
 *
 * TWO TICK SOURCES, because neither covers the case alone. `timeupdate` keeps firing
 * while the tab is hidden and the audio plays, which is when the fade and the stop have
 * to be right; the interval covers a PAUSED player, where no media event fires at all
 * and the countdown would otherwise freeze on screen and expire the instant somebody
 * pressed play again.
 *
 * NOTHING IS PERSISTED, deliberately, unlike the level and the speed beside it. A
 * restored queue comes back paused — playback never starts without a gesture — so a
 * timer that survived a reload would be counting down against silence, and would
 * either expire against nothing or stop the music seconds after it was asked to start.
 * A sleep timer is a fact about tonight, not a preference.
 *****************************************************************************/
import type { ComputedRef, Ref } from "vue";
import { computed, ref } from "vue";
import { setOutputAttenuation } from "Composables/usePlayerVolume";

/**
 * The durations the settings row offers, in minutes.
 *
 * Exported so the control and `arm()`'s validation cannot disagree about what exists —
 * the same rule PLAYER_SPEEDS follows. Nothing below a quarter of an hour: the fade
 * takes five minutes, and an option where a third of the timer is already fading is a
 * volume fault rather than a setting.
 */
export const SLEEP_MINUTES = [15, 30, 60] as const;

/** The row's "no timer" value — its resting position, and what cancelling selects. */
export const SLEEP_OFF = "off";

/** The row's end-of-chapter value, offered only while an audiobook chapter is loaded. */
export const SLEEP_CHAPTER = "chapter";

/**
 * How long the fade runs for, in minutes.
 *
 * Five, which is long enough to be inaudible as it starts — the point is that a listener
 * drifting off is never woken by the ending — and short enough that somebody still awake
 * notices in time to press the pill. Exported because the bar ANNOUNCES the number when
 * the fade begins, and a screen reader being told "five minutes" while the timer runs
 * four is the kind of disagreement only a shared constant prevents.
 */
export const SLEEP_FADE_MINUTES = 5;

/** The same window in seconds, which is the unit everything below counts in. */
const FADE_SECONDS = SLEEP_FADE_MINUTES * 60;

/** How often the wall clock is consulted while the player is paused, in milliseconds. */
const TICK_MS = 1000;

/** Return type of {@link useSleepTimer}. */
export type UseSleepTimerReturn = {
    /** Whether a timer is running, in either mode. What the gear's mark is drawn from. */
    isArmed: ComputedRef<boolean>;
    /** The armed option as the settings row's value: {@link SLEEP_OFF}, minutes, or {@link SLEEP_CHAPTER}. */
    selection: ComputedRef<string>;
    /** Seconds left on a duration, or 0 whenever there is no clock to read (off, or chapter mode). */
    remaining: Ref<number>;
    /** Whether the fade has begun — the one state that earns a readout over the page. */
    isFading: ComputedRef<boolean>;
    /** Arm (or re-arm) the timer from a row value. Anything unrecognised cancels. */
    arm: (value: string) => void;
    /** Cancel, restoring the output level a fade had pulled down. */
    cancel: () => void;
};

/** When a duration timer expires, as an epoch millisecond, or null in the other two states. */
let deadline: number | null = null;

/** Whether the timer is waiting for the current track to end rather than for a clock. */
const stopsAtTrackEnd = ref<boolean>(false);

/** The armed duration in minutes, kept so the row can light the option that was chosen. */
const armedMinutes = ref<number>(0);

/** Seconds left, republished on every tick — 0 unless a duration is running. */
const remaining = ref<number>(0);

/** The paused-player tick, or null when nothing is counting. */
let ticker: ReturnType<typeof setInterval> | null = null;

/** The attenuation last written, so a tick that changes nothing writes nothing. */
let lastAttenuation = 1;

/** How to stop playback, handed over by usePlayerAudio. Null before the bar mounts. */
let stopPlayback: (() => void) | null = null;

/**
 * Whether a duration is running.
 *
 * Asked of `armedMinutes` rather than of `deadline`, which is a plain variable: the
 * deadline is read on a tick and never rendered, while this answer is drawn (the gear's
 * mark, the fade) and so has to be reactive.
 */
function deadlineArmed(): boolean {
    return armedMinutes.value > 0;
}

/** Either mode counts as armed; the mark on the gear does not distinguish them. */
const isArmed = computed<boolean>(() => deadlineArmed() || stopsAtTrackEnd.value);

/** The armed option, as the value the settings row's radiogroup holds. */
const selection = computed<string>(() => {
    if (stopsAtTrackEnd.value) return SLEEP_CHAPTER;

    return armedMinutes.value > 0 ? String(armedMinutes.value) : SLEEP_OFF;
});

/** Whether output is being pulled down right now — false in chapter mode, which never fades. */
const isFading = computed<boolean>(() => deadlineArmed() && remaining.value <= FADE_SECONDS);

/**
 * How much to attenuate output with the given number of seconds left.
 *
 * SQUARED rather than linear, because a linear ramp is not a linear fade: loudness
 * tracks amplitude logarithmically, so `volume` walked evenly to zero sounds like
 * nothing happens for four minutes and then falls off a cliff. Squaring the remaining
 * fraction spends the fade evenly in decibels — halfway through it is down about 12 dB,
 * which is audibly quieter without being nearly gone.
 *
 * @param secondsLeft seconds until the stop; anything above the window means no attenuation
 */
function attenuationFor(secondsLeft: number): number {
    if (secondsLeft >= FADE_SECONDS) return 1;

    const progress = Math.max(secondsLeft, 0) / FADE_SECONDS;

    return progress * progress;
}

/**
 * Push an attenuation onto the output, but only when it actually moved.
 *
 * The guard is not thrift: without it every `timeupdate` — four a second, for the whole
 * length of a track — would write `element.volume` while no timer is running at all.
 */
function applyFade(factor: number): void {
    if (Math.abs(factor - lastAttenuation) < 0.001) return;

    lastAttenuation = factor;
    setOutputAttenuation(factor);
}

/** Stop counting and let go of the fade, leaving playback exactly as it is. */
function disarm(): void {
    deadline = null;
    stopsAtTrackEnd.value = false;
    armedMinutes.value = 0;
    remaining.value = 0;

    if (ticker !== null) {
        clearInterval(ticker);
        ticker = null;
    }

    applyFade(1);
}

/**
 * The deadline arrived: stop the music, then disarm.
 *
 * IN THAT ORDER, because disarming restores the output level — do it first and the last
 * instant of a five-minute fade is played back at full volume, which is the one sound
 * this feature exists to prevent. Pausing rather than clearing the queue: the bar, the
 * queue and the position all stay, so the morning after is one press away.
 */
function expire(): void {
    stopPlayback?.();
    disarm();
}

/**
 * Read the clock: republish what is left, move the fade, and stop when it runs out.
 *
 * Exported because usePlayerAudio calls it from its own `timeupdate` handler rather than
 * this module adding a second listener to the element — one owner for the element, and
 * one place where the events it fires are wired up.
 */
export function noteSleepProgress(): void {
    if (deadline === null) return;

    const secondsLeft = (deadline - Date.now()) / 1000;
    remaining.value = Math.max(secondsLeft, 0);

    if (secondsLeft <= 0) {
        expire();

        return;
    }

    applyFade(attenuationFor(secondsLeft));
}

/**
 * Whether the track that just ended should stop the player rather than advance it — and
 * if so, disarm on the way out.
 *
 * Consumed rather than merely read, so the answer cannot be true twice: `handleEnded`
 * asks exactly once per track boundary, and a flag left set would stop the queue again
 * the next time anything finished.
 */
export function consumeTrackEndStop(): boolean {
    if (!stopsAtTrackEnd.value) return false;

    disarm();

    return true;
}

/**
 * Take (or release) the means of stopping playback, handed over by usePlayerAudio.
 *
 * Releasing it CANCELS a running timer, because the only caller passes null when the
 * element goes away — the queue was emptied and the bar unmounted with it. A timer with
 * nothing left to stop is invisible state (its mark lives on the bar that just left),
 * and its interval would keep running for as long as the tab is open.
 */
export function bindSleepStop(stop: (() => void) | null): void {
    stopPlayback = stop;

    if (!stop) disarm();
}

/**
 * Arm, re-arm, or cancel the sleep timer.
 *
 * Returns the module-level refs themselves, so the settings row, the bar's pill and the
 * player are looking at one timer with no props in between.
 */
export function useSleepTimer(): UseSleepTimerReturn {
    /**
     * Arm the timer from a row value: a number of minutes, {@link SLEEP_CHAPTER}, or
     * {@link SLEEP_OFF}.
     *
     * ARMING IS A RESTART, including with the value already chosen — "give me the full
     * thirty again" is the same gesture as choosing thirty. (A native radiogroup will not
     * usually re-report the option already checked, so re-tapping the lit bubble is not a
     * reliable way to reach that; picking a different one is.)
     *
     * Anything unrecognised cancels rather than throwing: the callers are a radiogroup
     * built from `SLEEP_MINUTES` and this module's own constants, so a stray value is a
     * bug to fail safe on rather than a number to coerce.
     */
    function arm(value: string): void {
        disarm();

        if (value === SLEEP_OFF) return;

        if (value === SLEEP_CHAPTER) {
            stopsAtTrackEnd.value = true;

            return;
        }

        const minutes = Number(value);
        if (!SLEEP_MINUTES.includes(minutes as (typeof SLEEP_MINUTES)[number])) return;

        armedMinutes.value = minutes;
        deadline = Date.now() + minutes * 60 * 1000;
        // Published before the first tick, so the row reads "29:59" the moment it is armed
        // rather than sitting at zero until a second has passed.
        remaining.value = minutes * 60;
        // The paused-player tick. `timeupdate` covers a PLAYING one far better than this can
        // (it survives a hidden tab), and both read the same clock, so the two overlapping
        // costs nothing.
        ticker = setInterval(noteSleepProgress, TICK_MS);
    }

    /** Cancel — what the pill does, and what the row's "off" option selects. */
    function cancel(): void {
        disarm();
    }

    return { isArmed, selection, remaining, isFading, arm, cancel };
}

/**
 * Reset the singleton — tests only.
 *
 * Including the interval, which outlives a spec loudly: a timer left armed keeps ticking
 * into the next test file and fails it somewhere unrelated. Exported rather than worked
 * around with module mocking, the way this app's other singletons are drained (see
 * docs/testing.md → module singletons).
 */
export function resetSleepTimerForTests(): void {
    disarm();
    stopPlayback = null;
    lastAttenuation = 1;
}
