/******************************************************************************
 * usePlayerQueue
 * The live play queue — an ordered list of tracks plus a pointer at the one the
 * player has loaded. Module-level state, the same no-Pinia singleton pattern as
 * useToast and useTooltipLayer, because the queue is read by three things that
 * are nowhere near each other in the tree: the PlayQueue panel and the PlayerBar
 * (both mounted once in FullLayout) and any page with an enqueue button.
 *
 * It has to be a client composable rather than server state per request, and the
 * reason is playback: auto-advance drives off the audio element's `ended` event,
 * which lives in the browser, and the player has to keep running while Inertia
 * swaps pages underneath it. A queue that needed a round-trip per track change
 * could not do either. See docs/data-model.md → "The play queue", where this was
 * settled on 2026-07-20.
 *
 * PERSISTENCE, and what is deliberately missing. For now the queue survives in
 * `localStorage` only. The decided design also persists it server-side for a
 * logged-in user (the `player_states` table and its model already exist), so the
 * queue and your place in it resume on another device; that half is NOT built
 * yet. This module is shaped for it: everything goes through `commit()`, so the
 * debounced POST lands in one place, and the stored payload already carries the
 * `userId` it belongs to.
 *
 * `userId` is why the payload is scoped rather than global. This app is
 * deliberately shared — family and friends have accounts on one instance — so
 * two people using one browser must not inherit each other's queue. A stored
 * payload whose user does not match the current one is discarded on hydrate
 * rather than adopted.
 *
 * Tracks are stored WHOLE, not as bare ids. The app has no REST API by design
 * (Inertia only), so there is nothing for a client-side queue to call to turn an
 * id back into a title — and the panel has to render the moment the page loads.
 * The cost is that a renamed track shows its old title until it is re-queued,
 * which is the right trade for a list you assembled minutes ago.
 *****************************************************************************/
import { usePage } from "@inertiajs/vue3";
import type { ComputedRef, Ref } from "vue";
import { computed, ref } from "vue";

/** Storage key. The `v1` is load-bearing: a shape change bumps it rather than trying to migrate. */
const STORAGE_KEY = "mixtape.queue.v1";

/** Sentinel for "nothing loaded" — an empty queue, or one that was cleared. */
const NOTHING = -1;

/**
 * One track in the queue. A denormalised copy rather than an id, so the panel can
 * draw itself with no server round-trip (see the module note). Raw values only —
 * `duration` is seconds and is formatted at the point of display, per the
 * project's server-sends-raw rule.
 */
export type QueueTrack = {
    /** The track's UUID — its identity everywhere, and the key the queue dedupes on. */
    id: string;
    /** Track title, as tagged. */
    name: string;
    /** Performing artist, or null for a file whose tags carried none. */
    artist: string | null;
    /** Album name, or null when the track is filed under none. */
    album: string | null;
    /** Cover to draw beside it, or null when the track has none at all. */
    coverUrl: string | null;
    /** Playing time in SECONDS, or null when the file carried no duration. */
    duration: number | null;
    /** The track's own detail page, so a queue row is a real link. */
    href: string;
};

/** The localStorage payload. Versioned and user-scoped; see the module note for both. */
type PersistedQueue = {
    version: 1;
    userId: string | null;
    tracks: QueueTrack[];
    currentIndex: number;
};

