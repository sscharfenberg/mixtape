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
 * IT IS STORED IN TWO PIECES, AND WRITTEN LATE, both for the same reason: a queue can
 * hold the whole library, and the naive version rewrote all of it every time a song
 * ended. The list and the pointer have their own keys (see POSITION_STORAGE_KEY), and
 * `commit` marks them dirty instead of writing (see WRITE_DELAY_MS), so the cost of an
 * auto-advance no longer scales with how much is queued. The price of writing late is
 * that something has to guarantee the last write — `flushQueueWrites`, called when the
 * tab goes away.
 *
 * `userId` is why the payload is scoped rather than global. This app is
 * deliberately shared — family and friends have accounts on one instance — so
 * two people using one browser must not inherit each other's queue. A stored
 * payload whose user does not match the current one is discarded on hydrate
 * rather than adopted.
 *
 * Tracks are held WHOLE, not as bare ids. The app has no REST API by design
 * (Inertia only), so there is nothing for a client-side queue to call to turn an
 * id back into a title — and the panel has to render the moment the page loads.
 * The cost is that a renamed track shows its old title until it is re-queued,
 * which is the right trade for a list you assembled minutes ago.
 *
 * What is stored is whole METADATA but not whole URLs: the three URLs on a track are
 * rebuilt from its id on the way back in, since repeating the same UUID four times per
 * track is most of a browser's storage budget at library scale. `toPersisted` /
 * `fromPersisted` own that translation and are the only pair that knows the stored
 * shape; everything else in the app sees a full `QueueTrack`.
 *****************************************************************************/
import { usePage } from "@inertiajs/vue3";
import type { ComputedRef, Ref } from "vue";
import { computed, ref } from "vue";

/**
 * Storage key — deliberately NOT versioned, unlike the two keys before it
 * (`mixtape.queue.v1`, `.v2`). The version lives in the payload instead, and a shape
 * change still bumps it rather than trying to migrate.
 *
 * Putting it in the KEY is what made the two dead ones dead: a rejected payload under
 * an abandoned name is an orphan that nothing ever deletes, and it keeps its share of
 * the origin's few megabytes for as long as the browser profile lives. Under one
 * stable key the same rejection self-heals — `hydrate` refuses the old shape and the
 * next `commit` overwrites it.
 *
 * SHAPE 3 shrank the stored track: the three URLs are rebuilt from the id on the way
 * back in (see {@link toPersisted}), which takes a typical track from ~374 stored
 * characters to ~164. That is worth doing because the budget is small and counted
 * differently per browser — the floor is 5 MB per origin, and WebKit has counted it
 * in UTF-16 code units, i.e. ~2.5 M characters — so the trim moves the tightest
 * browser's ceiling from roughly 7,000 queued tracks to roughly 16,000, past the size
 * of the whole library. Shape 2 had added `streamUrl` per track and `repeat` to the
 * payload; a shape-1 track had no stream URL at all, which is why adopting one would
 * have put a row in the panel that looked playable and did nothing.
 */
const STORAGE_KEY = "mixtape.queue";

/**
 * Where the pointer lives — its own key, deliberately apart from the list.
 *
 * The two halves change at wildly different rates. The list is written when somebody
 * queues, drags or removes something; the pointer moves on every track change,
 * including every auto-advance while nobody is looking at the tab. Sharing one key
 * meant a four-minute song ending rewrote the entire queue — 1.9 MB of it at library
 * scale — to move one integer by one. Split, that write is under a hundred bytes.
 *
 * `repeat` rides with the pointer rather than the list because it changes at the same
 * rate (a click, not a queue edit) and it deliberately outlives `clear()`.
 *
 * The pair can go out of step — a quota error on the list, a profile copied half-way —
 * so the pointer is advice rather than truth: {@link applyPosition} is refused on its
 * own terms and `hydrate` clamps whatever survives into the list it actually read.
 */
const POSITION_STORAGE_KEY = "mixtape.queue.position";

