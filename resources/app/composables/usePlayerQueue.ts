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
import { announceQueueSaveFailure, noteQueueSaveSucceeded } from "Utils/queueSaveWarning";

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
    /**
     * Whether shuffle is on. Optional, and read tolerantly, so it could be ADDED without
     * bumping the shape: the version is shared with the list payload, so a bump would
     * throw away every stored queue to gain one boolean that defaults to false anyway.
     */
    shuffle?: boolean;
    /**
     * When this browser last changed the queue, by its own clock — the whole of the
     * conflict resolution between this copy and the server's (see {@link adoptServerState}).
     * Optional and read tolerantly, like `shuffle`: a payload written before it existed
     * reads as 0, which simply means "older than anything the server has".
     */
    updatedAt?: number;
    /**
     * How far into the LOADED track the listener had got, in milliseconds.
     *
     * Milliseconds because that is the unit the plan reserved in the row (`position_ms`)
     * and an integer stores smaller than a float of seconds. It belongs to the pointer
     * rather than the list for the obvious reason — it is a fact about the loaded track,
     * and it dies with it.
     */
    positionMs?: number;
};

/** Return type of {@link usePlayerQueue}. */
export type UsePlayerQueueReturn = {
    /** The queue in play order. */
    tracks: Ref<QueueTrack[]>;
    /** Index of the loaded track, or -1 when the queue is empty. */
    currentIndex: Ref<number>;
    /** Whether the queue wraps to its first track instead of stopping at the last. */
    repeat: Ref<boolean>;
    /** Whether the queue plays in a random order instead of the order on screen. */
    shuffle: Ref<boolean>;
    /** The loaded track, or null. Drives whether the PlayerBar replaces the footer. */
    current: ComputedRef<QueueTrack | null>;
    /** True while the queue holds nothing — the PlayQueue panel renders only when this is false. */
    isEmpty: ComputedRef<boolean>;
    /** Total playing time of the queue in seconds, ignoring tracks with no duration. */
    totalDuration: ComputedRef<number>;
    /** Replace the queue with these tracks and load the one at `startIndex` (the first by default). */
    playNow: (tracks: QueueTrack[], startIndex?: number) => void;
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
    /** Whether stepping forward is possible at all — what the transport's next button reads. */
    hasNext: ComputedRef<boolean>;
    /** Whether stepping back is possible at all — what the transport's previous button reads. */
    hasPrevious: ComputedRef<boolean>;
    /** Load the next track, wrapping to the first when repeat is on. Returns false at a hard end. */
    next: () => boolean;
    /** Load the previous track. Returns false when there is nothing behind the loaded one. */
    previous: () => boolean;
    /** Turn wrapping at the end of the queue on or off. */
    toggleRepeat: () => void;
    /** Turn the random play order on or off. */
    toggleShuffle: () => void;
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

/**
 * Whether the queue plays in a random order.
 *
 * A play MODE, not a reordering: the list on screen keeps the order you built, and
 * only the pointer jumps. Shuffling `tracks` itself would be destructive — the order
 * you dragged into place would be gone the moment you tried the mode — and it would
 * make "turn shuffle off again" impossible to honour.
 */
const shuffle = ref<boolean>(false);

/**
 * The ids played since shuffle last started over, in the order they were played, and
 * where in that walk the loaded track sits.
 *
 * A BAG rather than a die roll per track: rolling at random plays the same song twice
 * in ten and is the single most complained-about shuffle behaviour there is. Recording
 * the walk (rather than only a played-set) is what makes the transport's back button
 * mean "the track I heard before this one" under shuffle instead of "the row above",
 * and it lets forward retrace the same path after walking back, the way every music
 * player behaves.
 *
 * Ids rather than indices, because the list is editable underneath it: a remove or a
 * drag renumbers every index in the walk, while an id keeps pointing at its track.
 *
 * INDICES, not ids: the same song may legitimately be queued twice, and an id-keyed
 * walk would treat the two rows as one — marking the second copy played without
 * playing it. The price is that the walk is only valid while the rows keep their
 * numbers, which is why every edit that renumbers them forgets it (see
 * {@link resetShuffleWalk}); appending does not renumber anything, so an append keeps
 * it.
 *
 * NOT persisted, deliberately. It is meaningless once the tab is gone, and coming back
 * to a fresh pass is what a listener expects anyway — the shuffle they left running is
 * not a place in a list. Refs rather than plain variables because `hasNext` reads them:
 * a computed over a bare `let` never re-runs.
 */
const shuffleWalk = ref<number[]>([]);
const shuffleCursor = ref<number>(NOTHING);

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
    // `=== true` rather than a default, so a pointer written before shuffle existed reads
    // as off instead of undefined — which is why adding the field needed no version bump.
    shuffle.value = payload.shuffle === true;
    localUpdatedAt = storedUpdatedAt(stored);
    restoredPosition = typeof payload.positionMs === "number" ? payload.positionMs / 1000 : 0;
}