/** Return type of {@link usePlayerQueue}. */
export type UsePlayerQueueReturn = {
    /** The queue in play order. */
    tracks: Ref<QueueTrack[]>;
    /** Index of the loaded track, or -1 when the queue is empty. */
    currentIndex: Ref<number>;
    /** The loaded track, or null. Drives whether the PlayerBar replaces the footer. */
    current: ComputedRef<QueueTrack | null>;
    /** True while the queue holds nothing — the PlayQueue panel renders only when this is false. */
    isEmpty: ComputedRef<boolean>;
    /** Total playing time of the queue in seconds, ignoring tracks with no duration. */
    totalDuration: ComputedRef<number>;
    /** Replace the queue with these tracks and load the first. */
    playNow: (tracks: QueueTrack[]) => void;
    /** Append to the end of the queue. Loads the first one if nothing was loaded. */
    enqueue: (tracks: QueueTrack | QueueTrack[]) => void;
    /** Insert directly after the loaded track, so it plays next without disturbing the rest. */
    playNext: (tracks: QueueTrack | QueueTrack[]) => void;
    /** Drop the track at `index`, keeping the pointer on whatever was loaded. */
    remove: (index: number) => void;
    /** Move a track within the queue, keeping the pointer on whatever was loaded. */
    reorder: (from: number, to: number) => void;
    /** Load the track at `index` (a click in the panel). */
    jumpTo: (index: number) => void;
    /** Load the next track. Returns false at the end of the queue, which is where repeat will hook in. */
    next: () => boolean;
    /** Load the previous track. Returns false at the start of the queue. */
    previous: () => boolean;
    /** Empty the queue entirely. */
    clear: () => void;
    /** Restore from storage. Called once, by FullLayout — see the function's own note. */
    hydrate: () => void;
};

// Module-level state — every consumer shares this one queue.
const tracks = ref<QueueTrack[]>([]);
const currentIndex = ref<number>(NOTHING);

/** Guards `hydrate()` against a second run, since FullLayout is mounted once but tests mount it repeatedly. */
let hydrated = false;

/**
 * The signed-in user's id, or null for a guest arriving on a share link.
 *
 * Read from Inertia's shared props on every call rather than captured once: this
 * module is imported long before anyone logs in, and the same tab can change
 * user without a reload.
 */
function currentUserId(): string | null {
    const user = usePage().props.auth?.user;

    return user ? String(user.id) : null;
}

/**
 * Write the queue to storage.
 *
 * The single choke point for persistence, which is the point of it: when the
 * server sync lands, the debounced POST to `player_states` goes here and nothing
 * else in the module changes. Failures are swallowed deliberately — a full or
 * disabled storage must not take the player down with it.
 */
function commit(): void {
    const payload: PersistedQueue = {
        version: 1,
        userId: currentUserId(),
        tracks: tracks.value,
        currentIndex: currentIndex.value
    };

    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
    } catch {
        // Storage full, or blocked by the browser. The in-memory queue is unaffected.
    }
}

/** Clamp the pointer into the queue, or park it at "nothing" when the queue is empty. */
function clampIndex(): void {
    if (tracks.value.length === 0) {
        currentIndex.value = NOTHING;

        return;
    }
    currentIndex.value = Math.min(Math.max(currentIndex.value, 0), tracks.value.length - 1);
}

/** Normalise the one-or-many argument the three insert operations all accept. */
function asList(input: QueueTrack | QueueTrack[]): QueueTrack[] {
    return Array.isArray(input) ? input : [input];
}

/**
 * Read / write the shared play queue.
 *
 * Returns the module-level refs themselves rather than copies, which is what lets
 * a page's enqueue button and the panel in the layout agree without any props
 * between them.
 */
