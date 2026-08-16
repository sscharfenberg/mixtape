/******************************************************************************
 * useAddToPlaylist
 * The one piece of behaviour behind "add this to a playlist": which playlists may be offered,
 * which one is picked, and the POST that writes it.
 *
 * A composable rather than a component, because the SAME decision is presented twice and the
 * two presentations have nothing else in common. In a detail page's hero it is a row under the
 * facts — a sentence, a select, a button; in the play queue's menu it is a modal with the
 * button in its footer. Written as one component with a `variant` prop, the two would be a
 * template of conditionals; written twice, the "am I allowed to press save yet" rule would
 * exist twice and drift.
 *
 * THE LIST OF PLAYLISTS IS A SHARED PROP, not something fetched here. `playlists` is on every
 * response (HandleInertiaRequests) because one of the two callers is the queue menu, which
 * lives in FullLayout and can be opened from any page — including pages that know nothing
 * about playlists. A caller with a narrower offer passes `offered`: the detail pages send the
 * ids of the playlists that do not already hold their subject (`addablePlaylists`), so the
 * select shows only the ones where saving would do something.
 *
 * THE WRITE IS AN ORDINARY INERTIA POST, and the round trip it costs is the point: the server
 * flashes the outcome (which the toast bridge picks up) and re-renders the page, which is what
 * recomputes `addablePlaylists` — so the playlist just written to leaves the select by itself,
 * with nothing here having to guess at what the server decided. `preserveState` keeps the page
 * component rather than rebuilding it, so a table below the hero does not blink.
 *****************************************************************************/
import { router, usePage } from "@inertiajs/vue3";
import type { ComputedRef, Ref } from "vue";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "Composables/useToast";

/** One entry of the shared `playlists` prop — the reader's own, in the order they arranged. */
export type PlaylistOption = {
    /** UUID, and the value the select emits. */
    id: string;
    /** The playlist's name, unique per owner. */
    name: string;
};

/** The four things a detail page can be about — mirrors App\Enums\PlaylistSubject. */
export type AddablePlaylistSubject = "song" | "album" | "artist" | "genre";

/**
 * What is being added, in one of the two shapes the endpoint accepts.
 *
 * A SUBJECT when the server can work the tracks out from ids that are not track ids — a detail
 * page's hero (one album), or a listing's ticked rows (eight artists). That is why a hero never
 * has to hold a thousand track ids to offer this, and why ticking a screenful of artists costs
 * eight ids rather than nine hundred.
 *
 * TRACK IDS when the browser is already looking at them: the play queue, in the order the reader
 * arranged, and a TRACK table's ticked rows. It is also the only shape that can carry an
 * audiobook chapter, since the subject query is music-only by design.
 *
 * The subject shape is plural even for one, so a hero sends `ids: [id]` — a scalar alternative
 * would be a third shape that is a strict special case of this one.
 */
export type AddToPlaylistBody = { subject: AddablePlaylistSubject; ids: string[] } | { tracks: string[] };

/** What {@link useAddToPlaylist} hands back. */
export type UseAddToPlaylistReturn = {
    /** The playlists this caller may offer, in the reader's own order. */
    options: ComputedRef<PlaylistOption[]>;
    /** The chosen playlist's id, or "" for none — the Select owns the value, this holds it. */
    selected: Ref<string>;
    /** True while the POST is in flight, so save cannot be pressed twice. */
    saving: Ref<boolean>;
    /** Whether save may be pressed: something is chosen and nothing is in flight. */
    canSave: ComputedRef<boolean>;
    /** Write it. The callback runs only on success — the queue's modal closes on it. */
    save: (onSaved?: () => void) => void;
};

/**
 * Pick a playlist and add something to it.
 *
 * Both parameters are GETTERS rather than values, and each for its own reason: the body is
 * read at the moment save is pressed (the queue may have grown since the modal opened), and
 * the offer is re-read whenever the page's props change (a successful save removes a playlist
 * from it).
 *
 * @param body what to add, evaluated per save
 * @param offered ids this caller may offer, or undefined to offer every playlist
 */
export function useAddToPlaylist(
    body: () => AddToPlaylistBody,
    offered?: () => string[] | undefined
): UseAddToPlaylistReturn {
    const page = usePage();
    const { t } = useI18n();
    const { addToast } = useToast();
    const selected = ref("");
    const saving = ref(false);

    /**
     * The offerable playlists: the shared list, narrowed by the caller's ids when it has any.
     *
     * Read tolerantly — a partial reload that does not carry the prop leaves the select empty
     * rather than throwing, the same way useSiteAreas reads `library`.
     */
    const options = computed<PlaylistOption[]>(() => {
        const all = page.props.playlists ?? [];
        const allowed = offered?.();

        return allowed === undefined ? all : all.filter(playlist => allowed.includes(playlist.id));
    });

    /*
     * A CHOICE CAN BE WITHDRAWN UNDER THE READER. The offer is recomputed on every visit, so a
     * playlist that filled up in another tab — or the one just saved to — disappears from the
     * list while its id is still in `selected`. Left alone, the trigger would show a
     * placeholder (no option matches) while the button stayed enabled and armed at a playlist
     * nobody can see. Clearing it puts the two back in step.
     */
    watch(options, list => {
        if (selected.value !== "" && !list.some(playlist => playlist.id === selected.value)) {
            selected.value = "";
        }
    });

    const canSave = computed(() => selected.value !== "" && !saving.value);

    /**
     * POST the addition to the chosen playlist.
     *
     * Guarded on `canSave` rather than trusting the button's `disabled`: this is also reachable
     * by submitting the form with Enter, and a disabled attribute is not a rule about what may
     * be sent.
     *
     * `selected` is cleared on success only. A failed write leaves the choice standing, so
     * pressing save again is the retry — clearing it would make the reader re-pick a playlist
     * to repeat something they already asked for.
     *
     * A REJECTED WRITE HAS TO SAY SO, and nothing else here will. The success path reports
     * itself through the server's flash (the toast bridge), but a 422 carries its message in
     * `errors` — which this form has nowhere to render, since its only field is a select whose
     * value was never the problem. Left silent it is the worst possible outcome: the dialog
     * stays open under a button that appears to do nothing, forever. Reachable in practice —
     * a selection survives paging, so ticking past the request's ceiling is a few select-alls.
     */
    function save(onSaved?: () => void): void {
        if (!canSave.value) return;

        saving.value = true;
        router.post(`/playlists/${selected.value}/tracks`, body(), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                selected.value = "";
                onSaved?.();
            },
            onError: errors => {
                // The server's own words are not used — a message composed in PHP would be
                // German on a page being read in English (the project's server-sends-raw rule).
                // WHICH field failed is all that is read, and only to tell "too much at once"
                // from anything else.
                addToast(t(errors.ids || errors.tracks ? "playlists.add.tooMany" : "playlists.add.failed"), "error", 4000);
            },
            onFinish: () => {
                saving.value = false;
            }
        });
    }

    return { options, selected, saving, canSave, save };
}