/**
 * Shape version, carried by BOTH payloads because they are written and read as a pair.
 * A shape change bumps this; {@link STORAGE_KEY} says why it is not in the key names.
 */
const PERSISTED_VERSION = 3;

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
    /**
     * Where the player loads the bytes from (SongStreamController). Carried on the
     * track rather than rebuilt from the id, for the same reason the title is: the
     * queue must be drawable and playable with no server round-trip, and the server
     * is what owns which URLs exist.
     */
    streamUrl: string;
};

/**
 * One track as stored — everything the id already implies is absent rather than
 * repeated. A `QueueTrack` names the same song four times over (once as `id`, three
 * times inside URLs built from it), and at 12,000 tracks that repetition is most of
 * a browser's entire storage budget.
 *
 * Field names stay readable rather than shrinking to single letters. That would save
 * a further ~30 characters a track, which is not worth a stored payload nobody can
 * read in devtools when a queue comes back wrong.
 */
type PersistedTrack = {
    /** The track's UUID — and the seed every dropped URL is rebuilt from. */
    id: string;
    /** Track title, as tagged. */
    name: string;
    /** Performing artist, or null. */
    artist: string | null;
    /** Album name, or null. */
    album: string | null;
    /** Playing time in seconds, or null. */
    duration: number | null;
    /** True when the track has a cover at the route its id implies; absent when it has none at all. */
    hasCover?: true;
    /** Only written when the cover is somewhere the id does not imply. */
    coverUrl?: string;
    /** Only written when the detail page is not `/music/songs/{id}`. */
    href?: string;
    /** Only written when the stream is not `/music/songs/{id}/stream` — a signed share link, say. */
    streamUrl?: string;
};

/** The stored list. Versioned and user-scoped; see the module note for both. */
type PersistedQueue = {
    version: number;
    userId: string | null;
    tracks: PersistedTrack[];
};

/**
 * The stored pointer — kept small, because it is written far more often than the list
 * (see {@link POSITION_STORAGE_KEY}).
 */
type PersistedPosition = {
    version: number;
    userId: string | null;
    currentIndex: number;
    repeat: boolean;
};

/** Return type of {@link usePlayerQueue}. */
export type UsePlayerQueueReturn = {
    /** The queue in play order. */
    tracks: Ref<QueueTrack[]>;
    /** Index of the loaded track, or -1 when the queue is empty. */
    currentIndex: Ref<number>;
    /** Whether the queue wraps to its first track instead of stopping at the last. */
    repeat: Ref<boolean>;
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
    /** Load the next track, wrapping to the first when repeat is on. Returns false at a hard end. */
    next: () => boolean;
    /** Load the previous track. Returns false at the start of the queue. */
    previous: () => boolean;
    /** Turn wrapping at the end of the queue on or off. */
    toggleRepeat: () => void;
    /** Empty the queue entirely. */
    clear: () => void;
    /** Restore from storage. Called once, by FullLayout — see the function's own note. */
    hydrate: () => void;
};

// Module-level state — every consumer shares this one queue.
const tracks = ref<QueueTrack[]>([]);
const currentIndex = ref<number>(NOTHING);

/**
 * Whether the queue wraps at its end.
 *
 * Queue state rather than player state, which is why it lives here: what happens
 * after the last track is a fact about the LIST, and it has to persist with it —
 * repeat that reset on every reload would be a setting you set twice a day.
 */
const repeat = ref<boolean>(false);

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

/** The song's own page — the same string the pages themselves build when they enqueue. */
function hrefFor(id: string): string {
    return `/music/songs/${id}`;
}

/** Where the player loads the bytes from (SongStreamController's route). */
function streamUrlFor(id: string): string {
    return `/music/songs/${id}/stream`;
}

/** The track's cover art (SongCoverController's route). */
function coverUrlFor(id: string): string {
    return `/music/songs/${id}/cover`;
}

