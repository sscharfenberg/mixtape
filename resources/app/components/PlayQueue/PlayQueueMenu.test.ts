import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import PlayQueueMenu from "./PlayQueueMenu.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The queue panel's action menu — two verbs, and one of them is conditional.
 *
 * "Add everything to a playlist" is HIDDEN rather than disabled for a reader with no playlists:
 * a row that opens a modal offering an empty select is a worse answer than no row, and the
 * first playlist is made in the Playlists area. That is a decision only the rendered menu makes
 * — the endpoint behind it has no opinion about who has playlists — so it is checked here.
 *
 * The other half is the popover CLOSE. This entry leaves the queue standing, unlike `clear`,
 * which empties it and takes the whole panel (popover included) out of the DOM; without an
 * explicit `hidePopover()` the menu would be left hanging open behind the modal. happy-dom has
 * no popover implementation, so the call is asserted through a stub rather than through state.
 */

/** Two playlists, as the shared prop carries them. */
const playlists = [
    { id: "playlist-1", name: "Sunday morning" },
    { id: "playlist-2", name: "Loud" }
];

/** A queue entry, so the modal has something to post. */
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

/** The menu's entries, by their labels. */
const entries = (wrapper: ReturnType<typeof mountApp>): string[] =>
    wrapper.findAll(".popover-list-item").map(item => item.text());

describe("PlayQueueMenu", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        document.body.innerHTML = "";
        usePlayerQueue().playNow([track("a")]);
        setPage({ props: { playlists } });
    });

    it("offers the playlist entry above the destructive one", () => {
        // Order matters: clearing is the entry a mis-aimed click must not land on, so it is the
        // one furthest from the trigger.
        expect(entries(mountApp(PlayQueueMenu))).toEqual([
            translate("player.queue.addToPlaylist"),
            translate("player.queue.clear")
        ]);
    });

    it("hides the playlist entry from a reader with none, rather than disabling it", () => {
        setPage({ props: { playlists: [] } });

        expect(entries(mountApp(PlayQueueMenu))).toEqual([translate("player.queue.clear")]);
    });

    it("opens the modal and puts its own popover away first", async () => {
        const wrapper = mountApp(PlayQueueMenu, { attachTo: document.body });
        // happy-dom knows nothing about the Popover API, so the panel gets the method it would
        // have in a browser and the test watches for the call.
        const panel = document.getElementById("playQueueActions")!;
        const hidePopover = vi.fn();
        Object.assign(panel, { hidePopover });

        await wrapper.findAll(".popover-list-item")[0].trigger("click");
        await nextTick();

        expect(hidePopover).toHaveBeenCalledTimes(1);
        // The modal teleports to <body>, so it is found there rather than on the wrapper.
        expect(document.querySelector("#add-to-playlist-form")).not.toBeNull();
    });
});
