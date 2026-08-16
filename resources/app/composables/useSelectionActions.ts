import { usePage } from "@inertiajs/vue3";
import type { Ref } from "vue";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import type { AddablePlaylistSubject } from "Composables/useAddToPlaylist";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { useToast } from "Composables/useToast";

/** What {@link useSelectionActions} hands its caller: an in-flight flag and the two verbs. */
export type UseSelectionActionsReturn = {
    /** True while the selection is being resolved, so a control can refuse a second press. */
    busy: Ref<boolean>;
    /** Replace the queue with these rows and start. Resolves false when nothing was playable. */
    playSelection: (subject: AddablePlaylistSubject, ids: string[]) => Promise<boolean>;
    /** Append these rows to the queue. Resolves false when nothing was playable. */
    enqueueSelection: (subject: AddablePlaylistSubject, ids: string[]) => Promise<boolean>;
};

/**
 * "Play these" and "queue these" for a DataTable's ticked rows — the selection counterpart of
 * useSubjectTracks, and deliberately its twin: same two verbs, same "play means replace" rule,
 * same "nothing playable is a toast, not an error", same boolean resolve so a caller can act on
 * success only.
 *
 * WHY IT CANNOT JUST BE useSubjectTracks. That composable fetches its tracks as an optional
 * `queueTracks` Inertia prop, which works because a detail page IS one subject: the prop is the
 * page's own, and the id it resolves is in the URL. A listing is not about anything, so there is
 * no such prop for a partial reload to ask for — and a selection is not addressable that way in
 * the first place, since the ids exist only in the browser and only until the next click.
 *
 * HENCE A PLAIN `fetch`, the same exception useShareLink and the queue's own sync make: the
 * reader is not navigating, they want tracks to play. Inertia's visits carry the CSRF token
 * themselves, so this is one of the few places that has to send it by hand.
 *
 * NOTHING IS CACHED BETWEEN PRESSES, unlike useSubjectTracks, which keeps its payload for the
 * life of the page. A subject cannot change under that page; a selection changes every time a
 * checkbox is clicked, so a remembered answer would be wrong more often than right.
 *
 * `play()` IS CALLED EXPLICITLY AFTER THE AWAIT. Loading a track does not start it, and the
 * gesture that began the chain is the button press — the same shape useSubjectTracks has used
 * since the player was built, so the browser's autoplay policy is already known to allow it.
 */
export const useSelectionActions = (): UseSelectionActionsReturn => {
    const { t } = useI18n();
    const page = usePage();
    const { playNow, enqueue } = usePlayerQueue();
    const { play } = usePlayerAudio();
    const { addToast } = useToast();
    const csrfToken = computed(() => page.props.csrfToken as string);

    const busy = ref(false);

    /**
     * Ask the server what the ticked rows mean, in queue entries.
     *
     * NULL AND AN EMPTY LIST ARE DIFFERENT ANSWERS, which is the whole reason this returns a
     * nullable: null is "the request did not work", `[]` is "those rows hold nothing playable"
     * — three ticked albums that turn out to be audiobooks. They want opposite words, and a
     * single empty-list-for-everything return would force the verbs to say one of them wrongly.
     *
     * The failure toast is raised HERE because only this layer knows WHICH failure: a 429 is
     * "you have done that a lot just now", a 422 is the selection being too big, and anything
     * else is the generic one.
     *
     * THE 422 MESSAGE COVERS TWO CAUSES ON PURPOSE — more ticked rows than the request accepts,
     * and a selection that expands past what the queue can hold. This side cannot tell them
     * apart (the ceilings are the server's), and it does not need to: the answer to both is to
     * tick fewer, so a message naming one of them specifically would be wrong half the time.
     */
    const loadTracks = async (subject: AddablePlaylistSubject, ids: string[]): Promise<QueueTrack[] | null> => {
        try {
            const response = await fetch("/queue/tracks", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                    "X-CSRF-TOKEN": csrfToken.value
                },
                body: JSON.stringify({ subject, ids })
            });

            if (!response.ok) {
                const message =
                    response.status === 429
                        ? "components.datatable.selection.tooMany"
                        : response.status === 422
                          ? "components.datatable.selection.tooLarge"
                          : "components.datatable.selection.failed";

                addToast(t(message), "error", 4000);

                return null;
            }

            return (await response.json()) as QueueTrack[];
        } catch {
            // Offline, or the session rotated under us. Same answer either way: nothing to play,
            // and a reader who can press again.
            addToast(t("components.datatable.selection.failed"), "error", 4000);

            return null;
        }
    };

    /**
     * Resolve the selection once, guarding against a second press while the first is in flight.
     *
     * Shared by both verbs because the guard and the flag are the whole of what they have in
     * common before they diverge — and a press that slipped through would resolve the same
     * selection twice, which for enqueue means queueing it twice.
     *
     * A press that the guard swallows answers null and says NOTHING: the reader has not made a
     * mistake, the first press is still working, and a toast per impatient click would be noise
     * over an action that is about to happen anyway.
     */
    const resolve = async (subject: AddablePlaylistSubject, ids: string[]): Promise<QueueTrack[] | null> => {
        if (busy.value || ids.length === 0) return null;

        busy.value = true;

        try {
            return await loadTracks(subject, ids);
        } finally {
            busy.value = false;
        }
    };

    /**
     * Whether the resolved selection is worth acting on, saying so when it is not.
     *
     * The `[]` branch is the real case this exists for: ticking three rows and being told
     * "nothing playable here" is a sentence a reader can act on, where silence would read as a
     * broken button. Null has already said its piece in {@link loadTracks}.
     */
    const playable = (tracks: QueueTrack[] | null): tracks is QueueTrack[] => {
        if (tracks === null) return false;

        if (tracks.length === 0) {
            addToast(t("music.subjectMenu.nothingToPlay"), "warning", 3000);

            return false;
        }

        return true;
    };

    /** Replace the queue with the ticked rows and start playing. */
    const playSelection = async (subject: AddablePlaylistSubject, ids: string[]): Promise<boolean> => {
        const tracks = await resolve(subject, ids);

        if (!playable(tracks)) return false;

        playNow(tracks);
        play();

        return true;
    };

    /** Append the ticked rows to the queue, leaving whatever is playing alone. */
    const enqueueSelection = async (subject: AddablePlaylistSubject, ids: string[]): Promise<boolean> => {
        const tracks = await resolve(subject, ids);

        if (!playable(tracks)) return false;

        enqueue(tracks);
        // `t(key, plural)` — the message carries both branches and `{count}` is filled from the
        // number, the same call useSubjectTracks makes for the same toast.
        addToast(t("music.subjectMenu.enqueued", tracks.length), "success", 3000);

        return true;
    };

    return { busy, playSelection, enqueueSelection };
};