/**
 * Whether `url` is nothing more than this app's own `path`, and so need not be stored.
 *
 * It compares the parsed PATH rather than the raw string, because the props these
 * tracks are built from are inconsistent on purpose: `coverUrl` arrives absolute
 * (Laravel's `route()` default) while `streamUrl` arrives relative
 * (`absolute: false`), and both name the route the id already implies.
 *
 * Anything else — a foreign origin, or a query string, which is exactly what a signed
 * share link is — answers false and gets stored verbatim. That is why this asks
 * instead of trimming blindly: the server owns which URLs exist, so a URL it went out
 * of its way to sign has to survive a reload intact.
 */
function isDerivable(url: string, path: string): boolean {
    try {
        const parsed = new URL(url, window.location.origin);

        return (
            parsed.origin === window.location.origin &&
            parsed.pathname === path &&
            parsed.search === "" &&
            parsed.hash === ""
        );
    } catch {
        return false; // Not a URL this can reason about — keep it as it came.
    }
}

/**
 * Shrink a track for storage, dropping every URL the id can rebuild.
 *
 * The cover collapses to a flag rather than disappearing, because "no cover at all"
 * and "a cover at the usual place" are different facts and the panel draws a
 * placeholder for the first: derived unconditionally, a coverless track would point
 * an `<img>` at a 404 on every reload.
 */
function toPersisted(track: QueueTrack): PersistedTrack {
    const entry: PersistedTrack = {
        id: track.id,
        name: track.name,
        artist: track.artist,
        album: track.album,
        duration: track.duration
    };

    if (track.coverUrl !== null) {
        if (isDerivable(track.coverUrl, coverUrlFor(track.id))) entry.hasCover = true;
        else entry.coverUrl = track.coverUrl;
    }
    if (!isDerivable(track.href, hrefFor(track.id))) entry.href = track.href;
    if (!isDerivable(track.streamUrl, streamUrlFor(track.id))) entry.streamUrl = track.streamUrl;

    return entry;
}

/**
 * Rebuild a full track from its stored form.
 *
 * A derived `coverUrl` comes back ROOT-RELATIVE where the freshly enqueued one was
 * absolute. Same target — an `<img src>` and MediaSession artwork both resolve
 * against the document — and deliberately not re-absolutised: this module has no
 * business minting an origin, and a queue that outlives a domain change should follow
 * the domain it is being read on.
 */
function fromPersisted(entry: PersistedTrack): QueueTrack {
    return {
        id: entry.id,
        name: entry.name,
        artist: entry.artist ?? null,
        album: entry.album ?? null,
        coverUrl: entry.coverUrl ?? (entry.hasCover === true ? coverUrlFor(entry.id) : null),
        duration: entry.duration ?? null,
        href: entry.href ?? hrefFor(entry.id),
        streamUrl: entry.streamUrl ?? streamUrlFor(entry.id)
    };
}

/**
 * Whether a stored entry has the two fields nothing can be rebuilt without.
 *
 * Rows that fail are dropped individually rather than costing the whole queue: the
 * version check already rejects a payload written by a different shape, so anything
 * malformed getting this far is one corrupt row, and losing the other 200 to it would
 * be the worse outcome.
 */
function isPersistedTrack(entry: unknown): entry is PersistedTrack {
    return (
        typeof entry === "object" &&
        entry !== null &&
        typeof (entry as PersistedTrack).id === "string" &&
        typeof (entry as PersistedTrack).name === "string"
    );
}

/**
 * Apply a stored pointer to a list that has just been read.
 *
 * Refused on its own terms — wrong user, older shape, not a number — and refusing it
 * leaves the queue cued at its first track, which is a much better failure than
 * dropping a restored queue over one integer. The caller clamps afterwards, so a
 * pointer left over from a list write that failed cannot load a track that is not
 * there (see {@link POSITION_STORAGE_KEY} on the two keys drifting apart).
 */
function applyPosition(stored: string | null): void {
    if (!stored) return;

    let payload: PersistedPosition;
    try {
        payload = JSON.parse(stored) as PersistedPosition;
    } catch {
        return; // Corrupt pointer — the list it belongs to is still worth having.
    }

    if (payload.version !== PERSISTED_VERSION) return;
    if (payload.userId !== currentUserId()) return;

    if (typeof payload.currentIndex === "number") currentIndex.value = payload.currentIndex;
    repeat.value = payload.repeat === true;
}

