/******************************************************************************
 * usePlaylistTrackReorder
 * The two gestures that move an entry within one playlist: a pointer DRAG by the row's grip,
 * and Alt+↑/↓ from the keyboard. Colocated with the detail page rather than living in
 * composables/, because nothing else reorders a playlist's entries — the same reasoning that
 * keeps usePlaylistReorder beside the listing and useQueueReorder beside PlayQueue.
 *
 * It is the listing's version one level down, and deliberately built the same way: an
 * optimistic local order seeded from an Inertia prop, a wholesale PUT of the result, and
 * SortableJS with a `handle`. The three questions its banner answers — why SortableJS, why
 * no Vue wrapper, why `forceFallback` — have the same answers here (touch support, a grip,
 * one code path on every device, and a drag a Playwright spec can drive with plain mouse
 * moves), so they are not repeated.
 *
 * WHAT IS GENUINELY DIFFERENT IS WHAT IS SENT. The listing writes playlist ids; this writes
 * ENTRY ids — `playlist_tracks.id`, not `track_id` — because the same track may sit in a
 * playlist twice, so a track id does not identify a position. That is also why the rows are
 * keyed by `entryId` in the template: two rows sharing a Vue key would make Sortable and
 * Vue disagree about which one moved.
 *
 * IT HOLDS THE ORDER LOCALLY because an Inertia prop is immutable. Without a local copy a
 * drag would have nowhere to land — the row would snap back the instant Vue re-rendered
 * from the untouched prop. The server's answer re-seeds it, but only when it actually
 * differs (see the watcher).
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import Sortable from "sortablejs";
import type { Ref } from "vue";
import { nextTick, onBeforeUnmount, ref, watch } from "vue";
import { prefersMotion } from "Utils/motion";
import { altKeyLabel, shortcut } from "Utils/platform";
import type { PlaylistTrackRow } from "./PlaylistTracks.vue";

/**
 * How long Sortable takes to animate rows shuffling out of the way.
 *
 * A JavaScript option, so it cannot read the Sass token — but it MIRRORS the row's own hover
 * timing (ti.$c-playlist-tracks, the shared "fast" rung). Keep the two in step: a drag that
 * settles at a different speed from the row it lands on reads as two different components.
 */
const ANIMATION = 150;

/** Return type of {@link usePlaylistTrackReorder}. */
export type UsePlaylistTrackReorderReturn = {
    /** The playlist in its current order — what the page renders, rather than the raw prop. */
    entries: Ref<PlaylistTrackRow[]>;
    /** Alt+↑/↓ on a row, the keyboard's answer to the drag. */
    onRowKeydown: (event: KeyboardEvent, index: number) => void;
    /** The shortcut as THIS keyboard prints it (`⌥↑/↓` or `Alt+↑/↓`), for the grip's hint. */
    shortcutLabel: string;
};

/**
 * Wire drag-and-keyboard reordering to a playlist's list element.
 *
 * @param list      the `<ul>` holding the rows
 * @param source    a getter for the server's order (the Inertia prop), watched for re-seeding
 * @param playlistId which playlist to write the new order to
 */
