import { router, usePage } from "@inertiajs/vue3";
import type { Ref } from "vue";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { useToast } from "Composables/useToast";

/** What {@link useSubjectTracks} hands its caller: an in-flight flag and the two verbs. */
export type UseSubjectTracksReturn = {
    /** True while a partial reload is in flight, so a control can refuse a second press. */
    busy: Ref<boolean>;
    /** Replace the queue with this subject and start it. Resolves false when it holds nothing. */
    playSubject: () => Promise<boolean>;
    /**
     * The same, but starting on one particular track of it — a chapter's own play button.
     * Resolves false when the subject holds nothing or does not contain that track.
     */
    playSubjectFrom: (trackId: string) => Promise<boolean>;
    /** Append this subject to the queue. Resolves false when it holds nothing. */
    enqueueSubject: () => Promise<boolean>;
};

/**
 * "Play this" and "queue this" for the subject of a detail page — the two verbs a hero
 * offers, and the fetch that has to happen before either can act.
 *
 * SHARED BY THE HERO'S BUTTON ROW AND THE PLAYLIST PAGE'S POPOVER, which differ in nothing
 * but their shape: the same round trip, the same "a subject with no tracks is a toast, not
 * an error", the same rule about what play MEANS. A copy in each is how the two would come
 * to disagree.
 *
 * PLAY MEANS REPLACE. "Play this artist" empties the queue and puts their records in it,
 * which is what a listener means by it and what every player does; "enqueue" appends and
 * leaves what is playing alone. The two sit next to each other wherever this is used, so the
 * labels around them have to be unambiguous.
 *
 * IT FETCHES THE TRACKS WHEN PRESSED, not when the page loads. Every Music page paginates
 * its songs table, so the rows on screen are never the whole subject, and the whole subject
 * can be thousands of tracks. Those controllers therefore declare `queueTracks` as an
 * OPTIONAL Inertia prop (App\Services\Music\QueuePayload) and this asks for it by name with
 * a partial reload. There is no endpoint to call because this app has no REST API by design —
 * a partial reload IS the Inertia way to fetch more of a page.
 *
 * The fetched payload is kept for the life of the page: pressing play and then enqueue costs
 * one round trip, not two, and the second press is instant.
 *
 * …UNLESS THE PAGE ALREADY HAS THEM, which is what `provided` is for. A playlist is not
 * paginated: it is a hand-made list, every entry is on screen, and each row carries its own
 * play button — so the queue payload is the page's content rather than an extra to go back
 * for. Given that, this never reaches for the network and `busy` never turns true.
 *
 * BOTH VERBS RESOLVE TO A BOOLEAN — "did anything happen" — because the caller often has one
 * more thing to do on success and must not do it on failure: the popover closes itself only
 * when it actually queued something, so a subject with no tracks leaves the menu open under
 * the toast that says why.
 *
 * It reads usePlayerQueue and usePlayerAudio directly rather than emitting upward: both are
 * module singletons, so a component in between would only be prop-drilling.
 *
 * @param provided reads the subject's tracks when the page already holds all of them. An
 *                 empty array is a real answer ("this playlist is empty"), not "unset" —
 *                 which is why the check is `!== undefined` rather than a truthiness test.
 */
