/******************************************************************************
 * usePlaylistReorder
 * The two gestures that move a playlist in the listing: a pointer DRAG by the entry's
 * grip, and Alt+↑/↓ from the keyboard. Colocated with PlaylistsPage rather than living
 * in composables/ because nothing else reorders playlists — the same reasoning that
 * keeps useQueueReorder beside PlayQueue.
 *
 * IT OWNS THE ORDER LOCALLY, which is the one structural difference from the queue's
 * version. A queue is a module singleton that any page can write; a listing is an
 * Inertia PROP, and props are immutable. So this holds its own array, seeded from the
 * prop and re-seeded whenever the server sends a new one, and the page renders from
 * that. Without it a drag would have nowhere to land: the entry would snap back the
 * instant Vue re-rendered from the untouched prop.
 *
 * THE MOVE IS OPTIMISTIC AND THE WRITE IS WHOLESALE. The reorder shows immediately and
 * the whole new order goes to `PUT /playlists/order` — the ordering is a property of
 * the set, so sending one moved id would leave the server guessing at the rest (see
 * PlaylistOrderController). It travels as an ordinary Inertia visit rather than a
 * `fetch`: this app has no REST API by design, and unlike the play queue's sync — which
 * fires on every track change and must not re-render anything — a reorder is a
 * deliberate, occasional act that can afford the round trip.
 *
 * WHY SORTABLEJS, WHY NO VUE WRAPPER, AND WHY `forceFallback` are all settled in
 * useQueueReorder's banner; the same three answers apply here, for the same reasons
 * (touch support, a `handle`, one code path on every device, and a drag a Playwright
 * spec can drive with plain mouse moves).
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import Sortable from "sortablejs";
import type { Ref } from "vue";
import { nextTick, onBeforeUnmount, ref, watch } from "vue";
import type { PlaylistEntry } from "Types/playlists";
import { prefersMotion } from "Utils/motion";
import { altKeyLabel, shortcut } from "Utils/platform";

/**
 * How long Sortable takes to animate entries shuffling out of the way.
 *
 * A JavaScript option, so it cannot read the Sass token — but it MIRRORS the entry's own
 * hover timing (ti.$c-playlist "hover", the shared "fast" rung). Keep the two in step: a
 * drag that settles at a different speed from the entry it lands on reads as two
 * different components.
 */
const ANIMATION = 150;

/** Return type of {@link usePlaylistReorder}. */
export type UsePlaylistReorderReturn = {
    /** The listing in its current order — what the page renders, rather than the raw prop. */
    entries: Ref<PlaylistEntry[]>;
    /** Alt+↑/↓ on an entry, the keyboard's answer to the drag. */
    onEntryKeydown: (event: KeyboardEvent, index: number) => void;
    /** The shortcut as THIS keyboard prints it (`⌥↑/↓` or `Alt+↑/↓`), for the grip's hint. */
    shortcutLabel: string;
};

/**
 * Wire drag-and-keyboard reordering to the listing element.
 *
 * @param list  the `<ul>` holding the entries
 * @param source a getter for the server's order (the Inertia prop), watched for re-seeding
 */
