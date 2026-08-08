import { beforeEach, describe, expect, it, vi } from "vitest";
import { setupI18n } from "@/i18n";
import de from "@/lang/de.json";
import type { QueueTrack } from "Composables/usePlayerQueue";
import {
    bindPositionSource,
    flushQueueWrites,
    notePlaybackProgress,
    resetPlayerQueueForTests,
    takeRestoredPosition,
    usePlayerQueue
} from "Composables/usePlayerQueue";
import { useToast } from "Composables/useToast";
import { resetInertia, setPage } from "Testing/inertia";
import { resetQueueSaveWarningsForTests } from "Utils/queueSaveWarning";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The queue is the one piece of player state that outlives both the page and the tab, so
 * these cover the two things that are easy to get subtly wrong and invisible when they are.
 *
 * THE POINTER. Every operation that changes the list has to leave the player on the same
 * SONG, not the same index. Remove a track above the one playing, or drag one past it, and
 * an index-preserving implementation silently switches what is loaded — while looking
 * perfectly correct in the panel.
 *
 * WHOSE QUEUE IT IS. This instance is shared with family and friends, so one browser sees
 * several accounts. A stored queue carries the user it belongs to and is discarded rather
 * than adopted when that does not match, which is the difference between "resume where I
 * left off" and "here is somebody else's listening".
 */

/** A queue track with just enough shape to be identifiable in an assertion. */
const track = (id: string, name = `Track ${id}`): QueueTrack => ({
    id,
    name,
    artist: "Radiohead",
    album: "OK Computer",
    coverUrl: null,
    duration: 100,
    href: `/music/songs/${id}`,
    streamUrl: `/music/songs/${id}/stream`
});

/** Sign in as a given user id (null = a guest arriving on a share link). */
const signedInAs = (id: string | null, playerState: unknown = null) =>
    setPage({
        props: {
            auth: { user: id ? { id, name: "Ash", email: "a@b.c" } : null },
            csrfToken: "test-token",
            playerState
        }
    });

/**
 * The sync PUT, stubbed.
 *
 * MANDATORY, not a nicety: happy-dom's `fetch` is real enough to try, so without this every
 * flush in this file opens a request to a server that is not there — which surfaces as
 * AbortError noise from the teardown rather than as a failure, and slows the file down for
 * nothing. Stubbed as a resolved 204, the shape the controller really answers with.
 */
// Typed with the arguments it receives, so `mock.calls` can be read without casting.
const fetchMock = vi.fn<(url: string, init: RequestInit) => Promise<Response>>(() =>
    Promise.resolve(new Response(null, { status: 204 }))
);

/** The bodies of every sync PUT so far, parsed. */
const syncedBodies = () => fetchMock.mock.calls.map(([, init]) => JSON.parse(init.body as string));

/** The ids currently in the queue, in play order. */
const ids = () => usePlayerQueue().tracks.value.map(entry => entry.id);

/**
 * The stored list, exactly as it sits in storage.
 *
 * Read raw rather than through `hydrate`, because the trimmed shape is a contract of its
 * own: a round-trip alone would still pass if every dropped field were quietly stored.
 * Writes are coalesced, so a caller wanting the LATEST state flushes first — deliberately
 * not done in here, since some of these specs are about when the write happens.
 */
const stored = () => JSON.parse(window.localStorage.getItem("mixtape.queue") ?? "null");

/** The stored pointer, which lives under its own key so a track change stays cheap. */
const storedPosition = () => JSON.parse(window.localStorage.getItem("mixtape.queue.position") ?? "null");

/**
 * Simulate the tab going away and the module being re-imported.
 *
 * The flush is the part that matters: writes are coalesced now, so a reload only ever sees
 * what something actually put in storage — in the app, the `pagehide` listener.
 */
const closeTab = () => {
    flushQueueWrites();
    resetPlayerQueueForTests();
};