export function usePlaylistTrackReorder(
    list: Ref<HTMLUListElement | null>,
    source: () => PlaylistTrackRow[],
    playlistId: () => string
): UsePlaylistTrackReorderReturn {
    const entries = ref<PlaylistTrackRow[]>([...source()]);

    let sortable: Sortable | null = null;

    /*
     * Re-seed from the server, and ONLY when it actually differs. The write is optimistic, so
     * the response carries the order this already shows — assigning it back unconditionally
     * would replace every row object on every visit, which throws away the DOM the drag just
     * settled and makes the list flicker. Comparing entry ids is enough: nothing else about a
     * row changes as a result of reordering.
     */
    watch(source, next => {
        const same =
            next.length === entries.value.length &&
            next.every((entry, index) => entry.entryId === entries.value[index]?.entryId);

        if (!same) entries.value = [...next];
    });

    /** Send the order as it now stands, as ENTRY ids — see the banner for why not track ids. */
    function persist(): void {
        router.put(
            `/playlists/${playlistId()}/tracks/order`,
            { ids: entries.value.map(entry => entry.entryId) },
            {
                // Nothing about the page should move: the reader is looking at the row they just
                // dropped, and `preserveState` keeps this composable's own array — without it the
                // component would be rebuilt and the seed would come from the prop again.
                preserveScroll: true,
                preserveState: true
            }
        );
    }

    /** Move one row and persist the result. */
    function move(from: number, to: number): void {
        if (from === to) return;

        const next = [...entries.value];
        const [moved] = next.splice(from, 1);
        if (!moved) return;

        next.splice(to, 0, moved);
        entries.value = next;
        persist();
    }

    /**
     * Apply a finished drag — and first, put the DOM back.
     *
     * Sortable has already moved the `<li>` by the time this runs, which leaves two writers on
     * one list: Sortable's move and the re-render `entries` triggers. Undoing the move restores
     * the state Vue's virtual DOM still believes in, so Vue's own render is the only thing that
     * reorders anything. This is what the Vue wrappers do internally, and skipping it is how a
     * wrapper-less integration ends up with a duplicated or missing row.
     */
    function applyDrop(event: Sortable.SortableEvent): void {
        const { oldIndex, newIndex, item, from } = event;
        if (oldIndex === undefined || newIndex === undefined || oldIndex === newIndex) return;

        const siblings = [...from.children].filter(node => node !== item);
        from.insertBefore(item, siblings[oldIndex] ?? null);

        move(oldIndex, newIndex);
    }

    /** Build the Sortable instance over `element`. See useQueueReorder's banner for the options. */
    function create(element: HTMLUListElement): Sortable {
        return new Sortable(element, {
            handle: ".playlist-tracks__handle",
            draggable: "li.playlist-tracks__item",
            /*
             * A long-press starts a drag on TOUCH ONLY. Without the pair, a finger dragging a row
             * steals the gesture that scrolls the page — and the page scrolls far more often than
             * a playlist is reordered.
             */
            delay: 150,
            delayOnTouchOnly: true,
            touchStartThreshold: 3,
            forceFallback: true,
            fallbackOnBody: true,
            // A few pixels of slop, so a click on the grip is not read as a tiny drag.
            fallbackTolerance: 4,
            // Zero under reduced motion: rows then jump to their new places instead of sliding.
            animation: prefersMotion() ? ANIMATION : 0,
            // The list scrolls with the PAGE rather than in a box of its own, which Sortable
            // handles by walking up to the scrolling ancestor.
            scroll: true,
            scrollSensitivity: 64,
            ghostClass: "playlist-tracks__item--ghost",
            dragClass: "playlist-tracks__item--dragging",
            onEnd: applyDrop
        });
    }

    /**
     * Move the row at `index` one place with Alt+↑/↓, the keyboard's answer to the drag.
     *
     * A drag handle that only answers a pointer is a control a keyboard user cannot reach, and
     * the grip is a real <button> precisely so it can carry this. Alt is what keeps it out of
     * everyone's way: a bare ↑/↓ is how a reader scrolls the page. The event is only consumed
     * once a move is genuinely going to happen, so at the ends of the list the keystroke is
     * left alone rather than swallowed.
     *
     * FOCUS IS PUT BACK BY HAND, for the reason the queue's and the listing's versions record:
     * the moved row is re-rendered somewhere else in the list, so the grip that was being held
     * is a different element afterwards and focus would otherwise fall back to <body>.
     */
    function onRowKeydown(event: KeyboardEvent, index: number): void {
        if (!event.altKey) return;
        if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;

        const to = event.key === "ArrowUp" ? index - 1 : index + 1;
        if (to < 0 || to >= entries.value.length) return;

        event.preventDefault();
        move(index, to);

        void nextTick(() => {
            const grips = list.value?.querySelectorAll<HTMLElement>(".playlist-tracks__handle");
            grips?.[to]?.focus();
        });
    }

    /*
     * Built when the element appears, not on mount: the list is `v-if`d on the playlist holding
     * anything at all, so on an empty playlist there is nothing to attach to yet.
     */
    watch(
        list,
        element => {
            sortable?.destroy();
            sortable = element ? create(element) : null;
        },
        { immediate: true }
    );

    // Sortable listens on the document as well as the element, so an instance left behind would
    // keep answering pointer events for a page that is gone.
    onBeforeUnmount(() => {
        sortable?.destroy();
        sortable = null;
    });

    /*
     * Named for the keyboard in front of the reader — ⌥ on a Mac, Alt everywhere else — while
     * `aria-keyshortcuts` on the grip keeps ARIA's canonical spelling for assistive tech to
     * announce in its own words. Same split the queue's and the listing's grips make.
     */
    const shortcutLabel = shortcut(altKeyLabel(), "↑/↓");

    return { entries, onRowKeydown, shortcutLabel };
}