/** Which of the two keys a mutation left behind. */
type Persistable = "tracks" | "position";

/** Keys whose stored copy is behind the live state, waiting for the next flush. */
const dirty = new Set<Persistable>();

/** Handle of the pending flush, or null when none is scheduled. */
let writeTimer: ReturnType<typeof setTimeout> | null = null;

/**
 * How long a change may sit unwritten.
 *
 * A trailing-edge timer, not a resetting debounce: it starts on the FIRST dirty mark
 * and later ones do not push it back, so staleness stays bounded by this even while
 * somebody drags rows continuously. Half a second outlasts any burst of clicks and is
 * a fraction of the gap between two songs.
 */
const WRITE_DELAY_MS = 500;

/** Guards the hide listeners against a second binding, since `hydrate` re-runs in tests. */
let flushBound = false;

/** Put one payload in storage, swallowing failure — see {@link flushQueueWrites} for why. */
function writeEntry(key: string, payload: PersistedQueue | PersistedPosition): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(payload));
    } catch {
        // Storage full, or blocked by the browser. The in-memory queue is unaffected.
    }
}

/**
 * Write whatever is dirty, now, and cancel any pending flush.
 *
 * Exported because coalescing is only safe if something guarantees the LAST write:
 * {@link bindFlushOnHide} calls this when the tab goes away, and the tests call it
 * wherever they simulate a reload. When the server sync lands, the POST to
 * `player_states` belongs here — this is the one place that knows what changed.
 *
 * Failures are swallowed per key (a full or disabled storage must not take the player
 * down with it) and the dirty set is cleared regardless: a write that failed for want
 * of room will fail again, and keeping it dirty would only retry it on every
 * subsequent mutation for the rest of the session.
 */
export function flushQueueWrites(): void {
    if (writeTimer !== null) {
        clearTimeout(writeTimer);
        writeTimer = null;
    }
    if (dirty.size === 0) return;

    const userId = currentUserId();

    if (dirty.has("tracks")) {
        writeEntry(STORAGE_KEY, { version: PERSISTED_VERSION, userId, tracks: tracks.value.map(toPersisted) });
    }
    if (dirty.has("position")) {
        writeEntry(POSITION_STORAGE_KEY, {
            version: PERSISTED_VERSION,
            userId,
            currentIndex: currentIndex.value,
            repeat: repeat.value
        });
    }

    dirty.clear();
}

/**
 * Flush when the tab goes away, which is what makes the delay above safe to have.
 *
 * Both events, because they cover different exits: `pagehide` catches navigation and
 * close (and unlike `unload` it does not disqualify the page from the back/forward
 * cache), while `visibilitychange` is the one iOS reliably delivers before it discards
 * a backgrounded tab. Firing both costs nothing — the second finds nothing dirty.
 *
 * It also covers what the timer cannot. A hidden tab has its timers throttled to once
 * a second and, after five minutes, once a minute — and this player keeps playing when
 * the tab is hidden, on purpose. Without these listeners, an auto-advance in a
 * background tab could be minutes from disk when the tab is closed.
 */
function bindFlushOnHide(): void {
    if (flushBound) return;
    flushBound = true;

    window.addEventListener("pagehide", flushQueueWrites);
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "hidden") flushQueueWrites();
    });
}

/**
 * Note what a mutation changed, and make sure it reaches storage eventually.
 *
 * Still the single choke point for persistence, and now the throttle too: it records
 * which key is behind instead of writing, so a mutation costs a set insert and the
 * write happens once, later. That is what makes a long queue cheap to operate — the
 * cost of `next()` no longer scales with how much is queued.
 *
 * Callers say what they touched, and getting it wrong yields silent staleness rather
 * than a crash, so the rule is blunt: anything that changes the LIST passes "tracks",
 * and anything that can move the pointer — which is most list edits, since removing
 * and reordering both carry it — also passes "position".
 */