/**
 * When the stored pointer says this browser last changed the queue.
 *
 * Read on its own, before anything is adopted, because {@link hydrate} has to compare it
 * with what the server offers BEFORE deciding which copy to keep — and by then the pointer
 * may never be applied at all. Anything unreadable answers 0, which means "older than
 * whatever the server has": the safe direction, since a browser that cannot read its own
 * copy has no claim to defend.
 */
function storedUpdatedAt(stored: string | null): number {
    if (!stored) return 0;

    try {
        const payload = JSON.parse(stored) as PersistedPosition;

        if (payload.version !== PERSISTED_VERSION) return 0;
        if (payload.userId !== currentUserId()) return 0;

        return typeof payload.updatedAt === "number" ? payload.updatedAt : 0;
    } catch {
        return 0;
    }
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

/**
 * When this browser last changed the queue, by its own clock.
 *
 * Stamped on every flush, stored with the pointer, and sent with every sync — it is what
 * settles which copy is newer when a page load offers two (see {@link adoptServerState}).
 * Zero until the first change of the session, which is correct: a browser that has changed
 * nothing has nothing to defend.
 */
let localUpdatedAt = 0;

/**
 * Where the play position comes from, or null before the player has an element.
 *
 * THE NUMBER TRAVELS AGAINST THE IMPORTS, which is the whole reason this exists. It lives
 * on the <audio> element, which `usePlayerAudio` owns — and that module imports this one, so
 * it cannot be read from here. A getter registered on attach is the same handshake in
 * reverse that `bindVolumeElement` and `bindSpeedElement` already are, and it keeps ONE
 * writer for the stored payload: the alternative, a player that persists its own key, means
 * two modules writing one server row.
 */
let readPosition: (() => number) | null = null;

/** The position last written, in seconds — what {@link notePlaybackProgress} compares against. */
let writtenPosition = 0;

/**
 * The position a restored queue came back with, in seconds, waiting to be applied ONCE.
 *
 * A one-shot rather than a ref, because it is only ever true of the first track loaded after
 * a page load: every later `load()` is a new track starting at zero, and a value left lying
 * around would eventually seek one of them to a stranger's minute mark.
 */
let restoredPosition = 0;

/**
 * Register (or drop) the player's position getter — see {@link readPosition}.
 *
 * Called by `usePlayerAudio.attach()` with the element's own reading, and with null on
 * detach so this module never holds a closure over a node that has left the document.
 */
export function bindPositionSource(source: (() => number) | null): void {
    readPosition = source;
    if (!source) writtenPosition = 0;
}

/**
 * Take the restored position, once.
 *
 * Reading it CLEARS it, which is the contract: the player asks as it loads the hydrated
 * queue's track and gets a real answer exactly that once.
 */
export function takeRestoredPosition(): number {
    const seconds = restoredPosition;
    restoredPosition = 0;

    return seconds;
}

/**
 * How far the position must move before it is worth another write, in seconds.
 *
 * Not a cadence — the player decides when to ASK (every 30s of playback, on a pause, on the
 * way out) — but a floor under those asks, so a pause and a tab switch a second apart do not
 * cost two writes and two requests for the same instant.
 */
const POSITION_STEP_SECONDS = 1;

/**
 * Note that playback has moved, and mark the pointer for writing if it has moved enough.
 *
 * The rule ("has it moved?") lives here because the stored payload does; the CADENCE lives
 * in the player, which is the only thing that knows whether audio is running. Callers that
 * need it on disk immediately follow this with {@link flushQueueWrites}.
 */
export function notePlaybackProgress(): void {
    if (!readPosition) return;

    const seconds = readPosition();

    if (Math.abs(seconds - writtenPosition) < POSITION_STEP_SECONDS) return;

    commit("position");
}

/**
 * Put one payload in storage, and say whether it landed.
 *
 * Still swallows the exception — a full or disabled storage must not take the player down —
 * but no longer swallows the FACT. The caller reports it once (see
 * Utils/queueSaveWarning): the queue on screen is still correct and still playing, and the
 * only thing at risk is whether it comes back tomorrow, which is exactly the kind of thing a
 * listener should be told rather than discover.
 */
function writeEntry(key: string, payload: PersistedQueue | PersistedPosition): boolean {
    try {
        window.localStorage.setItem(key, JSON.stringify(payload));

        return true;
    } catch {
        // Storage full, or blocked by the browser. The in-memory queue is unaffected.
        return false;
    }
}

/** Where the queue is synced for a signed-in user (PlayerStateController). */
const SYNC_URL = "/player/state";

/**
 * Push the queue to the server, for a signed-in user.
 *
 * IDS ONLY. The server has the tracks — it is where they came from — so a title sent up
 * here would only be a copy to go stale, and a queue of thousands stays a few tens of
 * kilobytes rather than megabytes. What comes BACK is the full shape, because the browser
 * has no REST API to look ids up with; PlayerStatePayload owns that asymmetry.
 *
 * A PLAIN fetch, NOT AN INERTIA VISIT, and that is the whole reason this can fire on every
 * track change: a visit would re-render a page nobody asked for and hand back props the
 * player would have to ignore. This answers 204. Inertia's own visits carry the CSRF token
 * themselves, so this is the one place that has to send it by hand — off the shared prop.
 *
 * `keepalive` ONLY WHEN THE TAB IS GOING AWAY, because the flag comes with a 64 KB body
 * limit — about 1,700 ids. An ordinary flush has a live page to complete in and wants no
 * limit; a flush from `pagehide` has no page left, and for a queue past that size the
 * request is dropped by the browser. What survives it is the write half a second earlier
 * that the timer already made, and localStorage, which is always written first.
 *
 * Failure is swallowed, exactly as the storage write is: offline, logged out in another
 * tab, a 419 after a session rotation. The local copy is the source of truth for the
 * session either way, and a player that broke because a sync failed would be a worse bug
 * than a queue that is one change behind on another device.
 */
function syncToServer(unloading: boolean): void {
    if (!currentUserId()) return;

    try {
        void fetch(SYNC_URL, {
            method: "PUT",
            keepalive: unloading,
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": usePage().props.csrfToken ?? ""
            },
            body: JSON.stringify({
                tracks: tracks.value.map(track => track.id),
                currentIndex: currentIndex.value,
                repeat: repeat.value,
                shuffle: shuffle.value,
                updatedAt: localUpdatedAt,
                positionMs: Math.round(writtenPosition * 1000)
            })
        })
            .then(response => {
                /*
                 * A REFUSAL IS NOT ONLY A THROWN ERROR: a 419 after a session rotation, a
                 * 422 from a shape this build no longer sends, a 500 — all resolve happily
                 * and store nothing. Checking the status is the difference between "the
                 * queue is on the server" and "the request left the building".
                 *
                 * NOT ON THE WAY OUT, though. `unloading` means the tab is closing, and a
                 * toast raised into a page that is being torn down is one nobody can read;
                 * the local copy is written either way, and the next flush on the next
                 * visit will say so if the server is still refusing.
                 */
                if (response.ok) {
                    noteQueueSaveSucceeded("server");
                } else if (!unloading) {
                    announceQueueSaveFailure("server");
                }
            })
            .catch(() => {
                // Offline, or the request never left. Playback is untouched; only whether
                // this queue follows the listener to another device is at stake.
                if (!unloading) announceQueueSaveFailure("server");
            });
    } catch {
        // `fetch` itself missing (a very old WebView) — the queue still works locally.
    }
}

