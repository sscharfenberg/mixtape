/******************************************************************************
 * useSearchOverlay
 * Whether the header's search overlay is showing, and the two keys that open it.
 *
 * Module-level state for the usual reason in this app (the same no-Pinia pattern as useToast and
 * usePlayQueuePanel): the trigger lives in the header and the overlay lives in the layout body,
 * two components with no path between them. Note the split from {@link useLibrarySearch}, which
 * is deliberately PER-INSTANCE — this holds whether a panel happens to be on screen, that holds
 * what somebody is typing, and only the first of those exists once.
 *
 * THE OVERLAY SAYS WHETHER IT EXISTS, exactly as the play queue's panel does
 * (`notePlayQueuePanel`), and for the same reason: it must be absent in the guest share space —
 * "a share grants one subject, and a library search on that page would be an invitation to a
 * login form" — and any rule the header applied itself ("am I in ShareLayout?", "is there a
 * user?") would be a second copy of the layout's decision, kept in step by hand. Two copies
 * drift, and the drift here has a shape: a magnifying glass in the header that opens nothing.
 *
 * THE BROWSER CAN CLOSE IT WITHOUT ASKING, since the overlay is a native `[popover]`: it
 * light-dismisses on a click outside, on Escape, and on Android's back gesture. So `isOpen` is a
 * MIRROR of the element as much as a command to it — SearchOverlay writes what actually happened
 * back through `setOpen`, and the header's glyph follows.
 *
 * THE KEYS ARE BOUND HERE RATHER THAN IN THE KEYMAP THAT ALREADY EXISTS, and the boundary is
 * worth stating because the two nearly collide. `usePlayerShortcuts` claims Space, the arrows and
 * `k j l n p m s r q` on the document — but it stands down inside text entry and for any
 * modifier combo, which is exactly what makes both of these safe: `/` is not in its map at all,
 * `⌘K` carries a modifier, and once the field has focus every letter the reader types is text
 * entry. That check is written down because the failure would be silent and bizarre — a reader
 * typing a song title while their music seeks around under them.
 *
 * ASKING FOR SEARCH ALWAYS PUTS THE CARET IN THE FIELD, even when the panel is already up — which
 * is why there is a nonce here beside the flag. With opening as the only signal the overlay focuses
 * on the flag's false→true edge — so pressing ⌘K after tabbing out of an open panel does nothing at
 * all (measured: focus stays on a breadcrumb link). Every one of the three ways to ask
 * bumps `focusNonce`, and the overlay watches that as well as the flag. Same idea as the flash
 * `nonce` in the Inertia share: a value whose CHANGE is the message, for an event that has no state
 * of its own to watch.
 *
 * `/` IS GUARDED, `⌘K` IS NOT. A bare slash must never be stolen from a field somebody is typing
 * in (this app's search field included, where `/` is a character), so it stands down for text
 * entry and for any modifier. `⌘K` has a modifier by definition and works from anywhere, which is
 * the point of having it.
 *****************************************************************************/
import type { ComputedRef } from "vue";
import { computed, ref } from "vue";
import { isTextEntry } from "Utils/interactive";

/** Return type of {@link useSearchOverlay}. */
export type UseSearchOverlayReturn = {
    /**
     * Whether an overlay is rendered on this page AT ALL — false wherever the layout mounts none,
     * which today means the guest share space. Read by the header's trigger, so it never offers a
     * way into something that is not there.
     */
    exists: ComputedRef<boolean>;
    /** Whether the overlay is showing. */
    isOpen: ComputedRef<boolean>;
    /** Show it, and ask for the caret — what the header's trigger and both keys do. */
    open: () => void;
    /** Hide it — what a chosen result does, once it has navigated. */
    close: () => void;
    /** Flip it, for the trigger, which is one button for both directions. */
    toggle: () => void;
    /**
     * Adopt the element's own state, so a light dismiss, Escape or the back gesture is reflected
     * here rather than leaving the flag claiming a panel that is gone.
     */
    setOpen: (next: boolean) => void;
    /**
     * Bumped every time somebody asks for search. Watch it to put the caret in the field — the flag
     * alone cannot say "asked again" when the panel is already open. See the banner.
     */
    focusNonce: ComputedRef<number>;
};

// Module-level: the header's trigger and the overlay share these.
const open = ref(false);

