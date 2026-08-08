/******************************************************************************
 * usePlayEvents
 * A signal that the server has just recorded a listen — the one thing the play
 * beacon knows that the rest of the app wants to hear about.
 *
 * WHY IT EXISTS. `plays` are written by a fire-and-forget POST from the player
 * (Utils/playBeacon), while the counts a page shows arrive as Inertia props
 * rendered before that POST was made. So a track finishing on the very page that
 * displays its play count left the figure a request behind — the number only moved
 * on the next full navigation, which reads as the feature being broken.
 *
 * A MODULE SINGLETON, the same pattern as useToast and usePlayerQueue and for the
 * same reason: the writer (a plain .ts module, no component) and the readers (any
 * page showing a count) sit nowhere near each other in the tree, and this app has no
 * Pinia.
 *
 * A COUNTER, NOT AN EVENT BUS. What a reader needs is "something changed, ask the
 * server again", and a monotonic number gives exactly that with a plain `watch` —
 * no subscribe/unsubscribe, so a page that navigates away leaves nothing behind.
 * The track's id rides along for anyone who ever needs it, but deliberately nobody
 * filters on it today: an artist, genre or album page counts every listen to any of
 * ITS tracks, and the browser has no idea which artist or genre the played track
 * belonged to — the queue holds titles, not taxonomy. Guessing relevance here would
 * silently drop real updates; asking the server is definitionally right, and costs
 * one small request per finished track.
 *****************************************************************************/
import type { Ref } from "vue";
import { readonly, ref } from "vue";

/** What {@link usePlayEvents} exposes. */
export type UsePlayEventsReturn = {
    /** Ticks once per listen the SERVER accepted. Watch it; never write it. */
    playsRecorded: Readonly<Ref<number>>;
    /** The track behind the most recent tick, or null before the first one. */
    lastPlayedTrackId: Readonly<Ref<string | null>>;
    /** Called by the beacon once the server has answered. Not for components. */
    notifyPlayRecorded: (trackId: string) => void;
};

// Module-level state — see the banner. One counter, shared by every reader.
const playsRecorded = ref(0);
const lastPlayedTrackId = ref<string | null>(null);

/**
 * Record that the server accepted a listen, waking every watcher.
 *
 * Called ONLY on a successful response, never on the attempt: a refused or offline
 * beacon has changed nothing, and a tick for it would send every page showing a
 * count off to re-fetch the same number — which looks exactly like a count that
 * refuses to move.
 */
export function notifyPlayRecorded(trackId: string): void {
    lastPlayedTrackId.value = trackId;
    playsRecorded.value += 1;
}

/**
 * Reset the singleton between specs.
 *
 * Module state outlives a test file's mounts, so without this a counter left ticking
 * by one spec fires a watcher in the next — the trap usePlayerQueue's own reset
 * helper documents.
 */
export function resetPlayEventsForTests(): void {
    playsRecorded.value = 0;
    lastPlayedTrackId.value = null;
}

/**
 * Read the play signal.
 *
 * The refs come back `readonly` because there is exactly one legitimate writer (the
 * beacon, through `notifyPlayRecorded`); a component that could increment the counter
 * would be able to fake a listen the server never stored.
 */
export function usePlayEvents(): UsePlayEventsReturn {
    return {
        playsRecorded: readonly(playsRecorded),
        lastPlayedTrackId: readonly(lastPlayedTrackId),
        notifyPlayRecorded
    };
}
