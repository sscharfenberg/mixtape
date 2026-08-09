import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { useAddToPlaylist } from "Composables/useAddToPlaylist";
import type { AddToPlaylistBody } from "Composables/useAddToPlaylist";
import { resetInertia, routerCalls, setPage } from "Testing/inertia";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The rule behind every "add to playlist" control: which playlists may be offered, whether save
 * may be pressed, and what is actually sent.
 *
 * Three things here are genuinely this file's to check, and none of them is reachable from the
 * PHP side:
 *
 *   - THE NARROWING. The server sends one list of playlists (shared) and a page sends the ids
 *     it may offer; putting the two together is a client decision, and getting it wrong shows
 *     as a select holding playlists that would do nothing.
 *   - THE WITHDRAWN CHOICE. A successful save removes a playlist from the offer while its id is
 *     still selected. Left alone, the trigger shows a placeholder while the button stays armed
 *     at something invisible — a state a server assertion cannot see at all.
 *   - WHICH BODY SHAPE GOES OUT. A subject and a list of ids post to the SAME url, so the url
 *     alone says nothing about what happened.
 *
 * The mock router records calls without settling them, which is what makes the outcome the
 * test's to choose: `succeed()` and `finish()` below play the callbacks Inertia would.
 */

/** Two playlists, in the order the server sent them — the reader's own arrangement. */
const playlists = [
    { id: "playlist-1", name: "Sunday morning" },
    { id: "playlist-2", name: "Loud" }
];

/** The options bag of the last router call, where Inertia's callbacks live. */
const lastOptions = (): Record<string, unknown> => routerCalls[routerCalls.length - 1].options!;

/** Play Inertia's success callback, as a landed write would. */
const succeed = (): void => (lastOptions().onSuccess as () => void)();

/** Play Inertia's finish callback, which runs whatever the outcome was. */
const finish = (): void => (lastOptions().onFinish as () => void)();

describe("useAddToPlaylist", () => {
    beforeEach(() => {
        resetInertia();
        setPage({ props: { playlists } });
    });

    describe("what it offers", () => {
        it("offers every playlist when the caller names no narrower set", () => {
            const { options } = useAddToPlaylist(() => ({ tracks: [] }));

            expect(options.value.map(playlist => playlist.name)).toEqual(["Sunday morning", "Loud"]);
        });

        it("keeps only the ids the page said may be offered, in the shared list's order", () => {
            const { options } = useAddToPlaylist(() => ({ tracks: [] }), () => ["playlist-2"]);

            expect(options.value).toEqual([{ id: "playlist-2", name: "Loud" }]);
        });

        it("offers nothing rather than throwing when the response carried no playlists", () => {
            resetInertia();

            const { options } = useAddToPlaylist(() => ({ tracks: [] }));

            expect(options.value).toEqual([]);
        });
    });

    describe("when save may be pressed", () => {
        it("refuses until a playlist is chosen", () => {
            const { selected, canSave } = useAddToPlaylist(() => ({ tracks: [] }));

            expect(canSave.value).toBe(false);

            selected.value = "playlist-1";
            expect(canSave.value).toBe(true);
        });

        it("refuses again while a write is in flight, so one press is one addition", () => {
            const { selected, canSave, saving, save } = useAddToPlaylist(() => ({ tracks: ["track-1"] }));

            selected.value = "playlist-1";
            save();

            expect(saving.value).toBe(true);
            expect(canSave.value).toBe(false);

            // A second press while the first is out must not post again.
            save();
            expect(routerCalls).toHaveLength(1);
        });

        it("sends nothing at all when nothing is chosen, however it is pressed", () => {
            // Reachable by submitting the form with Enter, which no `disabled` attribute stops.
            const { save } = useAddToPlaylist(() => ({ tracks: ["track-1"] }));

            save();

            expect(routerCalls).toHaveLength(0);
        });
    });

    describe("what it sends", () => {
        it("posts a subject to the chosen playlist, leaving the tracks to the server", () => {
            const { selected, save } = useAddToPlaylist(() => ({ subject: "artist", id: "artist-1" }));

            selected.value = "playlist-2";
            save();

            expect(routerCalls[0].method).toBe("post");
            expect(routerCalls[0].url).toBe("/playlists/playlist-2/tracks");
            expect(routerCalls[0].data).toEqual({ subject: "artist", id: "artist-1" });
        });

        it("posts track ids for a queue, since only the browser knows what is in one", () => {
            const { selected, save } = useAddToPlaylist(() => ({ tracks: ["track-2", "track-1"] }));

            selected.value = "playlist-1";
            save();

            expect(routerCalls[0].data).toEqual({ tracks: ["track-2", "track-1"] });
        });

        it("reads the body at the moment of the press, not when the control was built", () => {
            // The queue can grow while a modal sits open; what is added is what is queued NOW.
            let queued = ["track-1"];
            const body = (): AddToPlaylistBody => ({ tracks: queued });
            const { selected, save } = useAddToPlaylist(body);

            selected.value = "playlist-1";
            queued = ["track-1", "track-2"];
            save();

            expect(routerCalls[0].data).toEqual({ tracks: ["track-1", "track-2"] });
        });

        it("keeps the page in place, so a table under the hero does not blink", () => {
            const { selected, save } = useAddToPlaylist(() => ({ subject: "song", id: "song-1" }));

            selected.value = "playlist-1";
            save();

            expect(lastOptions()).toMatchObject({ preserveScroll: true, preserveState: true });
        });
    });

    describe("after the write", () => {
        it("clears the choice and lets go of the button once it has landed", () => {
            const { selected, saving, save } = useAddToPlaylist(() => ({ subject: "song", id: "song-1" }));

            selected.value = "playlist-1";
            save();
            succeed();
            finish();

            expect(selected.value).toBe("");
            expect(saving.value).toBe(false);
        });

        it("closes what asked to be closed, but only on success", () => {
            const onSaved = vi.fn();
            const { selected, save } = useAddToPlaylist(() => ({ tracks: ["track-1"] }));

            selected.value = "playlist-1";
            save(onSaved);
            // A failure: Inertia finishes without succeeding.
            finish();

            expect(onSaved).not.toHaveBeenCalled();
            // The choice survives, so pressing save again IS the retry.
            expect(selected.value).toBe("playlist-1");

            save(onSaved);
            succeed();

            expect(onSaved).toHaveBeenCalledTimes(1);
        });

        it("drops a selection that has left the offer, so the button cannot arm at nothing", async () => {
            let offered = ["playlist-1", "playlist-2"];
            const { selected } = useAddToPlaylist(() => ({ subject: "song", id: "song-1" }), () => offered);

            selected.value = "playlist-1";
            // What a landed write looks like from here: the page comes back with the playlist
            // just written to no longer in the offer.
            offered = ["playlist-2"];
            setPage({ props: { playlists: [...playlists] } });
            await nextTick();

            expect(selected.value).toBe("");
        });
    });
});
