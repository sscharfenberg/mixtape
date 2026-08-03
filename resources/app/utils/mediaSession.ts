/******************************************************************************
 * mediaSession
 * Everything this app says to the OS about what is playing: the lock screen, the
 * notification shade, a keyboard's media keys, a car head unit.
 *
 * Plain browser API, no dependency — and the reason the player needs no library at
 * all for background playback.
 *
 * A MODULE OF FUNCTIONS, not a composable: it holds no state and owns nothing
 * reactive. It is one-way output plus one handler-binding call, and it was pulled
 * out of usePlayerAudio because that file was interleaving "wire up the element"
 * with "tell the OS about it" inside a single `attach()`. Those read as one
 * procedure and are two: nothing here touches the element, and none of the guards
 * below are about playback — they are about this API's own gaps.
 *
 * EVERY CALL IS GUARDED, and each guard has its own reason:
 *
 *   - `"mediaSession" in navigator` — absent on desktop Firefox and older WebViews,
 *     where a missing lock-screen title is the only cost.
 *   - `setPositionState` is feature-detected separately, because a browser can have
 *     Media Session without it.
 *   - `setActionHandler` is wrapped per action: a browser that does not know one
 *     action throws on it, and the rest must still be wired.
 *
 * Callers pass values in rather than this module reading them, so it can be tested
 * against a stub navigator with no player in sight.
 *****************************************************************************/
import type { QueueTrack } from "Composables/usePlayerQueue";

/**
 * What the OS transport controls should do. Supplied by the player, so this module
 * needs to know nothing about queues or elements.
 */
export type MediaSessionHandlers = {
    /** The lock screen's play button. */
    play: () => void;
    /** Its pause button — and its stop button, which this app treats the same way. */
    pause: () => void;
    /** Skip back a track. */
    previous: () => void;
    /** Skip forward a track. */
    next: () => void;
    /** Nudge the cursor by a signed number of seconds. */
    seekBy: (seconds: number) => void;
    /** Jump to an absolute position, when the OS offers a scrubber. */
    seekTo: (seconds: number) => void;
};

/**
 * How far the OS's seek-backward / seek-forward buttons move.
 *
 * Lives here rather than with the player because it is a property of THESE controls:
 * a lock screen offers two fixed nudges, not a scrub. The event can carry its own
 * `seekOffset` and this deliberately ignores it, so the step is the same wherever it
 * is pressed from.
 */
const SEEK_STEP_SECONDS = 10;

/**
 * Name what is playing, or clear it when nothing is.
 *
 * The null case is not a nicety: leaving stale metadata behind means a lock screen
 * still offering a track the queue no longer holds.
 */
export function publishMetadata(track: QueueTrack | null): void {
    if (!("mediaSession" in navigator)) return;

    if (!track) {
        navigator.mediaSession.metadata = null;

        return;
    }

    navigator.mediaSession.metadata = new MediaMetadata({
        title: track.name,
        artist: track.artist ?? "",
        album: track.album ?? "",
        artwork: track.coverUrl ? [{ src: track.coverUrl, type: "image/jpeg" }] : []
    });
}

/**
 * Publish the cursor and total, so a lock screen can draw its own progress bar and
 * scrub.
 *
 * Wrapped in try/catch on purpose: `setPositionState` throws a TypeError when the
 * position runs past the duration, and the two reach it from different places (the
 * database's measured length, the element's real playhead). A file whose tags
 * disagree with its bytes must cost a missing lock-screen scrubber, not a broken
 * player. The position is clamped for the same reason, before the throw can happen.
 */
export function publishPositionState(duration: number, position: number, playbackRate: number): void {
    if (!("mediaSession" in navigator) || typeof navigator.mediaSession.setPositionState !== "function") return;
    if (!Number.isFinite(duration) || duration <= 0) return;

    try {
        navigator.mediaSession.setPositionState({
            duration,
            position: Math.min(Math.max(position, 0), duration),
            playbackRate
        });
    } catch {
        // Position outside the claimed duration — nothing here depends on it.
    }
}

/** Mirror the play state, so a lock-screen button shows the right glyph. */
export function publishPlaybackState(isPlaying: boolean): void {
    if (!("mediaSession" in navigator)) return;

    navigator.mediaSession.playbackState = isPlaying ? "playing" : "paused";
}

/**
 * Wire the OS transport controls, and return the undo functions for them.
 *
 * These handlers are what make the queue work when the page is not on screen at all,
 * which is the whole point of wiring Media Session.
 *
 * Returns teardown rather than removing handlers itself, so the caller can push them
 * onto the same list it uses for its own listeners — one place that undoes
 * everything, which is how the player guarantees a remount cannot leave two sets of
 * handlers advancing the queue twice.
 */
export function bindActionHandlers(handlers: MediaSessionHandlers): Array<() => void> {
    if (!("mediaSession" in navigator)) return [];

    const undo: Array<() => void> = [];

    const actions: Array<[MediaSessionAction, () => void]> = [
        ["play", handlers.play],
        ["pause", handlers.pause],
        ["stop", handlers.pause],
        ["previoustrack", handlers.previous],
        ["nexttrack", handlers.next],
        ["seekbackward", () => handlers.seekBy(-SEEK_STEP_SECONDS)],
        ["seekforward", () => handlers.seekBy(SEEK_STEP_SECONDS)]
    ];

    for (const [action, handler] of actions) {
        try {
            navigator.mediaSession.setActionHandler(action, handler);
            undo.push(() => navigator.mediaSession.setActionHandler(action, null));
        } catch {
            // An action this browser does not know. The rest still work.
        }
    }

    // `seekto` carries a payload, so it cannot join the list above.
    try {
        navigator.mediaSession.setActionHandler("seekto", details => {
            if (typeof details.seekTime === "number") handlers.seekTo(details.seekTime);
        });
        undo.push(() => navigator.mediaSession.setActionHandler("seekto", null));
    } catch {
        // Not supported here; the lock screen simply offers no scrubber.
    }

    return undo;
}
