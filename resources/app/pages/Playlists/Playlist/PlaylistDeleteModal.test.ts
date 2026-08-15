import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetInertia, routerCalls } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import PlaylistDeleteModal from "./PlaylistDeleteModal.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The confirmation in front of deleting a playlist.
 *
 * WHAT IS WORTH TESTING HERE is what the reader is told and what the button does twice. The
 * delete itself, its cascade into shares and its 404 for somebody else's playlist are pinned
 * server-side in tests/Feature/Playlists/DeletePlaylistTest.php; this file covers the two
 * things PHP cannot see — that the dialog names the playlist and states the share
 * consequence, and that a second press cannot send a second DELETE.
 *
 * EVERYTHING IS QUERIED OFF `document`, not off the wrapper, for the reason the export
 * modal's test records: Modal TELEPORTS to <body>, so `wrapper.find()` reaches straight past
 * it.
 */

/** Mount the dialog over a playlist. */
const modal = (name = "Sonntagmorgen") =>
    mountApp(PlaylistDeleteModal, { props: { id: "playlist-1", name } });

/** The dialog's confirm button — the only button its footer holds. */
const confirm = (): HTMLButtonElement => {
    const button = document.querySelector<HTMLButtonElement>(".modal-dialog__footer button");

    if (button === null) throw new Error("the confirm button is not in the document");

    return button;
};

describe("PlaylistDeleteModal", () => {
    beforeEach(() => {
        resetInertia();
        document.body.innerHTML = "";
    });

    it("names the playlist it is about to delete", () => {
        // A dialog that said "delete this playlist?" would be the same sentence whichever row
        // opened it, and the risk of a delete button is deleting the neighbour of the one meant.
        modal("Sonntagmorgen");

        // `translate` looks a key up, it does not interpolate — the placeholder is filled in
        // here so the assertion still fails if the KEY changes.
        expect(document.body.textContent).toContain(
            translate("playlists.delete.body").replace("{name}", "Sonntagmorgen")
        );
    });

    it("states what happens to links already shared", () => {
        /*
         * THE HALF THE READER DID NOT ASK FOR. `shares` cascades from the playlist, so links
         * already sent stop working — invisible from the button, and unrecoverable, since a
         * re-mint is a different id.
         */
        modal();

        expect(document.body.textContent).toContain(translate("playlists.delete.warning"));
    });

    it("sends one DELETE to the playlist's own URL", async () => {
        const wrapper = modal();

        confirm().click();
        await nextTick();

        expect(routerCalls).toHaveLength(1);
        expect(routerCalls[0].method).toBe("delete");
        expect(routerCalls[0].url).toBe("/playlists/playlist-1");

        wrapper.unmount();
    });

    it("shows a spinner in place of the icon and refuses a second press", async () => {
        /*
         * IN PLACE OF, not beside: the button keeps its width, so the label does not shift
         * under a pointer that is still resting on it. The disable is what stops a double
         * press sending a second DELETE against a row the first one is already removing.
         */
        const wrapper = modal();

        expect(document.querySelector(".loading-spinner")).toBeNull();

        confirm().click();
        await nextTick();

        expect(document.querySelector(".loading-spinner")).not.toBeNull();
        expect(confirm().disabled).toBe(true);

        confirm().click();
        await nextTick();
        expect(routerCalls).toHaveLength(1);

        wrapper.unmount();
    });

    it("does not close itself — the redirect replaces the page", async () => {
        /*
         * Unlike RevokeShareModal, which closes on success. The server answers a delete with a
         * redirect to the listing, so Inertia replaces this dialog along with the page behind
         * it; emitting `close` first would only race that.
         */
        const wrapper = modal();

        confirm().click();
        await nextTick();

        expect(wrapper.emitted("close")).toBeUndefined();

        wrapper.unmount();
    });
});
