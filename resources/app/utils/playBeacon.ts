/******************************************************************************
 * playBeacon
 * Telling the server that a track was listened to — one fire-and-forget POST per
 * play, and nothing else.
 *
 * A MODULE OF FUNCTIONS beside mediaSession and playbackError, and for the same
 * reason all three are: one-way output with no state of its own. WHEN a play has
 * happened is the player's business — it is the only thing that knows how much of
 * the track was actually heard — and this knows only how to say so.
 *
 * IT COUNTS FOR NOBODY WHEN NOBODY IS SIGNED IN. `plays.user_id` is not nullable:
 * a listening history belongs to a person, and a guest on a share link has no
 * account to hang one on. The check is here rather than at the call site so the
 * player never has to think about who is listening.
 *
 * FAILURE IS SILENT, like every other beacon in the player. A lost play is a
 * missing row in a ranking; a toast about it would be noise about something the
 * listener can neither act on nor care about.
 *****************************************************************************/
import { usePage } from "@inertiajs/vue3";

/** Where a listen is recorded (PlayController). */
const PLAYS_URL = "/player/plays";

/**
 * Record that this track was played.
 *
 * Fired the moment the threshold is crossed, mid-track, rather than saved for the end:
 * the listener is still there, the tab is alive, and a play already earned should not
 * depend on how the track finishes. That also means `keepalive` buys nothing here — unlike
 * the queue's own sync, which is regularly written by a page on its way out.
 */
export function reportPlay(trackId: string): void {
    if (!usePage().props.auth?.user) return;

    try {
        void fetch(PLAYS_URL, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                // Inertia's own visits carry the token; this is not one of them.
                "X-CSRF-TOKEN": usePage().props.csrfToken ?? ""
            },
            body: JSON.stringify({ trackId })
        }).catch(() => {
            // Offline, or refused. Nothing here is recoverable and nothing depends on it.
        });
    } catch {
        // `fetch` itself missing (a very old WebView). Playback is unaffected.
    }
}