describe("usePlayerQueue", () => {
    beforeEach(() => {
        // Nothing here restores spies by default, and `vi.spyOn` hands back the spy that is
        // ALREADY installed rather than a fresh one — so a `setItem` spy in one spec silently
        // arrives pre-loaded with the previous spec's writes. Reset before the queue, since
        // resetting the queue writes nothing but clearing storage below must be real.
        vi.restoreAllMocks();
        resetInertia();
        // The save warnings translate through the i18n singleton and latch per target, so
        // both have to be drained or one spec's toast silences the next one's.
        setupI18n({ legacy: false, locale: "de", messages: { de } });
        resetQueueSaveWarningsForTests();
        // Toasts are a module singleton as well; a warning left standing counts twice.
        useToast().activeToasts.value.forEach(toast => useToast().removeToast(toast.id));
        resetPlayerQueueForTests();
        window.localStorage.clear();
        fetchMock.mockClear();
        vi.stubGlobal("fetch", fetchMock);
        signedInAs("user-1");
    });

    describe("building the queue", () => {
        it("starts empty, with nothing loaded", () => {
            const queue = usePlayerQueue();

            expect(queue.isEmpty.value).toBe(true);
            expect(queue.current.value).toBeNull();
            expect(queue.currentIndex.value).toBe(-1);
        });

        it("loads the first arrival, so one enqueue is enough to show the player", () => {
            // Without this the PlayerBar would have a queue and no track to put in it,
            // and the reader would have to click the row they just queued.
            const queue = usePlayerQueue();

            queue.enqueue(track("a"));

            expect(queue.current.value?.id).toBe("a");
        });

        it("appends without disturbing what is already loaded", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a"), track("b")]);
            queue.jumpTo(1);
            queue.enqueue(track("c"));

            expect(ids()).toStrictEqual(["a", "b", "c"]);
            expect(queue.current.value?.id).toBe("b");
        });

        it("replaces the queue wholesale on playNow", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a"), track("b")]);
            queue.playNow([track("c")]);

            expect(ids()).toStrictEqual(["c"]);
            expect(queue.current.value?.id).toBe("c");
        });

        it("slots playNext directly after the loaded track", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a"), track("b"), track("c")]);
            queue.jumpTo(1);
            queue.playNext(track("x"));

            expect(ids()).toStrictEqual(["a", "b", "x", "c"]);
            expect(queue.current.value?.id).toBe("b");
        });

        it("falls back to appending when playNext has nothing to follow", () => {
            const queue = usePlayerQueue();

            queue.playNext(track("a"));

            expect(ids()).toStrictEqual(["a"]);
            expect(queue.current.value?.id).toBe("a");
        });

        it("totals the queue's playing time, ignoring untagged durations", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a"), { ...track("b"), duration: null }, track("c")]);

            expect(queue.totalDuration.value).toBe(200);
        });
    });

    describe("keeping the player on the same song", () => {
        it("follows the loaded track when one above it is removed", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a"), track("b"), track("c")]);
            queue.jumpTo(2);
            queue.remove(0);

            expect(queue.current.value?.id).toBe("c");
            expect(queue.currentIndex.value).toBe(1);
        });

        it("leaves the pointer alone when one below it is removed", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a"), track("b"), track("c")]);
            queue.jumpTo(0);
            queue.remove(2);

            expect(queue.current.value?.id).toBe("a");
        });

        it("steps back onto the last track when the loaded one is removed off the end", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a"), track("b")]);
            queue.jumpTo(1);
            queue.remove(1);

            expect(queue.current.value?.id).toBe("a");
        });

        it("follows the loaded track through a reorder", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a"), track("b"), track("c")]);
            queue.jumpTo(0);
            queue.reorder(0, 2);

            expect(ids()).toStrictEqual(["b", "c", "a"]);
            expect(queue.current.value?.id).toBe("a");
            expect(queue.currentIndex.value).toBe(2);
        });

        it("empties back to nothing loaded, which puts the footer back", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a"), track("b")]);
            queue.clear();

            expect(queue.isEmpty.value).toBe(true);
            expect(queue.current.value).toBeNull();
        });
    });

    describe("stepping through it", () => {
        it("advances and reports that it did", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a"), track("b")]);

            expect(queue.next()).toBe(true);
            expect(queue.current.value?.id).toBe("b");
        });

        it("refuses to advance past the end, which is what stops the player", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a")]);

            expect(queue.next()).toBe(false);
            expect(queue.current.value?.id).toBe("a");
        });

        it("refuses to step back before the start", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a")]);

            expect(queue.previous()).toBe(false);
        });
    });

    describe("shuffling", () => {
        /*
         * Shuffle is a play MODE: the list keeps the order it was built in and only the
         * pointer jumps. Three things about it are worth pinning, because each is a
         * complaint a listener would make rather than a crash a type would catch — a song
         * playing twice before the others have had a turn, a back button that goes to the
         * row above instead of the track just heard, and a walk that survives an edit and
         * starts naming the wrong rows.
         *
         * `Math.random` is stubbed per assertion rather than seeded: what matters is WHICH
         * pool the pick came from, and a stub that always takes the first candidate makes
         * that observable.
         */

        /** Always pick the first remaining candidate, so a random draw becomes an assertable one. */
        const alwaysFirst = () => vi.spyOn(Math, "random").mockReturnValue(0);

        it("is off to begin with, so a queue plays in the order you built it", () => {
            expect(usePlayerQueue().shuffle.value).toBe(false);
        });

        it("plays every track once before any of them twice", () => {
            // The whole reason there is a walk rather than a die roll per track.
            alwaysFirst();
            const queue = usePlayerQueue();
            queue.enqueue([track("a"), track("b"), track("c")]);
            queue.toggleShuffle();

            const heard = [queue.current.value?.id];
            while (queue.next()) heard.push(queue.current.value?.id);

            expect([...heard].sort()).toStrictEqual(["a", "b", "c"]);
        });

        it("stops once the pass is done, with repeat off", () => {
            alwaysFirst();
            const queue = usePlayerQueue();
            queue.enqueue([track("a"), track("b")]);
            queue.toggleShuffle();

            expect(queue.next()).toBe(true);
            expect(queue.next()).toBe(false);
        });

        it("starts a new pass on repeat, without replaying the track that just ended", () => {
            alwaysFirst();
            const queue = usePlayerQueue();
            queue.enqueue([track("a"), track("b")]);
            queue.toggleShuffle();
            queue.toggleRepeat();

            const first = queue.current.value?.id;
            queue.next();
            const second = queue.current.value?.id;

            // The pass is exhausted here; the wrap must not hand back `second`.
            expect(queue.next()).toBe(true);
            expect(queue.current.value?.id).toBe(first);
            expect(queue.current.value?.id).not.toBe(second);
        });

        it("steps back to the track actually heard before this one, not the row above", () => {
            // The difference that makes the back button honest under a random order.
            alwaysFirst();
            const queue = usePlayerQueue();
            queue.enqueue([track("a"), track("b"), track("c")]);
            queue.toggleShuffle();
            queue.jumpTo(2);
            queue.next();

            const heard = queue.current.value?.id;

            expect(queue.previous()).toBe(true);
            expect(queue.current.value?.id).toBe("c");
            expect(queue.current.value?.id).not.toBe(heard);
        });

        it("retraces forward over the path already played", () => {
            // Back then forward has to land where it was, or both buttons feel broken.
            alwaysFirst();
            const queue = usePlayerQueue();
            queue.enqueue([track("a"), track("b"), track("c")]);
            queue.toggleShuffle();
            queue.next();
            const second = queue.current.value?.id;
            queue.previous();

            expect(queue.next()).toBe(true);
            expect(queue.current.value?.id).toBe(second);
        });

        it("has nothing behind the first track of a pass", () => {
            alwaysFirst();
            const queue = usePlayerQueue();
            queue.enqueue([track("a"), track("b")]);
            queue.toggleShuffle();

            expect(queue.hasPrevious.value).toBe(false);
            expect(queue.previous()).toBe(false);
        });

        it("tells the transport when the pass is finished", () => {
            alwaysFirst();
            const queue = usePlayerQueue();
            queue.enqueue([track("a"), track("b")]);
            queue.toggleShuffle();

            expect(queue.hasNext.value).toBe(true);

            queue.next();

            expect(queue.hasNext.value).toBe(false);

            queue.toggleRepeat();

            // Repeat means there is always a next, the same as it does in order.
            expect(queue.hasNext.value).toBe(true);
        });

        it("forgets the pass when an edit renumbers the rows", () => {
            // The walk records POSITIONS, so a remove would otherwise leave it naming
            // tracks that have shifted under it — the second-worst kind of bug, since
            // everything still plays and just plays the wrong thing.
            alwaysFirst();
            const queue = usePlayerQueue();
            queue.enqueue([track("a"), track("b"), track("c")]);
            queue.toggleShuffle();
            queue.next(); // one row now played besides the loaded one
            queue.remove(0);

            // A fresh pass over what is left: two rows, so exactly one step remains.
            expect(queue.next()).toBe(true);
            expect(queue.next()).toBe(false);
        });

        it("keeps the pass across an append, which renumbers nothing", () => {
            alwaysFirst();
            const queue = usePlayerQueue();
            queue.enqueue([track("a"), track("b")]);
            queue.toggleShuffle();
            queue.next(); // both rows played
            queue.enqueue(track("c"));

            // Only the newcomer is left to play, and then the pass really is done.
            expect(queue.next()).toBe(true);
            expect(queue.current.value?.id).toBe("c");
            expect(queue.next()).toBe(false);
        });

        it("survives the tab closing, but comes back on a fresh pass", () => {
            const queue = usePlayerQueue();
            queue.enqueue([track("a"), track("b")]);
            queue.toggleShuffle();

            closeTab();
            usePlayerQueue().hydrate();

            expect(usePlayerQueue().shuffle.value).toBe(true);
            // The walk is not stored, so the restored track is step one and there is
            // nothing behind it — see the walk's note on why that is the right cost.
            expect(usePlayerQueue().hasPrevious.value).toBe(false);
        });

        it("reads a pointer written before shuffle existed as off", () => {
            // Why adding the field needed no version bump: the old shape simply lacks it.
            window.localStorage.setItem(
                "mixtape.queue",
                JSON.stringify({
                    version: 3,
                    userId: "user-1",
                    tracks: [{ id: "a", name: "Track a", artist: null, album: null, duration: null }]
                })
            );
            window.localStorage.setItem(
                "mixtape.queue.position",
                JSON.stringify({ version: 3, userId: "user-1", currentIndex: 0, repeat: true })
            );

            usePlayerQueue().hydrate();

            expect(usePlayerQueue().repeat.value).toBe(true);
            expect(usePlayerQueue().shuffle.value).toBe(false);
        });
    });

    describe("repeating", () => {
        it("is off to begin with, so a queue ends where it ends", () => {
            expect(usePlayerQueue().repeat.value).toBe(false);
        });

        it("wraps to the first track instead of stopping at the last", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a"), track("b")]);
            queue.jumpTo(1);
            queue.toggleRepeat();

            expect(queue.next()).toBe(true);
            expect(queue.current.value?.id).toBe("a");
        });

        it("reports success on a one-track queue without moving the pointer", () => {
            // The case usePlayerAudio has to special-case: nothing about the queue
            // changed, so no watcher fires, yet the track is meant to play again — the
            // player notices the index stood still and restarts it itself.
            const queue = usePlayerQueue();

            queue.enqueue([track("a")]);
            queue.toggleRepeat();

            expect(queue.next()).toBe(true);
            expect(queue.currentIndex.value).toBe(0);
        });

        it("flips back off, and stops at the end again", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a")]);
            queue.toggleRepeat();
            queue.toggleRepeat();

            expect(queue.repeat.value).toBe(false);
            expect(queue.next()).toBe(false);
        });

        it("survives the tab closing, because it is a habit rather than a track", () => {
            usePlayerQueue().enqueue([track("a")]);
            usePlayerQueue().toggleRepeat();

            closeTab();
            usePlayerQueue().hydrate();

            expect(usePlayerQueue().repeat.value).toBe(true);
        });

        it("outlives clearing the queue, for the same reason", () => {
            const queue = usePlayerQueue();

            queue.enqueue([track("a")]);
            queue.toggleRepeat();
            queue.clear();

            expect(queue.repeat.value).toBe(true);
        });
    });

    describe("surviving the tab closing", () => {
        it("restores the queue and the place in it", () => {
            usePlayerQueue().enqueue([track("a"), track("b"), track("c")]);
            usePlayerQueue().jumpTo(2);

            // A fresh page load: same storage, same user, empty in-memory state.
            closeTab();
            usePlayerQueue().hydrate();

            expect(ids()).toStrictEqual(["a", "b", "c"]);
            expect(usePlayerQueue().current.value?.id).toBe("c");
        });

        it("hydrates only once, so a second layout mount cannot undo live changes", () => {
            usePlayerQueue().enqueue([track("a")]);
            closeTab();

            const queue = usePlayerQueue();
            queue.hydrate();
            queue.enqueue(track("b"));
            queue.hydrate();

            expect(ids()).toStrictEqual(["a", "b"]);
        });

        it("discards a queue belonging to somebody else on a shared browser", () => {
            // The reason the payload is user-scoped at all: this instance is shared with
            // family and friends, so one browser genuinely sees several accounts.
            usePlayerQueue().enqueue([track("a")]);

            closeTab();
            signedInAs("user-2");
            usePlayerQueue().hydrate();

            expect(usePlayerQueue().isEmpty.value).toBe(true);
        });

        it("does not hand a guest the queue of the user who was logged in", () => {
            usePlayerQueue().enqueue([track("a")]);

            closeTab();
            signedInAs(null);
            usePlayerQueue().hydrate();

            expect(usePlayerQueue().isEmpty.value).toBe(true);
        });

        it("keeps a guest's own queue across a reload", () => {
            signedInAs(null);
            usePlayerQueue().enqueue([track("a")]);

            closeTab();
            usePlayerQueue().hydrate();

            expect(ids()).toStrictEqual(["a"]);
        });

        it("starts clean rather than throwing on a corrupt entry", () => {
            window.localStorage.setItem("mixtape.queue", "{not json");

            usePlayerQueue().hydrate();

            expect(usePlayerQueue().isEmpty.value).toBe(true);
        });

        it("ignores a payload written by an older shape", () => {
            // A shape-1 track has no `streamUrl`, so an adopted one would sit in the panel
            // looking playable and do nothing when pressed — which is why the payload
            // carries a version rather than the module trying to migrate.
            window.localStorage.setItem(
                "mixtape.queue",
                JSON.stringify({ version: 1, userId: "user-1", tracks: [track("a")], currentIndex: 0 })
            );

            usePlayerQueue().hydrate();

            expect(usePlayerQueue().isEmpty.value).toBe(true);
        });

        it("overwrites a refused payload rather than leaving it to squat on the budget", () => {
            // Why the key is no longer versioned. An abandoned KEY is an orphan nothing
            // deletes, and it holds its share of a ~5 MB origin budget for the life of the
            // profile; under one stable key, the refusal above self-heals on the next write.
            window.localStorage.setItem(
                "mixtape.queue",
                JSON.stringify({ version: 1, userId: "user-1", tracks: [track("old")], currentIndex: 0 })
            );

            usePlayerQueue().hydrate();
            usePlayerQueue().enqueue(track("new"));
            flushQueueWrites();

            expect(stored().tracks.map((entry: { id: string }) => entry.id)).toStrictEqual(["new"]);
        });
    });

    describe("what it stores", () => {
        /*
         * The stored track is a TRIM of the live one: everything the id can rebuild is left
         * out, because a QueueTrack names the same song four times over (once as `id`, three
         * times inside URLs built from it) and at library scale that repetition is most of a
         * browser's entire storage budget. Two things have to hold for the trim to be safe —
         * a full track has to come back out, and a URL the id CANNOT imply has to survive.
         */

        it("leaves out every URL the id can rebuild", () => {
            usePlayerQueue().enqueue({ ...track("a"), coverUrl: `${window.location.origin}/music/songs/a/cover` });
            flushQueueWrites();

            expect(stored().tracks[0]).toStrictEqual({
                id: "a",
                name: "Track a",
                artist: "Radiohead",
                album: "OK Computer",
                duration: 100,
                hasCover: true
            });
        });

        it("hands back a whole track, URLs and all", () => {
            usePlayerQueue().enqueue({ ...track("a"), coverUrl: `${window.location.origin}/music/songs/a/cover` });

            closeTab();
            usePlayerQueue().hydrate();

            // The cover comes back root-relative where it went in absolute: the same target,
            // and this module has no business minting an origin of its own.
            expect(usePlayerQueue().tracks.value[0]).toStrictEqual({
                id: "a",
                name: "Track a",
                artist: "Radiohead",
                album: "OK Computer",
                coverUrl: "/music/songs/a/cover",
                duration: 100,
                href: "/music/songs/a",
                streamUrl: "/music/songs/a/stream"
            });
        });

        it("keeps a coverless track coverless", () => {
            // Derived unconditionally, a track with no cover at all would point an <img> at a
            // 404 on every reload instead of drawing the placeholder.
            usePlayerQueue().enqueue(track("a"));

            closeTab();
            usePlayerQueue().hydrate();

            expect(usePlayerQueue().tracks.value[0].coverUrl).toBeNull();
        });

        it("stores a signed URL verbatim, because the id cannot imply a signature", () => {
            // The case the trim must not eat: a share link the server went out of its way to
            // sign. Trimmed to its path it would come back unsigned and 403 on play.
            const signed = "/music/songs/a/stream?expires=1893456000&signature=deadbeef";
            usePlayerQueue().enqueue({ ...track("a"), streamUrl: signed });
            flushQueueWrites();

            expect(stored().tracks[0].streamUrl).toBe(signed);

            closeTab();
            usePlayerQueue().hydrate();

            expect(usePlayerQueue().tracks.value[0].streamUrl).toBe(signed);
        });

        it("stores a cover served from somewhere else verbatim", () => {
            const foreign = "https://covers.example.test/a.jpg";
            usePlayerQueue().enqueue({ ...track("a"), coverUrl: foreign });

            closeTab();
            usePlayerQueue().hydrate();

            expect(usePlayerQueue().tracks.value[0].coverUrl).toBe(foreign);
        });

        it("drops one unusable row rather than the whole queue", () => {
            // A row with no id cannot be rebuilt at all, but the 200 beside it can.
            window.localStorage.setItem(
                "mixtape.queue",
                JSON.stringify({
                    version: 3,
                    userId: "user-1",
                    tracks: [{ name: "no id at all" }, { id: "b", name: "Track b", artist: null, album: null, duration: null }],
                    currentIndex: 0,
                    repeat: false
                })
            );

            usePlayerQueue().hydrate();

            expect(ids()).toStrictEqual(["b"]);
        });
    });

    describe("when it writes", () => {
        /*
         * A queue can hold the whole library, so WHEN it is written matters as much as what.
         * Writes are coalesced, and the pointer has its own key, so the two things that used
         * to rewrite ~1.9 MB — a burst of edits, and a song merely ending — now cost one small
         * write between them. Both halves are easy to break without any other spec noticing,
         * because the data still round-trips either way.
         */

        it("coalesces a burst of edits into one write", () => {
            const write = vi.spyOn(window.localStorage, "setItem");
            const queue = usePlayerQueue();

            queue.enqueue(track("a"));
            queue.enqueue(track("b"));
            queue.reorder(0, 1);

            expect(write).not.toHaveBeenCalled();

            flushQueueWrites();

            // One list write and one pointer write in total — not one of each per edit.
            expect(write.mock.calls.map(call => call[0])).toStrictEqual(["mixtape.queue", "mixtape.queue.position"]);
        });

        it("does not rewrite the list when a song merely ends", () => {
            // THE reason the pointer has a key of its own: this write used to carry every
            // queued track, unchanged, every few minutes, for as long as the queue played.
            usePlayerQueue().enqueue([track("a"), track("b")]);
            flushQueueWrites();

            const write = vi.spyOn(window.localStorage, "setItem");
            usePlayerQueue().next();
            flushQueueWrites();

            expect(write.mock.calls.map(call => call[0])).toStrictEqual(["mixtape.queue.position"]);
            expect(storedPosition().currentIndex).toBe(1);
        });

        it("writes by itself, without waiting for a flush", () => {
            vi.useFakeTimers();
            try {
                usePlayerQueue().enqueue(track("a"));

                expect(stored()).toBeNull();

                vi.advanceTimersByTime(500);

                expect(stored().tracks).toHaveLength(1);
            } finally {
                vi.useRealTimers();
            }
        });

        it("does not let a later edit postpone the write it already owes", () => {
            // A trailing-edge timer rather than a resetting debounce: someone dragging rows
            // for a minute must not keep the queue a minute out of date the whole time.
            vi.useFakeTimers();
            try {
                const queue = usePlayerQueue();
                queue.enqueue(track("a"));
                vi.advanceTimersByTime(400);
                queue.enqueue(track("b"));
                vi.advanceTimersByTime(100);

                expect(stored().tracks).toHaveLength(2);
            } finally {
                vi.useRealTimers();
            }
        });

        it("flushes when the tab goes away, because the timer may never fire", () => {
            // Background playback is the case that needs this: a hidden tab has its timers
            // throttled to once a minute, so the last auto-advance would go with the tab.
            vi.useFakeTimers();
            try {
                usePlayerQueue().hydrate(); // binds the listeners
                usePlayerQueue().enqueue(track("a"));

                window.dispatchEvent(new Event("pagehide"));

                expect(stored().tracks).toHaveLength(1);
            } finally {
                vi.useRealTimers();
            }
        });

        it("flushes when the tab is only backgrounded, which is all iOS reliably gives", () => {
            vi.useFakeTimers();
            const visibility = vi.spyOn(document, "visibilityState", "get").mockReturnValue("hidden");
            try {
                usePlayerQueue().hydrate();
                usePlayerQueue().enqueue(track("a"));

                document.dispatchEvent(new Event("visibilitychange"));

                expect(stored().tracks).toHaveLength(1);
            } finally {
                visibility.mockRestore();
                vi.useRealTimers();
            }
        });
    });

    describe("the pointer, stored apart from the list", () => {
        it("restores where you were, and whether you were repeating", () => {
            const queue = usePlayerQueue();
            queue.enqueue([track("a"), track("b"), track("c")]);
            queue.jumpTo(2);
            queue.toggleRepeat();

            closeTab();
            usePlayerQueue().hydrate();

            expect(usePlayerQueue().currentIndex.value).toBe(2);
            expect(usePlayerQueue().repeat.value).toBe(true);
        });

        it("cues a restored queue at its first track when the pointer is gone", () => {
            // The pointer is advice, not truth. Losing it must cost the place in the queue
            // and nothing else — dropping a restored queue over one integer would be worse.
            usePlayerQueue().enqueue([track("a"), track("b")]);
            usePlayerQueue().jumpTo(1);
            flushQueueWrites();
            window.localStorage.removeItem("mixtape.queue.position");

            closeTab();
            usePlayerQueue().hydrate();

            expect(ids()).toStrictEqual(["a", "b"]);
            expect(usePlayerQueue().currentIndex.value).toBe(0);
        });

        it("clamps a pointer that outlived the list it belonged to", () => {
            // What a list write that failed for want of room looks like from here: the
            // pointer says 5, the stored list holds one track. It must not load track 5.
            window.localStorage.setItem(
                "mixtape.queue",
                JSON.stringify({
                    version: 3,
                    userId: "user-1",
                    tracks: [{ id: "a", name: "Track a", artist: null, album: null, duration: null }]
                })
            );
            window.localStorage.setItem(
                "mixtape.queue.position",
                JSON.stringify({ version: 3, userId: "user-1", currentIndex: 5, repeat: true })
            );

            usePlayerQueue().hydrate();

            expect(usePlayerQueue().current.value?.id).toBe("a");
            expect(usePlayerQueue().repeat.value).toBe(true);
        });

        it("refuses a pointer belonging to somebody else on a shared browser", () => {
            // Same reason the list is user-scoped, and checked separately because the two
            // keys are written separately.
            usePlayerQueue().enqueue([track("a"), track("b")]);
            flushQueueWrites();
            window.localStorage.setItem(
                "mixtape.queue.position",
                JSON.stringify({ version: 3, userId: "user-2", currentIndex: 1, repeat: true })
            );

            closeTab();
            usePlayerQueue().hydrate();

            expect(usePlayerQueue().currentIndex.value).toBe(0);
            expect(usePlayerQueue().repeat.value).toBe(false);
        });
    });

    describe("syncing with the server", () => {
        /*
         * The half that makes the queue follow a person rather than a browser. What can be
         * proved here is that the right request is SENT and that the right copy wins on the
         way in; that a row is actually written, and that nobody else's row is read, is
         * tests/Feature/Player/PlayerStateSyncTest's job — no fake `fetch` can answer it.
         */

        it("pushes the queue to the server when something changes", () => {
            usePlayerQueue().enqueue([track("a"), track("b")]);
            flushQueueWrites();

            expect(fetchMock).toHaveBeenCalledTimes(1);
            const [url, init] = fetchMock.mock.calls[0];
            expect(url).toBe("/player/state");
            expect(init.method).toBe("PUT");
            // Inertia's own visits carry the token; this request is not one, so it has to
            // send it by hand or Laravel answers 419.
            expect((init.headers as Record<string, string>)["X-CSRF-TOKEN"]).toBe("test-token");
        });

        it("sends ids and the pointer, never the tracks themselves", () => {
            // The server is where the tracks came from. A title sent up would only be a
            // copy to go stale, and a long queue would be megabytes instead of kilobytes.
            usePlayerQueue().enqueue([track("a"), track("b")]);
            usePlayerQueue().jumpTo(1);
            usePlayerQueue().toggleRepeat();
            flushQueueWrites();

            // The stamp rides along and is the client's OWN clock — the server stores it
            // verbatim and hands it back, and comparing it with the local copy's is how the
            // next page load decides which queue is newer.
            expect(syncedBodies()[0]).toMatchObject({
                tracks: ["a", "b"],
                currentIndex: 1,
                repeat: true,
                shuffle: false
            });
            expect(typeof syncedBodies()[0].updatedAt).toBe("number");
        });

        it("costs one request for a burst, like the storage write beside it", () => {
            // Both writes ride the same coalescing — that is the reason the sync lives in
            // the flush rather than in `commit`.
            usePlayerQueue().enqueue(track("a"));
            usePlayerQueue().enqueue(track("b"));
            usePlayerQueue().reorder(0, 1);
            flushQueueWrites();

            expect(fetchMock).toHaveBeenCalledTimes(1);
        });

        it("says nothing for a guest, who has no row to write", () => {
            signedInAs(null);
            usePlayerQueue().enqueue(track("a"));
            flushQueueWrites();

            expect(fetchMock).not.toHaveBeenCalled();
            // …and the local copy is still written, which is the only one a guest has.
            expect(stored().tracks).toHaveLength(1);
        });

        it("keeps the request alive only when the tab is going away", () => {
            // `keepalive` buys a request that outlives the page and costs a 64 KB body cap
            // — worth it on the way out, pointless while a live page can complete it.
            usePlayerQueue().enqueue(track("a"));
            flushQueueWrites();
            expect(fetchMock.mock.calls[0][1].keepalive).toBe(false);

            usePlayerQueue().enqueue(track("b"));
            flushQueueWrites(true);
            expect(fetchMock.mock.calls[1][1].keepalive).toBe(true);
        });

        it("keeps playing when the sync fails", () => {
            // Offline, or a 419 after a session rotation. A player that broke because a
            // sync failed would be a worse bug than a queue one change behind elsewhere.
            fetchMock.mockRejectedValueOnce(new Error("offline"));

            usePlayerQueue().enqueue(track("a"));
            flushQueueWrites();

            expect(ids()).toStrictEqual(["a"]);
            expect(stored().tracks).toHaveLength(1);
        });

        it("takes the server's queue over the one in storage", () => {
            // The point of the feature: what you left on the laptop is what greets you on
            // the phone. Every local change was pushed as it happened, so the stored copy
            // is this browser's own last word too.
            usePlayerQueue().enqueue([track("local-1"), track("local-2")]);
            closeTab();

            signedInAs("user-1", {
                tracks: [track("server-1"), track("server-2"), track("server-3")],
                currentIndex: 2,
                repeat: true,
                shuffle: true,
                // Newer than anything this browser wrote — the whole basis of the decision.
                updatedAt: Date.now() + 60_000
            });
            usePlayerQueue().hydrate();

            expect(ids()).toStrictEqual(["server-1", "server-2", "server-3"]);
            expect(usePlayerQueue().current.value?.id).toBe("server-3");
            expect(usePlayerQueue().repeat.value).toBe(true);
            expect(usePlayerQueue().shuffle.value).toBe(true);
        });

        it("writes the adopted queue to storage, so the next offline load still has it", () => {
            signedInAs("user-1", {
                tracks: [track("server-1")],
                currentIndex: 0,
                repeat: false,
                shuffle: false,
                updatedAt: Date.now() + 60_000
            });
            usePlayerQueue().hydrate();

            expect(stored().tracks[0].id).toBe("server-1");
            // Straight to storage rather than through the dirty set: nothing CHANGED, and
            // marking it would send the server its own queue back.
            expect(fetchMock).not.toHaveBeenCalled();
        });

        it("keeps the local queue when the server's copy is older than it", () => {
            /*
             * THE RACE THIS EXISTS FOR, and it is not hypothetical — the E2E suite hit it on
             * the first run (2026-08-07). Enqueue, then click a link: the sync PUT and the
             * next page's HTML are two requests racing, and if the page wins, the server
             * hands back the queue as it was BEFORE the enqueue. Adopting it unconditionally
             * loses the track that was just added.
             */
            usePlayerQueue().enqueue([track("a"), track("b")]);
            closeTab();

            signedInAs("user-1", {
                tracks: [track("a")],
                currentIndex: 0,
                repeat: false,
                shuffle: false,
                // One second before this browser's own last write.
                updatedAt: Date.now() - 1_000
            });
            usePlayerQueue().hydrate();

            expect(ids()).toStrictEqual(["a", "b"]);
        });

        it("keeps the local queue when the server has none", () => {
            // Null means "nothing stored", not "an empty queue" — the distinction the
            // server payload is careful about. Reading it the other way would wipe a good
            // local queue on the first load after signing in on a second device.
            usePlayerQueue().enqueue([track("a"), track("b")]);
            closeTab();

            signedInAs("user-1", null);
            usePlayerQueue().hydrate();

            expect(ids()).toStrictEqual(["a", "b"]);
        });
    });

    describe("the play position", () => {
        /*
         * The queue stores how far into the loaded track the listener had got, but it cannot
         * READ that — the number lives on the <audio> element, which usePlayerAudio owns and
         * which imports this module rather than the other way round. So the player registers
         * a getter, and what is worth pinning here is the two halves of that handshake: the
         * value reaching storage and the sync, and this module refusing to write for a
         * position that has not moved.
         */

        /** Stand in for the player, which is the only thing that can read an element. */
        const playingAt = (seconds: number) => bindPositionSource(() => seconds);

        it("stores the position with the pointer and sends it up", () => {
            playingAt(96.4);
            usePlayerQueue().enqueue(track("a"));
            flushQueueWrites();

            // Milliseconds, and rounded — the row's unit, and an integer stores smaller
            // than a float of seconds.
            expect(storedPosition().positionMs).toBe(96_400);
            expect(syncedBodies()[0].positionMs).toBe(96_400);
        });

        it("writes nothing when the position has not really moved", () => {
            // The player asks on every heartbeat, on a pause and on the way out — three
            // asks a second apart, at the same instant of a track, are one write.
            playingAt(96);
            usePlayerQueue().enqueue(track("a"));
            flushQueueWrites();
            fetchMock.mockClear();

            notePlaybackProgress();
            flushQueueWrites();

            expect(fetchMock).not.toHaveBeenCalled();
        });

        it("writes when it has", () => {
            playingAt(96);
            usePlayerQueue().enqueue(track("a"));
            flushQueueWrites();
            fetchMock.mockClear();

            playingAt(140);
            notePlaybackProgress();
            flushQueueWrites();

            expect(storedPosition().positionMs).toBe(140_000);
            expect(fetchMock).toHaveBeenCalledTimes(1);
        });

        it("hands a restored position back to the player exactly once", () => {
            // The value belongs to the track a page load came back holding, and the player
            // takes it as it loads that track. A second reader would be a later track.
            usePlayerQueue().enqueue(track("a"));
            flushQueueWrites();
            closeTab();
            playingAt(0);
            usePlayerQueue().hydrate();

            expect(typeof takeRestoredPosition()).toBe("number");
            expect(takeRestoredPosition()).toBe(0);
        });
    });

    describe("when a save is refused", () => {
        /*
         * Both failures stay non-fatal — a full storage or a dead network must never take
         * the player down — but neither is silent any more. What is at risk is not the music
         * (it plays on) but whether the queue on screen is the queue that comes back, which
         * is precisely the kind of thing a listener should be told rather than discover.
         */

        /** The toasts on screen, as text. */
        const toasts = () => useToast().activeToasts.value;

        /**
         * Make every storage write fail, the way a full quota does.
         *
         * Spied on the INSTANCE, not on `Storage.prototype`: the suite replaces
         * `localStorage` with its own MemoryStorage (happy-dom ships none), and that class
         * has its own `setItem` — a prototype spy would install cleanly and catch nothing.
         */
        const fillStorage = () =>
            vi.spyOn(window.localStorage, "setItem").mockImplementation(() => {
                throw new DOMException("quota", "QuotaExceededError");
            });

        it("warns when the browser refuses to store the queue, and keeps playing", () => {
            fillStorage();

            usePlayerQueue().enqueue([track("a"), track("b")]);
            flushQueueWrites();

            expect(toasts()).toHaveLength(1);
            expect(toasts()[0].type).toBe("warning");
            // The queue itself is untouched: the failure is about SURVIVING, not about now.
            expect(ids()).toStrictEqual(["a", "b"]);
        });

        it("says it once, however many tracks end", () => {
            // The queue flushes on every track change, so an unlatched warning would raise a
            // toast every four minutes for as long as the tab is open.
            fillStorage();

            usePlayerQueue().enqueue([track("a"), track("b")]);
            flushQueueWrites();
            usePlayerQueue().next();
            flushQueueWrites();

            expect(toasts()).toHaveLength(1);
        });

        it("speaks again after a write works and then fails a second time", () => {
            const failing = fillStorage();
            usePlayerQueue().enqueue(track("a"));
            flushQueueWrites();

            failing.mockRestore();
            usePlayerQueue().enqueue(track("b"));
            flushQueueWrites();

            fillStorage();
            usePlayerQueue().enqueue(track("c"));
            flushQueueWrites();

            expect(toasts()).toHaveLength(2);
        });

        it("warns when the server refuses the sync", async () => {
            // A 419 after a session rotation resolves happily and stores nothing — which is
            // why the status is checked rather than only the promise.
            fetchMock.mockResolvedValueOnce(new Response(null, { status: 419 }));

            usePlayerQueue().enqueue(track("a"));
            flushQueueWrites();
            await Promise.resolve();
            await Promise.resolve();

            expect(toasts()).toHaveLength(1);
            expect(toasts()[0].message).toContain("Server");
        });

        it("warns when the sync never leaves at all", async () => {
            fetchMock.mockRejectedValueOnce(new Error("offline"));

            usePlayerQueue().enqueue(track("a"));
            flushQueueWrites();
            await Promise.resolve();
            await Promise.resolve();

            expect(toasts()).toHaveLength(1);
        });

        it("says nothing about the server while the tab is closing", async () => {
            // A toast raised into a page being torn down is one nobody can read, and the
            // local copy was written either way.
            fetchMock.mockRejectedValueOnce(new Error("offline"));

            usePlayerQueue().enqueue(track("a"));
            flushQueueWrites(true);
            await Promise.resolve();
            await Promise.resolve();

            expect(toasts()).toHaveLength(0);
        });
    });
});
