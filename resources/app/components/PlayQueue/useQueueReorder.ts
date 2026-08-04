/******************************************************************************
 * useQueueReorder
 * The two gestures that move a track inside the play queue: a pointer DRAG by the
 * row's grip, and Alt+↑/↓ from the keyboard. Colocated with PlayQueue rather than
 * living in composables/ because nothing else in the app reorders a queue — the
 * same reasoning that keeps rowNavigation.ts beside DataTable's two row components.
 *
 * BOTH GESTURES GO THROUGH `usePlayerQueue().reorder()`, and nothing here touches
 * `tracks` directly. That is not tidiness: `reorder()` carries the pointer with
 * whatever track was LOADED (so dragging something above the playing row does not
 * silently switch songs) and it is the call that persists the queue. A hand-rolled
 * splice here would lose both, and the loss would be invisible until a reload.
 *
 * WHY SORTABLEJS, AND WHY NO VUE WRAPPER. Native HTML5 drag-and-drop is
 * disqualified outright — it fires no events on touch, and this panel has a phone
 * mode. SortableJS brings the three things a hand-rolled version would cost most:
 * touch support, auto-scroll when the pointer nears the edge of the scrolling list,
 * and `handle`. The Vue wrappers (vuedraggable, vue-draggable-plus) are skipped
 * because their whole selling point is owning the list through `v-model`, which is
 * exactly what must NOT happen when the queue is a module singleton that also
 * persists itself — in controlled mode a wrapper earns nothing and this app has
 * very few runtime dependencies on purpose.
 *
 * WHY `forceFallback`. Sortable would otherwise use native HTML5 dragging on a
 * desktop and its own mouse/touch fallback everywhere else — two code paths, two
 * sets of drag visuals to style, and a documented history of browsers refusing to
 * start a native drag from inside a <button>, which is exactly what the grip is.
 * Forcing the fallback gives one path on every device, styleable in our own CSS
 * (`--dragging` on the clone, `--ghost` on the gap it left), and it is the path a
 * Playwright spec can drive with plain mouse moves.
 *****************************************************************************/
import Sortable from "sortablejs";
import type { Ref } from "vue";
import { nextTick, onBeforeUnmount, watch } from "vue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { altKeyLabel, shortcut } from "Utils/platform";

/**
 * How long Sortable takes to animate rows shuffling out of the way.
 *
 * A JavaScript option, so it cannot read the Sass token — but it MIRRORS
 * `ti.$c-play-queue` ("fast", 150ms), which is what the row's own hover and glow
 * transitions use. Keep the two in step: a drag that settles at a different speed
 * from the row it lands on reads as two different components.
 */
const ANIMATION = 150;

/**
 * The controls a row holds, in tab order. Read when Alt+↑/↓ moves a row, to put
 * focus back on the SAME control afterwards — see `onRowKeydown`.
 */
const CONTROLS = [".play-queue__grip", ".play-queue__load", ".play-queue__remove"] as const;

/** Return type of {@link useQueueReorder}. */
export type UseQueueReorderReturn = {
    /** Bind to each row's `keydown`: Alt+↑/↓ moves that row one place. */
    onRowKeydown: (event: KeyboardEvent, index: number) => void;
    /** Bind to the grip's `pointerdown`, so pressing it really does focus it. */
    onGripPointerdown: (event: PointerEvent) => void;
    /** The shortcut as THIS keyboard prints it (`⌥↑/↓` or `Alt+↑/↓`), for the grip's hint. */
    shortcutLabel: string;
};

/**
 * Whether the reader has asked for motion.
 *
 * Written positively (`no-preference`) rather than as a `reduce` opt-out, matching
 * the repo's motion rule — so a browser that reports nothing at all gets no
 * animation either.
 */
function prefersMotion(): boolean {
    return window.matchMedia("(prefers-reduced-motion: no-preference)").matches;
}

/**
 * Wire drag-and-drop and Alt+↑/↓ onto the queue's scrolling `<ol>`.
 *
 * Takes the element as a ref rather than mounting on a selector because the whole
 * panel is behind a `v-if`: an empty queue renders no list at all, so the element
 * arrives (and leaves again) long after setup. Hence a watcher rather than
 * `onMounted` — the instance is built when the list appears and destroyed when it
 * goes, which also covers the queue being cleared and refilled.
 */