/**
 * Write whatever is dirty, now, and cancel any pending flush.
 *
 * Exported because coalescing is only safe if something guarantees the LAST write:
 * {@link bindFlushOnHide} calls this when the tab goes away, and the tests call it
 * wherever they simulate a reload.
 *
 * IT IS ALSO WHERE THE SERVER SYNC GOES, for the reason it was always going to: this is
 * the one place that knows something changed and that it has settled. The two writes ride
 * the same coalescing, so a burst — two enqueues and a drag — costs one PUT, and a track
 * change costs one whether the queue holds three tracks or three thousand.
 *
 * LOCAL FIRST, ALWAYS. The browser's copy is what the next page load falls back on when
 * the network is not there, and it is the only copy a guest has at all.
 *
 * Failures are swallowed per key (a full or disabled storage must not take the player
 * down with it) and the dirty set is cleared regardless: a write that failed for want
 * of room will fail again, and keeping it dirty would only retry it on every
 * subsequent mutation for the rest of the session.
 *
 * @param unloading true when the tab is going away — see {@link syncToServer} on what
 *                  that changes about the request
 */
export function flushQueueWrites(unloading = false): void {
    if (writeTimer !== null) {
        clearTimeout(writeTimer);
        writeTimer = null;
    }
    if (dirty.size === 0) return;

    const userId = currentUserId();
    localUpdatedAt = Date.now();

    let stored = true;

    if (dirty.has("tracks")) {
        stored = writeEntry(STORAGE_KEY, { version: PERSISTED_VERSION, userId, tracks: tracks.value.map(toPersisted) });
    }
    // The POINTER IS WRITTEN ON EVERY FLUSH, even one that only touched the list, because
    // it is where the stamp lives and the stamp has to be as new as the newest change. It
    // costs ~110 characters against the list's megabytes, so the split this key exists for
    // — never rewriting the LIST for a track change — is untouched.
    writtenPosition = readPosition?.() ?? 0;
    stored = writeEntry(POSITION_STORAGE_KEY, {
        version: PERSISTED_VERSION,
        userId,
        currentIndex: currentIndex.value,
        repeat: repeat.value,
        shuffle: shuffle.value,
        updatedAt: localUpdatedAt,
        positionMs: Math.round(writtenPosition * 1000)
    }) && stored;

    // Once per failure, and reset by the next write that works — a browser that started
    // refusing has usually stopped for good, and one that recovers is worth hearing from
    // again if it fails a second time.
    if (stored) {
        noteQueueSaveSucceeded("browser");
    } else {
        announceQueueSaveFailure("browser");
    }

    dirty.clear();

    // One PUT for whatever changed, list or pointer — the server row is written wholesale,
    // so there is nothing finer to tell it.
    syncToServer(unloading);
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

    // Wrapped rather than passed by reference, and not for tidiness: the listener would
    // otherwise hand the Event object in as `unloading`, which is truthy — the right answer
    // here by accident, and the wrong one the day this signature grows.
    // NOTED BEFORE FLUSHED, and that order is the point: a tab going away while a track
    // plays has usually moved since the last heartbeat, and a flush finds nothing dirty
    // unless something says so. Without this, closing the tab mid-song stores the position
    // from up to thirty seconds earlier.
    const flushOnHide = (): void => {
        notePlaybackProgress();
        flushQueueWrites(true);
    };

    window.addEventListener("pagehide", flushOnHide);
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "hidden") flushOnHide();
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
 * Forget the shuffle walk.
 *
 * Called by every edit that RENUMBERS rows — remove, reorder, playNext's insert, a
 * wholesale replace — because the walk records positions and a renumbered position
 * points at a different song. An append is deliberately not in that list: it changes no
 * existing index, so the pass keeps its memory and the new arrivals simply join the
 * pool of tracks not yet played.
 */
