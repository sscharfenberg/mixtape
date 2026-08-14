/******************************************************************************
 * usePlayerVolume
 * How loud the player is, and whether it is muted — the level itself, the two ways
 * of silencing it, and remembering the choice across visits.
 *
 * Module-level state, the same no-Pinia singleton pattern as usePlayerQueue and
 * usePlayerAudio: the control in the bar and the element making sound have to be
 * looking at one level.
 *
 * SPLIT OUT OF usePlayerAudio because it shares nothing with playback. It touches
 * exactly two element properties and one storage key, and never interacts with the
 * intent flag, the queue pointer or the teardown list — so it was the one cluster in
 * that file that could leave without spreading an invariant across two modules.
 *
 * IT DOES NOT OWN THE ELEMENT. usePlayerAudio does, and hands it over through
 * `bindVolumeElement()` on attach (and `null` on detach). One owner, because "there
 * must be exactly one element making sound" is that module's invariant and adding a
 * second claim on it would be the beginning of two.
 *
 * A LEVEL OF ZERO AND A MUTE ARE SEPARATE STATES that look identical. They stay
 * separate because they have to be separately undoable — muting at 60% must come
 * back to 60%, and dragging to zero then un-muting has to arrive somewhere audible —
 * and `isSilent` is what collapses them for anything that only cares whether
 * something can be heard.
 *****************************************************************************/
import type { ComputedRef, Ref } from "vue";
import { computed, ref } from "vue";

/**
 * Where the level is remembered.
 *
 * NOT user-scoped, unlike the queue's payload. How loud this machine's speakers want
 * to be is a fact about the machine and whoever is sitting at it, so two people
 * sharing a browser inheriting one level is right where inheriting one queue is not.
 */
const VOLUME_STORAGE_KEY = "mixtape.volume.v1";

/**
 * The level a fresh browser gives an <audio>: unity gain, nothing attenuated.
 *
 * Kept as the default deliberately, and it is why this app sounds louder than
 * YouTube for the same song. YouTube normalises loudness — it measures each upload
 * and turns DOWN anything mastered hotter than roughly -14 LUFS, which most modern
 * music is by a wide margin. Playing a file untouched is therefore not "too loud",
 * it is unattenuated, and the platform people compare it to is the one being quiet.
 * Lowering this number would only mean everything starts quiet and gets turned up;
 * the real fix is per-track normalisation (ReplayGain tags, which getID3 can already
 * read during the library scan) applied as a gain per track. Not built.
 */
const FULL_VOLUME = 1;

/** Return type of {@link usePlayerVolume}. */
export type UsePlayerVolumeReturn = {
    /** Output level, 0–1. `1` is the browser's own default: unity gain, no attenuation. */
    volume: Ref<number>;
    /**
     * How many times somebody has CHANGED the level or the mute, this page life.
     *
     * A counter rather than a flag, so a consumer can watch it: what it distinguishes is a
     * gesture from a value, which the level itself cannot. See {@link changes} below.
     */
    changes: Ref<number>;
    /** Whether output is muted, which is separate from a level of zero. */
    isMuted: Ref<boolean>;
    /** Nothing audible either way — what a control draws its mute glyph from. */
    isSilent: ComputedRef<boolean>;
    /** Set the level, clamped to 0–1; anything above zero also lifts a mute. */
    setVolume: (value: number) => void;
    /** Mute, or come back to the level muting interrupted. */
    toggleMute: () => void;
};

const volume = ref<number>(FULL_VOLUME);
const isMuted = ref<boolean>(false);

/**
 * A tick per deliberate change — turned up, turned down, muted, unmuted.
 *
 * WHY A COUNTER AND NOT THE LEVEL ITSELF: the level moves for two very different reasons,
 * and only one of them is a gesture. `hydrateVolume` restores what was stored, on the first
 * bind, which happens in PlayerBar's `onMounted` — AFTER its children's setup, so anything
 * watching the level is already listening when the restore lands and cannot tell it from
 * somebody turning the knob — which is how the volume readout ends up greeting every page load.
 *
 * Bumped only where a change was ASKED for, and only when it actually changed something,
 * so a key pressed at the ceiling still ticks nothing.
 */
const changes = ref<number>(0);

/**
 * The level to return to when a mute is lifted.
 *
 * Needed because the two ways of silencing the player have to be undoable in the same
 * gesture: muting at 60% must come back to 60%, and un-muting after the slider was
 * dragged to zero has to arrive at something audible or the button does nothing
 * visible and reads as broken.
 */
let levelBeforeMute = FULL_VOLUME;

/** Whether the stored level has been read yet — once per page, on the first bind. */
let hydrated = false;

/** The element to attenuate, handed over by usePlayerAudio. Null before the bar mounts. */
let element: HTMLAudioElement | null = null;