export function useQueueReorder(list: Ref<HTMLOListElement | null>): UseQueueReorderReturn {
    const { tracks, reorder } = usePlayerQueue();

    let sortable: Sortable | null = null;

    /**
     * Apply a finished drag to the queue — and first, put the DOM back.
     *
     * Sortable has already moved the `<li>` by the time this runs, which leaves two
     * writers on one list: Sortable's move and the re-render `reorder()` triggers.
     * Undoing the move restores the state Vue's virtual DOM still believes in, so the
     * queue's own render is the only thing that reorders anything. This is what the
     * Vue wrappers do internally, and skipping it is how a wrapper-less integration
     * ends up with a duplicated or missing row.
     *
     * The restore is index-arithmetic-free on purpose: take the children WITHOUT the
     * dragged node and insert it before whichever one used to follow it (or at the
     * end, when nothing did).
     */
    function applyDrop(event: Sortable.SortableEvent): void {
        const { oldIndex, newIndex, item, from } = event;
        if (oldIndex === undefined || newIndex === undefined || oldIndex === newIndex) return;

        const siblings = [...from.children].filter(node => node !== item);
        from.insertBefore(item, siblings[oldIndex] ?? null);

        reorder(oldIndex, newIndex);
    }

    /** Build the Sortable instance over `element`. See the banner for every non-obvious option. */
    function create(element: HTMLOListElement): Sortable {
        return new Sortable(element, {
            handle: ".play-queue__grip",
            draggable: ".play-queue__row",
            /*
             * A long-press starts a drag on TOUCH ONLY. Without the pair, a finger
             * dragging a row steals the gesture that scrolls the list — and the list
             * scrolls far more often than it is reordered.
             */
            delay: 150,
            delayOnTouchOnly: true,
            touchStartThreshold: 3,
            // One code path on every device — see the `forceFallback` note in the banner.
            forceFallback: true,
            fallbackOnBody: true,
            // A few pixels of slop, so a click on the grip is not read as a tiny drag.
            fallbackTolerance: 4,
            // Zero under reduced motion: rows then jump to their new places instead of sliding.
            animation: prefersMotion() ? ANIMATION : 0,
            /*
             * Auto-scroll while dragging near the ends of the list. The queue is a
             * fixed-height scroll container, so without this a track can only be moved
             * as far as the visible rows — and 40-odd tracks do not fit.
             */
            scroll: true,
            scrollSensitivity: 48,
            ghostClass: "play-queue__row--ghost",
            dragClass: "play-queue__row--dragging",
            onEnd: applyDrop
        });
    }

    /**
     * Move the row at `index` one place with Alt+↑/↓, the keyboard's answer to the drag.
     *
     * Bound per row rather than once on the list because the index is what the handler
     * needs and the template already has it; `keydown` bubbles, so pressing it while
     * holding any of the row's three controls works.
     *
     * Alt is what keeps this out of everyone's way: a bare ↑/↓ is how a reader scrolls
     * the panel, and hijacking it would make the queue unscrollable from the keyboard.
     * The event is only consumed once a move is genuinely going to happen — at the ends
     * of the queue the keystroke is left alone rather than swallowed.
     */
    function onRowKeydown(event: KeyboardEvent, index: number): void {
        if (!event.altKey) return;
        if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;

        const to = event.key === "ArrowUp" ? index - 1 : index + 1;
        if (to < 0 || to >= tracks.value.length) return;

        event.preventDefault();

        const target = event.target instanceof Element ? event.target : null;
        const held = CONTROLS.find(selector => target?.closest(selector)) ?? CONTROLS[0];

        reorder(index, to);

        /*
         * FOCUS HAS TO BE PUT BACK BY HAND. The `v-for` key carries the index (it must:
         * the same song may sit in the queue twice, so the id alone is not unique), which
         * means every row in the moved range is a new element and the one holding focus is
         * gone the moment the queue re-renders. Focus would fall to <body> and the journey
         * would end after a single press — so the same control is re-focused in the row's
         * new position, and Alt+↓ Alt+↓ walks a track down the queue.
         */
        void nextTick(() => {
            const row = list.value?.children.item(to) as HTMLElement | null;
            row?.querySelector<HTMLElement>(held)?.focus();
        });
    }

    /**
     * Focus the grip when it is pressed, because the browser may well not.
     *
     * THE SHORTCUT NEEDS A FOCUSED ROW, and on macOS Safari and Firefox
     * deliberately leave a clicked `<button>` unfocused — that is the platform
     * convention, not a bug. Chrome focuses it, which is why this looked like a
     * Mac-only fault when it is really "nothing was focused": hovering a row and
     * pressing the shortcut does nothing, in any browser, because hover is not
     * focus. The hint on the grip now says to click it first, and this is what
     * makes that instruction true everywhere rather than only in Chrome.
     *
     * `preventScroll` because focus otherwise scrolls the element into view, and a
     * half-visible row would jump under the pointer just as a drag begins.
     */
    function onGripPointerdown(event: PointerEvent): void {
        (event.currentTarget as HTMLElement | null)?.focus({ preventScroll: true });
    }

    watch(
        list,
        element => {
            sortable?.destroy();
            sortable = element ? create(element) : null;
        },
        { immediate: true }
    );

    // Sortable listens on the document as well as the element, so an instance left
    // behind by an unmounted panel keeps a detached list alive and reacting.
    onBeforeUnmount(() => {
        sortable?.destroy();
        sortable = null;
    });

    /*
     * Named for the keyboard in front of the reader, because `altKey` is one bit with
     * two names printed on it — and a hint that says "Alt" to someone looking at a ⌥
     * key is naming a key they cannot find. The HANDLER never branches on platform:
     * it is the same modifier either way, only the word changes.
     */
    const shortcutLabel = shortcut(altKeyLabel(), "↑/↓");

    return { onRowKeydown, onGripPointerdown, shortcutLabel };
}