export const useSubjectTracks = (provided?: () => QueueTrack[] | undefined): UseSubjectTracksReturn => {
    const { t } = useI18n();
    const page = usePage();
    const { playNow, enqueue } = usePlayerQueue();
    const { play } = usePlayerAudio();
    const { addToast } = useToast();

    const busy = ref(false);

    /** The reload already on the wire, so a second press joins it — see `loadTracks`. */
    let inFlight: Promise<QueueTrack[]> | null = null;

    /**
     * The tracks, fetched once per page and remembered.
     *
     * A partial reload rather than a request of our own: `only` names the optional prop, so
     * the server runs that closure and nothing else on the page re-renders. Wrapped in a
     * promise because Inertia reports through callbacks, and both verbs need to act AFTER
     * the payload has landed.
     */
    const loadTracks = async (): Promise<QueueTrack[]> => {
        const own = provided?.();
        if (own !== undefined) return own;

        const alreadyThere = page.props.queueTracks as QueueTrack[] | undefined;
        if (alreadyThere) return alreadyThere;

        /*
         * THE IN-FLIGHT REQUEST IS SHARED, not started twice. `busy` disables the controls, but
         * it is a flag rather than a lock: two presses in the same tick — play then enqueue, a
         * keyboard repeat, or the audiobook page where several controls sit together — each
         * started their own `router.reload`, and INERTIA CANCELS THE FIRST. A cancelled visit
         * never fires `onSuccess`, so that promise settled through nothing at all and the verb
         * waiting on it silently did nothing, with no error anywhere.
         *
         * Returning the same promise means the second caller waits on the first request rather
         * than replacing it, and both verbs act on the one payload.
         */
        if (inFlight !== null) return inFlight;

        busy.value = true;

        inFlight = new Promise<QueueTrack[]>(resolve => {
            router.reload({
                only: ["queueTracks"],
                onSuccess: () => resolve((page.props.queueTracks as QueueTrack[] | undefined) ?? []),
                // A failed reload must not leave the control stuck: resolve empty and let the
                // caller say so, rather than hanging on a promise nothing will settle.
                onError: () => resolve([]),
                onFinish: () => {
                    busy.value = false;
                }
            });
        });

        try {
            return await inFlight;
        } finally {
            // Cleared however it settled, so a later press starts a fresh request rather than
            // being handed a resolved promise from a page the reader has since navigated past.
            inFlight = null;
        }
    };

    /**
     * Replace the queue with this subject and start playing.
     *
     * `play()` is called explicitly, and it matters: loading a track does not start it, and a
     * browser only allows playback from a user gesture — the click is that gesture, so the
     * call has to happen inside the handler chain rather than in a watcher somewhere later.
     */
    const playSubject = async (): Promise<boolean> => {
        const tracks = await loadTracks();
        if (tracks.length === 0) {
            addToast(t("music.subjectMenu.nothingToPlay"), "warning", 3000);

            return false;
        }

        playNow(tracks);
        play();

        return true;
    };

    /**
     * Replace the queue with this subject and start on ONE OF ITS TRACKS.
     *
     * What a chapter's play button means, and it is deliberately not "play this one track":
     * pressing chapter 40 of a book queues the whole book and starts there, so the reader
     * goes on to 41 without touching anything. Starting a single chapter would strand them at
     * the end of it — which for a book is the one thing the player must not do.
     *
     * A track that is not in the subject resolves false rather than starting at 0: it can
     * only mean the page and the payload have gone out of step, and silently playing chapter
     * 1 when 40 was pressed is worse than doing nothing and saying so.
     */
    const playSubjectFrom = async (trackId: string): Promise<boolean> => {
        const tracks = await loadTracks();
        const index = tracks.findIndex(track => track.id === trackId);

        if (tracks.length === 0 || index === -1) {
            addToast(t("music.subjectMenu.nothingToPlay"), "warning", 3000);

            return false;
        }

        playNow(tracks, index);
        play();

        return true;
    };

    /** Append this subject to the queue, leaving whatever is playing alone. */
    const enqueueSubject = async (): Promise<boolean> => {
        const tracks = await loadTracks();
        if (tracks.length === 0) {
            addToast(t("music.subjectMenu.nothingToPlay"), "warning", 3000);

            return false;
        }

        enqueue(tracks);
        // `t(key, plural)` — the same call PlayQueue makes for its summary; the message
        // carries both branches and `{count}` is filled from the number.
        addToast(t("music.subjectMenu.enqueued", tracks.length), "success", 3000);

        return true;
    };

    return { busy, playSubject, playSubjectFrom, enqueueSubject };
};