function resetShuffleWalk(): void {
    shuffleWalk.value = [];
    shuffleCursor.value = NOTHING;
}

/**
 * Record the loaded row as the newest step of the shuffle walk.
 *
 * Anything ahead of the cursor is dropped first, because a deliberate pick — a click in
 * the panel, a fresh queue — is a new branch: retracing forward after it should follow
 * what actually played, not the path abandoned when the listener jumped somewhere else.
 *
 * A no-op while shuffle is off, so the ordinary index-stepping path carries no
 * bookkeeping at all; `toggleShuffle` seeds the walk when the mode comes on.
 */
function noteShuffleStep(index: number): void {
    if (!shuffle.value || index === NOTHING) return;

    shuffleWalk.value = [...shuffleWalk.value.slice(0, shuffleCursor.value + 1), index];
    shuffleCursor.value = shuffleWalk.value.length - 1;
}

/** Rows not yet played in this shuffle pass — the pool `next()` draws from. */
function unplayedIndices(): number[] {
    const played = new Set(shuffleWalk.value);

    return tracks.value.map((_, index) => index).filter(index => !played.has(index));
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

    /**
     * Whether stepping forward is possible.
     *
     * Read by the transport's next button, and here rather than in `PlayerBar` because
     * under shuffle the answer is not derivable from the index alone: it depends on the
     * walk, which is this module's private state.
     *
     * With repeat on the answer is always yes for a non-empty queue — the last track's
     * "next" is the first one — or the queue would wrap on its own at the end of a track
     * while the button sat there disabled.
     */
    const hasNext = computed<boolean>(() => {
        if (currentIndex.value === NOTHING) return false;
        if (!shuffle.value) return repeat.value || currentIndex.value < tracks.value.length - 1;

        const canRetrace = shuffleCursor.value > NOTHING && shuffleCursor.value < shuffleWalk.value.length - 1;

        return canRetrace || unplayedIndices().length > 0 || repeat.value;
    });

    /**
     * Whether stepping back is possible.
     *
     * Under shuffle that means "is there a track I heard before this one", which is the
     * walk's cursor — NOT the row above, which under a random order is a song that has
     * probably not played at all.
     */
    const hasPrevious = computed<boolean>(() =>
        shuffle.value ? shuffleCursor.value > 0 : currentIndex.value > 0
    );

    /**
     * Replace the queue wholesale and load one of its tracks — the "play this album now"
     * operation.
     *
     * `startIndex` is what makes a PLAYLIST row work: pressing play on the fourth entry means
     * "queue the whole list and start there", not "queue this one song". Defaulted to 0, so
     * every caller that means "from the top" (the hero menus) reads exactly as before.
     *
     * Clamped rather than trusted, because the alternative is silent: an index past the end
     * would leave the pointer at a row that does not exist, and the player would sit there
     * with a full queue and nothing loaded.
     */
    function playNow(next: QueueTrack[], startIndex = 0): void {
        tracks.value = [...next];
        currentIndex.value = next.length > 0 ? Math.min(Math.max(startIndex, 0), next.length - 1) : NOTHING;
        resetShuffleWalk();
        noteShuffleStep(currentIndex.value);
        commit("tracks", "position");
    }

    /**
     * Append to the end of the queue.
     *
     * Loading the first arrival when nothing was loaded is what makes a single
     * enqueue useful: without it the PlayerBar would have a queue and no track to
     * show, and the reader would have to click the row they just queued.
     *
     * The shuffle walk is left alone on purpose — an append renumbers nothing, so the
     * pass keeps what it has already played and the arrivals just join the pool.
     */
    function enqueue(input: QueueTrack | QueueTrack[]): void {
        const wasEmpty = currentIndex.value === NOTHING;
        tracks.value = [...tracks.value, ...asList(input)];
        if (wasEmpty) {
            currentIndex.value = 0;
            noteShuffleStep(0);
        }
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
        // An insert shifts every row below it, so the walk's recorded positions now name
        // different songs — see resetShuffleWalk. The loaded row keeps its number, so it
        // becomes step one of the new pass.
        resetShuffleWalk();
        noteShuffleStep(currentIndex.value);
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
        resetShuffleWalk();
        noteShuffleStep(currentIndex.value);
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
        resetShuffleWalk();
        noteShuffleStep(currentIndex.value);
        commit("tracks", "position");
    }

    /**
     * Load the track at `index` — a click on a queue row.
     *
     * Under shuffle this is a deliberate branch, so it becomes the newest step of the
     * walk: back then goes to the track you were listening to, and forward retraces from
     * here rather than resuming the path you abandoned.
     */
    function jumpTo(index: number): void {
        if (index < 0 || index >= tracks.value.length) return;
        currentIndex.value = index;
        noteShuffleStep(index);
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
        if (shuffle.value) return shuffledNext();

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

    /**
     * Step forward through the shuffle walk.
     *
     * Three cases in order of precedence. Ahead of the cursor there may be a path already
     * played (the listener stepped back); that is retraced rather than re-rolled, because
     * a back-then-forward that landed somewhere new would make the two buttons feel
     * broken. Otherwise a row is drawn at random from those not yet played this pass. When
     * nothing is left, repeat starts a new pass and anything else reports the queue
     * finished — the same contract the ordinary path has.
     *
     * The new pass excludes the track that just ended wherever the queue holds another, so
     * a wrap does not immediately replay it. That also means the walk no longer holds what
     * came before the wrap, so `previous` cannot step back across it: a pass boundary is
     * where the history honestly ends.
     */
    function shuffledNext(): boolean {
        if (shuffleCursor.value > NOTHING && shuffleCursor.value < shuffleWalk.value.length - 1) {
            shuffleCursor.value += 1;
            currentIndex.value = shuffleWalk.value[shuffleCursor.value];
            commit("position");

            return true;
        }

        let candidates = unplayedIndices();

        if (candidates.length === 0) {
            if (!repeat.value) return false;

            resetShuffleWalk();
            const others = unplayedIndices().filter(index => index !== currentIndex.value);
            candidates = others.length > 0 ? others : unplayedIndices();
        }
        if (candidates.length === 0) return false;

        const pick = candidates[Math.floor(Math.random() * candidates.length)];
        currentIndex.value = pick;
        noteShuffleStep(pick);
        commit("position");

        return true;
    }

    /**
     * Step back — to the row above, or under shuffle to the track actually heard before
     * this one. False when there is nothing behind the loaded track either way.
     */
    function previous(): boolean {
        if (shuffle.value) {
            if (shuffleCursor.value <= 0) return false;
            shuffleCursor.value -= 1;
            currentIndex.value = shuffleWalk.value[shuffleCursor.value];
            commit("position");

            return true;
        }

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

    /**
     * Flip the random play order.
     *
     * Either direction starts a fresh walk, and the loaded track becomes its first step:
     * switching the mode on should not immediately replay what is already playing, and
     * switching it off and on again should not resume a pass from an hour ago. A habit
     * like repeat, so it persists and survives `clear()` too.
     */
    function toggleShuffle(): void {
        shuffle.value = !shuffle.value;
        resetShuffleWalk();
        noteShuffleStep(currentIndex.value);
        commit("position");
    }

    /** Empty the queue, which also puts the footer back in place of the PlayerBar. */
    function clear(): void {
        tracks.value = [];
        currentIndex.value = NOTHING;
        resetShuffleWalk();
        commit("tracks", "position");
    }

    /**
     * Adopt the queue the server sent with this page load.
     *
     * THE SERVER WINS WHEN IT HAS ONE, which is the whole point of syncing: the queue you
     * left on the laptop is what should greet you on the phone, and every local change was
     * pushed up as it happened, so the stored copy is this browser's own last word too.
     * The case that loses is a change made while offline, which the failed PUT never
     * carried — last-write-wins across devices is what the plan chose at this scale, and
     * that is the shape of it.
     *
     * A null prop is NOT an empty queue (PlayerStatePayload is careful about the
     * difference): it means "nothing stored", and the local copy is used instead.
     *
     * The adopted queue is written straight to localStorage rather than marked dirty,
     * because it did not change — marking it would send it back where it came from. That
     * write is what keeps the offline fallback in step with what was just restored.
     */
    function adoptServerState(localStamp: number): boolean {
        const payload = usePage().props.playerState;

        if (!payload || !Array.isArray(payload.tracks) || payload.tracks.length === 0) return false;

        /*
         * LAST WRITE WINS, AND THE STAMP IS WHAT SAYS WHICH. Without this the server copy
         * won unconditionally — which loses a change made moments before a navigation, since
         * the PUT and the next page's HTML are two requests racing: enqueue, click a link,
         * and the page comes back holding the queue as it was BEFORE the enqueue. Found by
         * the E2E suite doing exactly that (2026-08-07).
         *
         * Both numbers are wall clocks, so a device with a badly wrong clock can win or lose
         * an argument it should not. That is the trade data-model.md accepted for this scale,
         * and the alternative — a revision counter per row, reconciled on every write — is a
         * lot of machinery for a family's worth of listening.
         */
        if (payload.updatedAt <= localStamp) return false;

        tracks.value = payload.tracks;
        currentIndex.value = payload.currentIndex;
        repeat.value = payload.repeat === true;
        shuffle.value = payload.shuffle === true;
        restoredPosition = payload.positionMs / 1000;
        clampIndex();

        const userId = currentUserId();
        writeEntry(STORAGE_KEY, { version: PERSISTED_VERSION, userId, tracks: tracks.value.map(toPersisted) });
        writeEntry(POSITION_STORAGE_KEY, {
            version: PERSISTED_VERSION,
            userId,
            currentIndex: currentIndex.value,
            repeat: repeat.value,
            shuffle: shuffle.value,
            updatedAt: payload.updatedAt,
            positionMs: payload.positionMs
        });

        return true;
    }

    /**
     * Restore the queue, once — from the server if it has one, otherwise from storage.
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
            // Storage unavailable. The server's copy is still worth having, and an
            // in-memory queue works without either.
        }

        // Read BEFORE anything is adopted: the stamp is the input to the decision below,
        // and a queue restored from the server overwrites the local one it is compared with.
        if (adoptServerState(storedUpdatedAt(storedPosition))) {
            // A restored queue starts a fresh shuffle pass, whichever copy it came from.
            resetShuffleWalk();
            noteShuffleStep(currentIndex.value);

            return;
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
        shuffle.value = false;
        applyPosition(storedPosition);
        clampIndex();
        // A restored queue starts a fresh pass, with whatever it came back on as step one
        // — the walk is not persisted (see its note).
        resetShuffleWalk();
        noteShuffleStep(currentIndex.value);
    }

    return {
        tracks,
        currentIndex,
        repeat,
        shuffle,
        current,
        isEmpty,
        totalDuration,
        hasNext,
        hasPrevious,
        playNow,
        enqueue,
        playNext,
        remove,
        reorder,
        jumpTo,
        next,
        previous,
        toggleRepeat,
        toggleShuffle,
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
    localUpdatedAt = 0;
    readPosition = null;
    writtenPosition = 0;
    restoredPosition = 0;

    tracks.value = [];
    currentIndex.value = NOTHING;
    repeat.value = false;
    shuffle.value = false;
    resetShuffleWalk();
    hydrated = false;
}