export function usePlaylistReorder(
    list: Ref<HTMLUListElement | null>,
    source: () => PlaylistEntry[]
): UsePlaylistReorderReturn {
    const entries = ref<PlaylistEntry[]>([...source()]);

    let sortable: Sortable | null = null;

    /*
     * Re-seed from the server, and ONLY when it actually differs. The write is optimistic, so
     * the response carries the order this already shows — assigning it back unconditionally
     * would replace every entry object on every visit, which throws away the DOM the drag just
     * settled and makes the listing flicker. Comparing ids is enough: nothing else about an
     * entry changes as a result of reordering.
     */
    watch(source, next => {
        const same =
            next.length === entries.value.length &&
            next.every((entry, index) => entry.id === entries.value[index]?.id);

        if (!same) entries.value = [...next];
    });

    /** Send the order as it now stands. */
    function persist(): void {
        router.put(
            "/playlists/order",
            { ids: entries.value.map(entry => entry.id) },
            {
                // Nothing about the page should move: the reader is looking at the entry they
                // just dropped, and `preserveState` keeps this composable's own array — without
                // it the component would be rebuilt and the seed would come from the prop again.
                preserveScroll: true,
                preserveState: true
            }
        );
    }

    /** Move one entry and persist the result. */
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
     * Sortable has already moved the `<li>` by the time this runs, which leaves two writers
     * on one list: Sortable's move and the re-render `entries` triggers. Undoing the move
     * restores the state Vue's virtual DOM still believes in, so Vue's own render is the only
     * thing that reorders anything. This is what the Vue wrappers do internally, and skipping
     * it is how a wrapper-less integration ends up with a duplicated or missing entry.
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
            handle: ".playlist__handle",
            draggable: "li.playlist",
            /*
             * A long-press starts a drag on TOUCH ONLY. Without the pair, a finger dragging an
             * entry steals the gesture that scrolls the page — and the page scrolls far more
             * often than the listing is reordered.
             */
            delay: 150,
            delayOnTouchOnly: true,
            touchStartThreshold: 3,
            forceFallback: true,
            fallbackOnBody: true,
            // A few pixels of slop, so a click on the grip is not read as a tiny drag.
            fallbackTolerance: 4,
            // Zero under reduced motion: entries then jump to their new places instead of sliding.
            animation: prefersMotion() ? ANIMATION : 0,
            // The listing scrolls with the PAGE rather than in a box of its own, which Sortable
            // handles by walking up to the scrolling ancestor.
            scroll: true,
            scrollSensitivity: 64,
            ghostClass: "playlist--ghost",
            dragClass: "playlist--dragging",
            onEnd: applyDrop
        });
    }

    /**
     * Move the entry at `index` one place with Alt+↑/↓, the keyboard's answer to the drag.
     *
     * A drag handle that only answers a pointer is a control a keyboard user cannot reach, and
     * the grip is a real <button> precisely so it can carry this. Alt is what keeps it out of
     * everyone's way: a bare ↑/↓ is how a reader scrolls the page. The event is only consumed
     * once a move is genuinely going to happen, so at the ends of the listing the keystroke is
     * left alone rather than swallowed.
     *
     * FOCUS IS PUT BACK BY HAND, for the reason the queue's version records: the moved entry is
     * re-rendered somewhere else in the list, so the grip that was being held is a different
     * element afterwards and focus would otherwise fall back to <body>.
     */
    function onEntryKeydown(event: KeyboardEvent, index: number): void {
        if (!event.altKey) return;
        if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;

        const to = event.key === "ArrowUp" ? index - 1 : index + 1;
        if (to < 0 || to >= entries.value.length) return;

        event.preventDefault();
        move(index, to);

        void nextTick(() => {
            const grips = list.value?.querySelectorAll<HTMLElement>(".playlist__handle");
            grips?.[to]?.focus();
        });
    }

    /*
     * Built when the element appears, not on mount: the listing is `v-if`d on there being
     * playlists at all, so on an empty account there is nothing to attach to yet.
     */
    watch(
        list,
        element => {
            sortable?.destroy();
            sortable = element ? create(element) : null;
        },
        { immediate: true }
    );

    // Sortable listens on the document as well as the element, so an instance left behind
    // would keep answering pointer events for a page that is gone.
    onBeforeUnmount(() => {
        sortable?.destroy();
        sortable = null;
    });

    /*
     * Named for the keyboard in front of the reader — ⌥ on a Mac, Alt everywhere else — while
     * `aria-keyshortcuts` on the grip keeps ARIA's canonical spelling for assistive tech to
     * announce in its own words. Same split the queue's grip makes.
     */
    const shortcutLabel = shortcut(altKeyLabel(), "↑/↓");

    return { entries, onEntryKeydown, shortcutLabel };
}
