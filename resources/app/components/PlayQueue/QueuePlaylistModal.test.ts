import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetInertia, routerCalls, setPage } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import QueuePlaylistModal from "./QueuePlaylistModal.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * "Add everything in the queue to a playlist".
 *
 * THE QUEUE IS THE POINT of this component, and it is the one thing the server cannot check:
 * the queue lives in the browser, so what gets posted is whatever `usePlayerQueue` holds at the
 * moment save is pressed. Two consequences are tested here and nowhere else — the ids go out in
 * QUEUE ORDER (a playlist built from the queue must play like the queue), and they are read at
 * the press rather than at the open, so a track that finished while the modal sat there does not
 * send a stale list.
 *
 * The offer is deliberately every playlist, unlike the detail-page heroes: the server has no
 * live copy of the queue to compute a narrower one from, and posting the queue up just to draw
 * a select would be the request this modal exists to make.
 *
 * EVERYTHING IS QUERIED OFF `document`, not off the wrapper — Modal teleports to <body>, so
 * `wrapper.find()` reaches straight past it. The same rule PlaylistExportModal's spec records.
 */

/** Two playlists, as the shared prop carries them. */
const playlists = [
    { id: "playlist-1", name: "Sunday morning" },
    { id: "playlist-2", name: "Loud" }
];

/** A queue entry. */
const track = (id: string) => ({
    id,
    name: `Track ${id}`,
    artist: "Radiohead",
    album: "OK Computer",
    href: `/music/songs/${id}`,
    coverUrl: null,
    streamUrl: `/music/songs/${id}/stream`,
    duration: 300
});

/** Choose the nth playlist in the teleported select, the way a click does. */
const choose = async (index: number): Promise<void> => {
    document.querySelector<HTMLButtonElement>(".form-select__button")!.click();
    await nextTick();
    document.querySelectorAll<HTMLButtonElement>(".form-select__option")[index].click();
    await nextTick();
};

/** Submit the teleported form. */
const submit = (): void => {
    document
        .querySelector("#queue-playlist-form")!
        .dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
};

describe("QueuePlaylistModal", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        document.body.innerHTML = "";
        setPage({ props: { playlists } });
    });

    it("posts the queue's ids, in the order the reader arranged them", async () => {
        usePlayerQueue().playNow([track("c"), track("a"), track("b")]);
        mountApp(QueuePlaylistModal);

        await choose(0);
        submit();

        expect(routerCalls[0].url).toBe("/playlists/playlist-1/tracks");
        expect(routerCalls[0].data).toEqual({ tracks: ["c", "a", "b"] });
    });

    it("reads the queue at the press, not at the open", async () => {
        // A modal can sit open while a track finishes, or while the reader queues another album
        // from the page behind it. What is added is what is queued NOW.
        usePlayerQueue().playNow([track("a")]);
        mountApp(QueuePlaylistModal);

        await choose(0);
        usePlayerQueue().enqueue([track("b")]);
        submit();

        expect(routerCalls[0].data).toEqual({ tracks: ["a", "b"] });
    });

    it("offers every playlist, since the server cannot know what a queue holds", async () => {
        usePlayerQueue().playNow([track("a")]);
        mountApp(QueuePlaylistModal);

        document.querySelector<HTMLButtonElement>(".form-select__button")!.click();
        await nextTick();

        const labels = [...document.querySelectorAll(".form-select__option")].map(option => option.textContent?.trim());

        expect(labels).toEqual(["Sunday morning", "Loud"]);
    });

    it("sends nothing until a playlist is chosen", () => {
        usePlayerQueue().playNow([track("a")]);
        mountApp(QueuePlaylistModal);

        submit();

        expect(routerCalls).toHaveLength(0);
    });

    it("closes only once the write has landed, so a failure can be retried", async () => {
        usePlayerQueue().playNow([track("a")]);
        const wrapper = mountApp(QueuePlaylistModal);

        await choose(1);
        submit();

        expect(wrapper.emitted("close")).toBeUndefined();

        (routerCalls[0].options!.onSuccess as () => void)();
        expect(wrapper.emitted("close")).toHaveLength(1);
    });
});