export function usePlayerQueue(): UsePlayerQueueReturn {
    /** The loaded track, or null when the queue is empty. */
    const current = computed<QueueTrack | null>(() => tracks.value[currentIndex.value] ?? null);

    /** Whether the queue holds anything at all — the panel's render condition. */
    const isEmpty = computed<boolean>(() => tracks.value.length === 0);

    /** Playing time of the whole queue, skipping tracks whose files carried no duration. */
    const totalDuration = computed<number>(() =>
        tracks.value.reduce((total, track) => total + (track.duration ?? 0), 0)
    );

    /** Replace the queue wholesale and load its first track — the "play this album now" operation. */
    function playNow(next: QueueTrack[]): void {
        tracks.value = [...next];
        currentIndex.value = next.length > 0 ? 0 : NOTHING;
        commit();
    }

    /**
     * Append to the end of the queue.
     *
     * Loading the first arrival when nothing was loaded is what makes a single
     * enqueue useful: without it the PlayerBar would have a queue and no track to
     * show, and the reader would have to click the row they just queued.
     */
    function enqueue(input: QueueTrack | QueueTrack[]): void {
        tracks.value = [...tracks.value, ...asList(input)];
        if (currentIndex.value === NOTHING) currentIndex.value = 0;
        commit();
    }

    /** Insert straight after the loaded track, leaving everything already queued behind it in order. */
    function playNext(input: QueueTrack | QueueTrack[]): void {
        const additions = asList(input);
        if (currentIndex.value === NOTHING) {
            enqueue(additions);

            return;
        }
        const at = currentIndex.value + 1;
        tracks.value = [...tracks.value.slice(0, at), ...additions, ...tracks.value.slice(at)];
        commit();
    }

    /**
     * Drop one track.
     *
     * The pointer follows the track that was loaded rather than the index it sat
     * at — removing something above it would otherwise silently switch the player
     * to a different song.
     */
    function remove(index: number): void {
        if (index < 0 || index >= tracks.value.length) return;
        tracks.value = tracks.value.filter((_, position) => position !== index);
        if (index < currentIndex.value) currentIndex.value -= 1;
        clampIndex();
        commit();
    }

    /** Move a track, carrying the pointer with whatever was loaded (same reasoning as remove). */
    function reorder(from: number, to: number): void {
        if (from === to) return;
        if (from < 0 || from >= tracks.value.length) return;
        if (to < 0 || to >= tracks.value.length) return;

        const loaded = tracks.value[currentIndex.value] ?? null;
        const next = [...tracks.value];
        const [moved] = next.splice(from, 1);
        next.splice(to, 0, moved);
        tracks.value = next;
        if (loaded) currentIndex.value = next.indexOf(loaded);
        commit();
    }

    /** Load the track at `index` — a click on a queue row. */
    function jumpTo(index: number): void {
        if (index < 0 || index >= tracks.value.length) return;
        currentIndex.value = index;
        commit();
    }

    /** Step forward. False at the end, which is the hook repeat-all will replace later. */
    function next(): boolean {
        if (currentIndex.value === NOTHING || currentIndex.value >= tracks.value.length - 1) return false;
        currentIndex.value += 1;
        commit();

        return true;
    }

    /** Step back. False at the start. */
    function previous(): boolean {
        if (currentIndex.value <= 0) return false;
        currentIndex.value -= 1;
        commit();

        return true;
    }

    /** Empty the queue, which also puts the footer back in place of the PlayerBar. */
    function clear(): void {
        tracks.value = [];
        currentIndex.value = NOTHING;
        commit();
    }

    /**
     * Restore the queue from storage, once.
     *
     * Called from FullLayout's setup rather than at module load, because it needs
     * Inertia's shared props to know whose queue it is reading, and those do not
     * exist until the app has a page. A payload belonging to a different user — or
     * written by an older shape — is discarded rather than adopted, so a shared
     * browser never hands one person the other's queue.
     */
    function hydrate(): void {
        if (hydrated) return;
        hydrated = true;

        let stored: string | null = null;
        try {
            stored = window.localStorage.getItem(STORAGE_KEY);
        } catch {
            return; // Storage unavailable; an in-memory queue still works.
        }
        if (!stored) return;

        let payload: PersistedQueue;
        try {
            payload = JSON.parse(stored) as PersistedQueue;
        } catch {
            return; // Corrupt entry — start clean rather than throw at boot.
        }

        if (payload.version !== 1) return;
        if (payload.userId !== currentUserId()) return;
        if (!Array.isArray(payload.tracks)) return;

        tracks.value = payload.tracks;
        currentIndex.value = payload.currentIndex ?? NOTHING;
        clampIndex();
    }

    return {
        tracks,
        currentIndex,
        current,
        isEmpty,
        totalDuration,
        playNow,
        enqueue,
        playNext,
        remove,
        reorder,
        jumpTo,
        next,
        previous,
        clear,
        hydrate
    };
}

/**
 * Reset the singleton — tests only.
 *
 * The module-level state and the one-shot `hydrated` flag both outlive a test, so
 * a spec that queues something leaks into the next one. Exported rather than
 * worked around with module mocking, the same way the other singletons in this
 * app are drained (see docs/testing.md → module singletons).
 */
export function resetPlayerQueueForTests(): void {
    tracks.value = [];
    currentIndex.value = NOTHING;
    hydrated = false;
}
