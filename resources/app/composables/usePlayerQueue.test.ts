import { beforeEach, describe, expect, it, vi } from "vitest";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
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

describe("usePlayerQueue", () => {
    beforeEach(() => {
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

            resetPlayerQueueForTests();
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
            resetPlayerQueueForTests();
            usePlayerQueue().hydrate();

            expect(ids()).toStrictEqual(["a", "b", "c"]);
            expect(usePlayerQueue().current.value?.id).toBe("c");
        });

        it("hydrates only once, so a second layout mount cannot undo live changes", () => {
            usePlayerQueue().enqueue([track("a")]);
            resetPlayerQueueForTests();

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

            resetPlayerQueueForTests();
            signedInAs("user-2");
            usePlayerQueue().hydrate();

            expect(usePlayerQueue().isEmpty.value).toBe(true);
        });

        it("does not hand a guest the queue of the user who was logged in", () => {
            usePlayerQueue().enqueue([track("a")]);

            resetPlayerQueueForTests();
            signedInAs(null);
            usePlayerQueue().hydrate();

            expect(usePlayerQueue().isEmpty.value).toBe(true);
        });

        it("keeps a guest's own queue across a reload", () => {
            signedInAs(null);
            usePlayerQueue().enqueue([track("a")]);

            resetPlayerQueueForTests();
            usePlayerQueue().hydrate();

            expect(ids()).toStrictEqual(["a"]);
        });

        it("starts clean rather than throwing on a corrupt entry", () => {
            window.localStorage.setItem("mixtape.queue.v2", "{not json");

            usePlayerQueue().hydrate();

            expect(usePlayerQueue().isEmpty.value).toBe(true);
        });

        it("ignores a payload written by an older shape", () => {
            // A v1 track has no `streamUrl`, so an adopted one would sit in the panel
            // looking playable and do nothing when pressed — which is exactly why the
            // key carries a version rather than the module trying to migrate.
            window.localStorage.setItem(
                "mixtape.queue.v2",
                JSON.stringify({ version: 1, userId: "user-1", tracks: [track("a")], currentIndex: 0 })
            );

            usePlayerQueue().hydrate();

            expect(usePlayerQueue().isEmpty.value).toBe(true);
        });
    });
});