function commit(...changed: Persistable[]): void {
    changed.forEach(entry => dirty.add(entry));

    if (writeTimer !== null) return;
    writeTimer = setTimeout(flushQueueWrites, WRITE_DELAY_MS);
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
        commit("tracks", "position");
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
        commit("tracks", "position");
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
        commit("tracks");
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
        commit("tracks", "position");
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
        commit("tracks", "position");
    }

    /** Load the track at `index` — a click on a queue row. */
    function jumpTo(index: number): void {
        if (index < 0 || index >= tracks.value.length) return;
        currentIndex.value = index;
        commit("position");
    }

    /**
     * Step forward, wrapping to the first track when repeat is on.
     *
     * The return value is what the player's `ended` handler reads: false means "the
     * queue is genuinely finished, stop", and it is only ever false at the last
     * track with repeat off. Note it returns TRUE for a one-track queue on repeat,
     * where the index does not move — the track is meant to play again, and the
     * caller (usePlayerAudio) is the one that notices the pointer stood still and
     * restarts it, since nothing about the queue changed for a watcher to see.
     */
    function next(): boolean {
        if (currentIndex.value === NOTHING) return false;

        if (currentIndex.value >= tracks.value.length - 1) {
            if (!repeat.value) return false;
            currentIndex.value = 0;
            commit("position");

            return true;
        }

        currentIndex.value += 1;
        commit("position");

        return true;
    }

    /** Step back. False at the start. */
    function previous(): boolean {
        if (currentIndex.value <= 0) return false;
        currentIndex.value -= 1;
        commit("position");

        return true;
    }

    /**
     * Flip wrapping at the end of the queue.
     *
     * Persisted like everything else here, and deliberately NOT reset by `clear()`:
     * "I listen on repeat" is a habit, not a property of the tracks that happen to
     * be queued right now.
     */
    function toggleRepeat(): void {
        repeat.value = !repeat.value;
        commit("position");
    }

    /** Empty the queue, which also puts the footer back in place of the PlayerBar. */
    function clear(): void {
        tracks.value = [];
        currentIndex.value = NOTHING;
        commit("tracks", "position");
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

        // Here rather than at module load for the same reason as the rest of this
        // function: it belongs to a live app, and this is the once-per-app call.
        bindFlushOnHide();

        let storedQueue: string | null = null;
        let storedPosition: string | null = null;
        try {
            storedQueue = window.localStorage.getItem(STORAGE_KEY);
            storedPosition = window.localStorage.getItem(POSITION_STORAGE_KEY);
        } catch {
            return; // Storage unavailable; an in-memory queue still works.
        }
        if (!storedQueue) return;

        let payload: PersistedQueue;
        try {
            payload = JSON.parse(storedQueue) as PersistedQueue;
        } catch {
            return; // Corrupt entry — start clean rather than throw at boot.
        }

        if (payload.version !== PERSISTED_VERSION) return;
        if (payload.userId !== currentUserId()) return;
        if (!Array.isArray(payload.tracks)) return;

        tracks.value = payload.tracks.filter(isPersistedTrack).map(fromPersisted);
        currentIndex.value = tracks.value.length > 0 ? 0 : NOTHING;
        repeat.value = false;
        applyPosition(storedPosition);
        clampIndex();
    }

    return {
        tracks,
        currentIndex,
        repeat,
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
        toggleRepeat,
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
    // The pending write goes with it, and that part is not optional: a flush left
    // scheduled by one spec would fire during the next one and drop this spec's queue
    // into that spec's storage. `flushBound` is deliberately NOT reset — the listeners
    // are on a window that outlives every reset in the file.
    if (writeTimer !== null) {
        clearTimeout(writeTimer);
        writeTimer = null;
    }
    dirty.clear();

    tracks.value = [];
    currentIndex.value = NOTHING;
    repeat.value = false;
    hydrated = false;
}