/** Incremented by every request to search — see the banner and `focusNonce`. */
const focusNonce = ref(0);

/** Whether a SearchOverlay is mounted right now. See {@link noteSearchOverlay}. */
const present = ref(false);

/** Everything {@link bindSearchShortcuts} registered, so unbinding needs no list of its own. */
let teardown: Array<() => void> = [];

/**
 * Say whether an overlay is on the page — called by SearchOverlay itself, on mount and unmount.
 *
 * A plain setter rather than a counter, and it survives a layout swap despite Vue mounting the
 * incoming layout before unmounting the outgoing one, because only one side ever writes each
 * value: into the share space, ShareLayout registers nothing and the old overlay then writes
 * false; out of it, the new overlay writes true and ShareLayout unmounts writing nothing. Both
 * land right way up. (The same argument as `notePlayQueuePanel`, which is where it was worked
 * out.)
 */
export function noteSearchOverlay(mounted: boolean): void {
    present.value = mounted;

    // An overlay that has gone cannot be showing, and a stale `true` would have the header
    // drawing a close glyph for nothing. Only ever on the way out — a fresh mount must not
    // clobber a flag the incoming page has already set.
    if (!mounted) open.value = false;
}

/**
 * Bind `/` and `⌘K` on the window. Called by SearchOverlay on mount, which is exactly when there
 * is something for them to open.
 *
 * `unbind` first, so a double bind is impossible — two listeners would toggle twice per press,
 * which for `open()` is invisible and for anything added later would not be.
 */
export function bindSearchShortcuts(): void {
    unbindSearchShortcuts();

    const onKeydown = (event: KeyboardEvent): void => {
        // Ctrl as well as Cmd: the same chord on a keyboard that has no Cmd key. Checked before
        // the bare-slash arm below, so Cmd+/ is not read as a slash.
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
            event.preventDefault();
            requestOpen();

            return;
        }

        if (event.key !== "/") return;
        // Somebody typing owns their own slash — including in this app's search field, where the
        // character is a character. Modifiers belong to the browser.
        if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        if (isTextEntry(event.target)) return;
        // A component that has already handled it wins, the general form of the guard above.
        if (event.defaultPrevented) return;

        event.preventDefault();
        requestOpen();
    };

    window.addEventListener("keydown", onKeydown);
    teardown.push(() => window.removeEventListener("keydown", onKeydown));
}

/**
 * Show the panel and ask for the caret — the one path every request to search goes through.
 *
 * Separate from writing the flag because the two are not the same statement: the flag says whether
 * a panel is on screen, the nonce says somebody just asked to use it, and only the second is true
 * of a second press while it is already open.
 */
function requestOpen(): void {
    open.value = true;
    focusNonce.value++;
}

/** Drop the key listeners. Called by SearchOverlay on unmount. */
export function unbindSearchShortcuts(): void {
    teardown.forEach(undo => undo());
    teardown = [];
}

/**
 * Read / write whether the header's search overlay is showing.
 *
 * `isOpen` and `exists` are exposed as computeds rather than the refs themselves, so the only
 * ways to change either are the functions beside them — a flag that could be reassigned from
 * anywhere is a flag whose state is hard to account for.
 */
export function useSearchOverlay(): UseSearchOverlayReturn {
    return {
        exists: computed(() => present.value),
        isOpen: computed(() => open.value),
        open: requestOpen,
        close: () => {
            open.value = false;
        },
        // Only an opening press asks for the caret; a closing one is the reader putting the panel
        // away, and moving focus there would be taking it from wherever they are going next.
        toggle: () => {
            if (open.value) open.value = false;
            else requestOpen();
        },
        setOpen: (next: boolean) => {
            open.value = next;
        },
        focusNonce: computed(() => focusNonce.value)
    };
}

/**
 * Reset the singleton — tests only, since module state outlives a test file.
 *
 * `present` resets to FALSE, which is the honest starting point: no overlay is mounted until one
 * says so. A spec that mounts the trigger on its own therefore has to state that precondition
 * (`noteSearchOverlay(true)`), which is the point — it is exactly the condition the button
 * depends on.
 */
export function resetSearchOverlayForTests(): void {
    open.value = false;
    present.value = false;
    focusNonce.value = 0;
    unbindSearchShortcuts();
}
