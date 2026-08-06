import { beforeEach, describe, expect, it, vi } from "vitest";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { flushQueueWrites, resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetInertia, setPage } from "Testing/inertia";

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
const signedInAs = (id: string | null) => setPage({ props: { auth: { user: id ? { id, name: "Ash", email: "a@b.c" } : null } } });

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
        resetPlayerQueueForTests();
        window.localStorage.clear();
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
});
