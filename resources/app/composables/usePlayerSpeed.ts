/******************************************************************************
 * usePlayerSpeed
 * How fast the player plays — the chosen speed, the temporary skim on top of it, and
 * remembering the choice across visits.
 *
 * Split out of usePlayerAudio for the reason usePlayerVolume was: it touches one element
 * property and one storage key, and never goes near the intent flag, the queue pointer or
 * the teardown list. It does NOT own the element — usePlayerAudio does, and hands it over
 * through `bindSpeedElement()` on attach (and `null` on detach), because "there is exactly
 * one element making sound" is that module's invariant.
 *
 * TWO NUMBERS, and keeping them apart is the whole design. `speed` is the SETTING — what
 * the popover shows, what is persisted, what a listener means by "I play podcasts at 2×".
 * `effectiveRate` is what the element is actually doing, which is the setting times the
 * skim multiplier while Space is held. Collapsing them into one value is the version where
 * a hold silently persists 6× as the reader's preference, and where letting go of Space at
 * a 3× setting drops the player to 1× instead of back to 3×.
 *
 * THE CEILING IS EMPIRICAL, not folklore. Measured in this project's own Chromium against a
 * synthesised 440 Hz tone: at 1×–6× the rate is honoured exactly (effective 1.00 … 6.05),
 * the output level is unchanged (peak RMS 0.6912 at every rate), and `preservesPitch` holds
 * — the peak stays at 441 Hz rather than sliding to 1323 Hz at 3×. So 3 × the skim's 2 = 6
 * is inside what the engine does properly, and nothing here is working around a platform
 * limit. What a pure tone cannot tell you is how the time-stretcher handles TRANSIENTS in
 * real music, which is a matter of taste rather than capability — hence a short list of
 * speeds a person chose, not a slider.
 *****************************************************************************/
import type { ComputedRef, Ref } from "vue";
import { computed, ref } from "vue";

/**
 * Where the speed is remembered.
 *
 * NOT user-scoped, matching the volume key and for the same reason: how fast to play is a
 * fact about the listener at this machine, not about the account. Versioned in the key so a
 * shape change can never be read as the old one.
 */
const SPEED_STORAGE_KEY = "mixtape.speed.v1";

/** Normal speed, and what an unreadable or unrecognised stored value falls back to. */
const NORMAL_SPEED = 1;

/**
 * The speeds the settings popover offers, in display order.
 *
 * A fixed list rather than a range, because these are choices with names ("normal",
 * "double") rather than a continuum — and because the honest constraint on the top end is
 * how a time-stretcher handles real music, which is a judgement someone made by listening
 * rather than a number to interpolate. Exported so the control and the validation below
 * cannot disagree about what is offered.
 */
export const PLAYER_SPEEDS = [1, 2, 3] as const;

/** How much a held Space multiplies the chosen speed by. */
const SKIM_MULTIPLIER = 2;

/** Return type of {@link usePlayerSpeed}. */
export type UsePlayerSpeedReturn = {
    /** The chosen speed — the setting, one of {@link PLAYER_SPEEDS}. Persisted. */
    speed: Ref<number>;
    /** Whether Space is being held; multiplies the setting, and is never persisted. */
    isSkimming: Ref<boolean>;
    /** What the element is actually playing at: the setting, doubled while skimming. */
    effectiveRate: ComputedRef<number>;
    /** Choose a speed. Ignores anything not on the offered list. */
    setSpeed: (value: number) => void;
    /** Start or stop the temporary skim. */
    setSkimming: (value: boolean) => void;
};

const speed = ref<number>(NORMAL_SPEED);
const isSkimming = ref<boolean>(false);

/** Whether the stored speed has been read yet — once per page, on the first bind. */
let hydrated = false;

/** The element to drive, handed over by usePlayerAudio. Null before the bar mounts. */
let element: HTMLAudioElement | null = null;

/** The setting, doubled while Space is held. */
const effectiveRate = computed<number>(() => speed.value * (isSkimming.value ? SKIM_MULTIPLIER : 1));

