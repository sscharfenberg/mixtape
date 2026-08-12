/******************************************************************************
 * usePlayQueuePanel
 * Whether the play queue's panel is showing.
 *
 * Its own composable rather than another field on usePlayerQueue, because it is
 * a different kind of thing: that module holds what is IN the queue and persists
 * it, this holds whether a panel happens to be on screen right now. Mixing them
 * would put a piece of view state into the payload written to storage and
 * (later) POSTed to `player_states`, where it has no business — you do not want
 * a panel you opened on your phone reopening itself on your desktop.
 *
 * Module-level state for the usual reason in this app (the same no-Pinia pattern
 * as useToast): the toggle lives in the header and the panel lives in the layout
 * body, two components with no path between them.
 *
 * THE BROWSER CAN CLOSE THE PANEL WITHOUT ASKING, since 2026-08-08: it is a native
 * `[popover]`, so it light-dismisses on a click outside, on Escape, and on Android's back
 * gesture (Chrome routes all three through CloseWatcher). This flag is therefore a MIRROR
 * of the element as much as a command to it — PlayQueue writes what actually happened back
 * through `setOpen`, and the header's glyph follows.
 *
 * DELIBERATELY NOT PERSISTED, and deliberately not reset on navigation. Not
 * persisted so every visit starts with the content unobstructed — on a phone the
 * panel covers a good part of the screen, and a panel you left open last week is
 * not a preference. Not reset on navigation because closing it on every click
 * would make it useless for its actual job: queueing a few songs in a row while
 * moving between albums.
 *
 * EVERY WIDTH reads this, since 2026-08-08. It used to be consulted only below the
 * `landscape` step, because above it the panel stood permanently open and there was
 * no toggle at all; the dashboard's right-aligned headings ended that (see
 * PlayQueue's banner). So this flag is now the single answer to "is the queue on
 * screen", rather than one of two depending on how wide the window is.
 *****************************************************************************/
import type { ComputedRef } from "vue";
import { computed, ref } from "vue";

/** Return type of {@link usePlayQueuePanel}. */
export type UsePlayQueuePanelReturn = {
    /**
     * Whether there is a panel on this page AT ALL — false wherever the layout does not
     * render one, which today means the guest share space (ShareLayout).
     *
     * Read by everything that would otherwise offer a way to open one that is not there: the
     * header's toggle, and the `Q` shortcut. See {@link notePlayQueuePanel} for why the panel
     * itself is what answers this.
     */
    exists: ComputedRef<boolean>;
    /** Whether the panel is showing. Consulted at every width. */
    isOpen: ComputedRef<boolean>;
    /** Flip it — what the header's toggle button does. */
    toggle: () => void;
    /** Show it. Used by the PEEK, which reveals the panel for a moment when the queue grows. */
    open: () => void;
    /** Force it shut, for a caller that needs to guarantee the content is clear. */
    close: () => void;
    /**
     * Record what the panel ACTUALLY did, for PlayQueue to mirror the element's own state
     * back into this flag.
     *
     * The panel is a native `[popover]`, so the browser can close it without anything here
     * being asked — light dismiss, Escape, or Android's back gesture. Without this the flag
     * would still say "open" and the header's glyph would go on showing a close icon for a
     * panel that is gone.
     */
    setOpen: (next: boolean) => void;
};

// Module-level state — the header's toggle and the panel share this one flag.
const open = ref(false);

/** Whether a PlayQueue is mounted right now. See {@link notePlayQueuePanel}. */
const present = ref(false);

/**
 * Say whether a panel is on the page — called by PlayQueue itself, on mount and on unmount.
 *
 * THE PANEL IS THE ONE THING THAT KNOWS, which is the whole reason this is a registration
 * rather than a condition the header evaluates. The toggle and the `Q` shortcut both need to
 * disappear wherever no panel is rendered — the guest share space, since 2026-08-12, where the
 * queue is on the page instead and the panel is deliberately a signed-in reader's affordance —
 * and any rule they applied themselves ("am I in ShareLayout?", "is there a user?") is a second
 * copy of the layout's decision, kept in step by hand. Two copies drift, and the drift here has
 * a shape: a round button in the header that opens nothing at all.
 *
 * A PLAIN SETTER RATHER THAN A COUNTER, and it survives a layout swap despite Vue mounting the
 * incoming layout before unmounting the outgoing one — because only one side ever writes each
 * value. Into the share space: ShareLayout mounts and registers nothing, then the old panel
 * unmounts and writes false. Out of it: the new panel mounts and writes true, then ShareLayout
 * unmounts and writes nothing. Both land right way up.
 */
export function notePlayQueuePanel(mounted: boolean): void {
    present.value = mounted;
}

/**
 * Read / write whether the narrow-screen play queue panel is showing.
 *
 * `isOpen` is exposed as a computed rather than the ref itself, so the only ways
 * to change it are the two functions beside it — a panel that could be flipped
 * from anywhere by assignment is a panel whose state is hard to account for.
 */
export function usePlayQueuePanel(): UsePlayQueuePanelReturn {
    /** Flip the panel open or shut. */
    function toggle(): void {
        open.value = !open.value;
    }

    /** Show it, whatever it was. */
    function show(): void {
        open.value = true;
    }

    /** Close it, whatever it was. */
    function close(): void {
        open.value = false;
    }

    /** Adopt the panel element's own state — see the type's note on why this exists. */
    function setOpen(next: boolean): void {
        open.value = next;
    }

    return { exists: computed(() => present.value), isOpen: computed(() => open.value), toggle, open: show, close, setOpen };
}

/**
 * Reset the singleton — tests only, since module state outlives a test file.
 *
 * `present` resets to FALSE, which is the honest starting point: no panel is mounted until one
 * says so. A spec that mounts the toggle on its own therefore has to state the precondition
 * (`notePlayQueuePanel(true)`), which is the point — that is exactly the condition the button
 * now depends on.
 */
export function resetPlayQueuePanelForTests(): void {
    open.value = false;
    present.value = false;
}