/** Nothing audible: muted, or turned all the way down. One glyph covers both. */
const isSilent = computed<boolean>(() => isMuted.value || volume.value === 0);

/**
 * Push the level onto the element.
 *
 * `volume` and `muted` are properties of the ELEMENT, not of its source, so they
 * survive every `src` change and this needs calling only when one of them changes or
 * a new element is bound — not per track.
 */
function applyVolume(): void {
    if (!element) return;

    element.volume = volume.value;
    element.muted = isMuted.value;
}

/** Write the level down. Failure is silent: a working player matters more than a remembered level. */
function persistVolume(): void {
    try {
        window.localStorage.setItem(VOLUME_STORAGE_KEY, JSON.stringify({ volume: volume.value, muted: isMuted.value }));
    } catch {
        // Storage full or blocked — the level still applies for this page.
    }
}

/**
 * Read the stored level, once.
 *
 * Every field is re-validated rather than trusted: this value is assigned straight to
 * `element.volume`, which THROWS on anything outside 0–1, so a hand-edited or
 * half-written entry would otherwise break playback at bind time rather than
 * degrade. A number that fails the check is simply not adopted.
 */
function hydrateVolume(): void {
    if (hydrated) return;
    hydrated = true;

    let stored: string | null = null;
    try {
        stored = window.localStorage.getItem(VOLUME_STORAGE_KEY);
    } catch {
        return; // Storage unavailable; the default level is fine.
    }
    if (!stored) return;

    try {
        const payload = JSON.parse(stored) as { volume?: unknown; muted?: unknown };

        if (typeof payload.volume === "number" && Number.isFinite(payload.volume)) {
            volume.value = Math.min(Math.max(payload.volume, 0), 1);
            if (volume.value > 0) levelBeforeMute = volume.value;
        }
        if (typeof payload.muted === "boolean") isMuted.value = payload.muted;
    } catch {
        // Corrupt entry — start at full rather than throw at boot.
    }
}

/**
 * Take (or release) the element usePlayerAudio owns.
 *
 * Hydration happens here rather than at module scope because it must land BEFORE
 * anything can be heard: a fresh element starts at unity regardless of what the
 * listener chose last visit. Passing `null` on detach stops this module holding a
 * reference to a node that has left the document.
 */
export function bindVolumeElement(audio: HTMLAudioElement | null): void {
    element = audio;

    if (!audio) return;

    hydrateVolume();
    applyVolume();
}

/**
 * Read / write the output level.
 *
 * Returns the module-level refs themselves, so the control in the bar and the element
 * are looking at the same value with no props in between.
 */
export function usePlayerVolume(): UsePlayerVolumeReturn {
    /**
     * Set the output level.
     *
     * Dragging up LIFTS A MUTE, deliberately: the slider is the more specific gesture
     * of the two, and a slider that visibly moves while the player stays silent is the
     * kind of control people press twice and then distrust. Zero is left as a level in
     * its own right rather than turned into a mute — `isSilent` is what collapses the
     * distinction for the glyph, so the two states stay separately undoable.
     */
    function setVolume(value: number): void {
        const before = { level: volume.value, muted: isMuted.value };

        volume.value = Math.min(Math.max(value, 0), 1);

        if (volume.value > 0) {
            levelBeforeMute = volume.value;
            isMuted.value = false;
        }

        // Only a real move counts as a change: ↑ at full volume clamps to the level it
        // already had, and nothing happened worth showing anyone.
        if (volume.value !== before.level || isMuted.value !== before.muted) changes.value += 1;

        applyVolume();
        persistVolume();
    }

    /**
     * Mute, or come back from it.
     *
     * Un-muting from a level of zero lands on the remembered level instead, because
     * `muted = false` over `volume = 0` is still silence — the press would appear to
     * do nothing at all.
     */
    function toggleMute(): void {
        if (isMuted.value) {
            isMuted.value = false;
            if (volume.value === 0) volume.value = levelBeforeMute > 0 ? levelBeforeMute : FULL_VOLUME;
        } else {
            if (volume.value > 0) levelBeforeMute = volume.value;
            isMuted.value = true;
        }

        // Always a change: a mute toggled is a mute toggled.
        changes.value += 1;

        applyVolume();
        persistVolume();
    }

    return { volume, changes, isMuted, isSilent, setVolume, toggleMute };
}

/**
 * Reset the singleton — tests only.
 *
 * The level, the remembered pre-mute level AND the hydration latch: a spec that turns
 * the volume down would otherwise leave the next one starting quiet, and one that
 * seeds localStorage would find hydration already spent. Exported rather than worked
 * around with module mocking, the same way the app's other singletons are drained
 * (see docs/testing.md → module singletons).
 */
export function resetPlayerVolumeForTests(): void {
    element = null;
    volume.value = FULL_VOLUME;
    isMuted.value = false;
    changes.value = 0;
    levelBeforeMute = FULL_VOLUME;
    hydrated = false;
}