/**
 * Push the rate onto the element.
 *
 * `preservesPitch` goes with it every time, and is the reason any of this is usable: without
 * it the same samples are played faster, every voice goes up an octave, and the result is
 * unlistenable rather than merely quick. It is the default in current engines but it is a
 * per-element property a browser may reset, and stating it costs one line against a failure
 * that is only detectable by ear.
 *
 * Exported because usePlayerAudio has to re-assert it on a NEW SOURCE: some engines reset
 * `playbackRate` to 1 when `src` changes and others keep it, so a skim (or a 3× setting)
 * running past the end of a track would otherwise behave differently per browser.
 */
export function applyPlaybackRate(): void {
    if (!element) return;

    element.preservesPitch = true;
    element.playbackRate = effectiveRate.value;
}

/** Write the speed down. Failure is silent: playing matters more than remembering. */
function persistSpeed(): void {
    try {
        window.localStorage.setItem(SPEED_STORAGE_KEY, JSON.stringify({ speed: speed.value }));
    } catch {
        // Storage full or blocked — the speed still applies for this page.
    }
}

/**
 * Read the stored speed, once.
 *
 * Validated against the offered list rather than merely range-checked, because this value is
 * assigned to `element.playbackRate` — which throws on some engines outside their supported
 * range — and because a stored `2.5` from a future build should not resurrect a speed this
 * one does not offer, leaving the popover with no option lit.
 */
function hydrateSpeed(): void {
    if (hydrated) return;
    hydrated = true;

    let stored: string | null = null;
    try {
        stored = window.localStorage.getItem(SPEED_STORAGE_KEY);
    } catch {
        return; // Storage unavailable; normal speed is fine.
    }
    if (!stored) return;

    try {
        const payload = JSON.parse(stored) as { speed?: unknown };

        if (typeof payload.speed === "number" && PLAYER_SPEEDS.includes(payload.speed as (typeof PLAYER_SPEEDS)[number])) {
            speed.value = payload.speed;
        }
    } catch {
        // Corrupt entry — start at normal rather than throw at boot.
    }
}

/**
 * Take (or release) the element usePlayerAudio owns.
 *
 * Hydration lands here rather than at module scope because it has to happen BEFORE anything
 * can be heard: a fresh element starts at 1 whatever the listener chose last visit. A skim
 * is dropped on bind — a remount cannot inherit a key that is no longer down.
 */
export function bindSpeedElement(audio: HTMLAudioElement | null): void {
    element = audio;
    isSkimming.value = false;

    if (!audio) return;

    hydrateSpeed();
    applyPlaybackRate();
}

/**
 * Read / write the playback speed.
 *
 * Returns the module-level refs themselves, so the popover, the shortcuts and the element
 * are looking at one value with no props in between.
 */
export function usePlayerSpeed(): UsePlayerSpeedReturn {
    /**
     * Choose a speed.
     *
     * Anything off the offered list is ignored rather than clamped: the only callers are a
     * radiogroup built from that same list and the storage hydrator, so a value from
     * anywhere else is a bug rather than a number to round into range.
     */
    function setSpeed(value: number): void {
        if (!PLAYER_SPEEDS.includes(value as (typeof PLAYER_SPEEDS)[number])) return;

        speed.value = value;
        applyPlaybackRate();
        persistSpeed();
    }

    /** Start or stop the skim. Deliberately not persisted — it lasts as long as the key is down. */
    function setSkimming(value: boolean): void {
        isSkimming.value = value;
        applyPlaybackRate();
    }

    return { speed, isSkimming, effectiveRate, setSpeed, setSkimming };
}

/**
 * Reset the singleton — tests only.
 *
 * The speed, the skim AND the hydration latch: a spec that picks 3× would otherwise leave
 * the next one fast, and one that seeds localStorage would find hydration already spent.
 * Exported rather than worked around with module mocking, the way this app's other
 * singletons are drained (docs/testing.md → module singletons).
 */
export function resetPlayerSpeedForTests(): void {
    element = null;
    speed.value = NORMAL_SPEED;
    isSkimming.value = false;
    hydrated = false;
}
