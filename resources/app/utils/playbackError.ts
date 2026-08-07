/******************************************************************************
 * playbackError
 * Telling the LISTENER that a track will not play — the second half of the answer
 * a failed stream needs. usePlayerAudio already gives the first: it stops, and the
 * glyph goes back to play. On its own that is indistinguishable from a player
 * somebody paused, so a file that vanished between library scans looked like the
 * app ignoring a click. This says which track failed, and why.
 *
 * A MODULE OF FUNCTIONS rather than a composable, the same shape and for the same
 * reason as Utils/mediaSession: it is one-way output. Nothing here is reactive,
 * nothing here touches the element — the caller hands over the element's MediaError
 * and the track it was loading, so this can be tested with no player in sight.
 *
 * TWO DECISIONS LIVE HERE that the audio state machine has no business holding:
 *
 * 1. **What kind of failure it was.** A dropped connection and a file the library
 *    no longer has are different problems for the person reading the toast: one
 *    says try again, the other says the collection needs re-scanning. Two messages,
 *    chosen off `MediaError.code`.
 * 2. **Saying it once.** One failed load reports itself TWICE — the element fires
 *    `error`, and the `play()` promise for the same source rejects a tick later —
 *    and two identical toasts stacked on top of each other is worse than one. The
 *    MediaError's IDENTITY is what tells the two reports apart from two real
 *    failures: a browser mints one object per failure, so the same object is the
 *    same failure, while a fresh load that fails again brings a new one.
 *
 * A REPEAT PRESS IS DELIBERATELY NOT A REPEAT REPORT: `forgetPlaybackFailure()`
 * clears the memory whenever the listener asks for playback again. The element
 * keeps its one MediaError for as long as the dead source is loaded, so without
 * that a second press would be met with silence — the exact thing this file exists
 * to remove.
 *****************************************************************************/
import type { Composer } from "vue-i18n";
import { getI18n } from "@/i18n";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { useToast } from "Composables/useToast";

/**
 * Translate through the i18n singleton, naming the track.
 *
 * The same reach-for-the-singleton as useTwoFactorAuth, and for the same reason: this
 * runs inside a media event handler rather than component setup, where `useI18n()` is
 * not available. The cast bridges vue-i18n's `Composer | VueI18n` union to a callable `t`.
 */
const translate = (key: string, name: string): string => (getI18n().global as unknown as Composer).t(key, { name });

/** `MediaError.MEDIA_ERR_ABORTED` — a fetch that was cancelled, which this app does itself. */
const MEDIA_ERR_ABORTED = 1;

/** `MediaError.MEDIA_ERR_NETWORK` — bytes were arriving and then stopped. */
const MEDIA_ERR_NETWORK = 2;

/**
 * The failure already announced, or `undefined` while none has been.
 *
 * `null` is a VALUE here, not the empty state: an `error` event can arrive with no
 * MediaError on the element at all (older engines, and happy-dom), and that unknown
 * failure still has to be distinguishable from "nothing said yet" — otherwise the pair
 * of reports one load produces would both get through.
 */
let announced: MediaError | null | undefined;

/**
 * Raise a toast naming the track that would not play, unless it has been raised already.
 *
 * Takes the error rather than reading it so the caller stays the only owner of the
 * element (the rule the whole player is built on), and takes the track because the
 * message names it — a bare "playback failed" in a bar that shows no title is not
 * something a listener can act on.
 */
export function announcePlaybackFailure(error: MediaError | null, track: QueueTrack | null): void {
    // Our own doing, not a failure: `load()` re-pointing the element and `stopAndUnload()`
    // letting go of the file both cancel a download in flight, and nobody wants a toast
    // for pressing next.
    if (error?.code === MEDIA_ERR_ABORTED) return;

    // Said already — the `error` event and the rejected `play()` promise are two reports
    // of one failure (see the module note).
    if (announced !== undefined && error === announced) return;

    // Nothing loaded means nothing to name and nothing the listener asked for. The player
    // has still stopped, which is the honest half of the answer.
    if (!track) return;

    announced = error;

    useToast().addToast(
        translate(error?.code === MEDIA_ERR_NETWORK ? "player.error.network" : "player.error.unavailable", track.name),
        "error"
    );
}

/**
 * Forget what was last announced, so the next failure speaks again.
 *
 * Called when the listener presses play — a fresh request deserves a fresh answer — and
 * when the player lets go of its element, since the next one starts with no history.
 * Doubles as the reset a test needs: the memory is module state and outlives one spec.
 */
export function forgetPlaybackFailure(): void {
    announced = undefined;
}
