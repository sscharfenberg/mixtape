import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia, routerCalls, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import AddToPlaylist from "./AddToPlaylist.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The hero's "add this to a playlist" area.
 *
 * What the composable already covers — the narrowing, the guard on save, the body that goes out
 * — is not repeated here. What is left is what only a rendered component decides, and all of it
 * is about the area having NOTHING to offer, which happens in two ways that want opposite
 * treatment:
 *
 *   - every playlist already holds this: say so. The control disappearing on its own reads as a
 *     bug, and "it is already in all of them" is the answer to the question being asked.
 *   - no playlists at all: render nothing. There is no decision to present.
 *
 * The other half is the sentence, which is FOUR keys rather than one template with the noun
 * interpolated — German declines the article with the noun's gender, so a template is wrong on
 * half the pages. That is a claim about the catalog, and it is checked by rendering against the
 * real one (Testing/mount imports de.json itself).
 */

/** Two playlists, as the shared prop carries them. */
const playlists = [
    { id: "playlist-1", name: "Sunday morning" },
    { id: "playlist-2", name: "Loud" }
];

/** Mount the area for a subject, with whatever offer the page would have sent. */
const area = (subject: "song" | "album" | "artist" | "genre", addable: string[]) =>
    mountApp(AddToPlaylist, { props: { subject, subjectId: "subject-1", addable } });

describe("AddToPlaylist", () => {
    beforeEach(() => {
        resetInertia();
        setPage({ props: { playlists } });
    });

    it("names what will be added, in the case the noun takes", () => {
        // The reason the four sentences are four keys: "Diesen Song" against "Dieses Album" is
        // not something a `{subject}` placeholder can produce.
        expect(area("song", ["playlist-1"]).text()).toContain(translate("playlists.add.song"));
        expect(area("album", ["playlist-1"]).text()).toContain(translate("playlists.add.album"));
        expect(area("artist", ["playlist-1"]).text()).toContain(translate("playlists.add.artist"));
        expect(area("genre", ["playlist-1"]).text()).toContain(translate("playlists.add.genre"));
    });

    it("offers only the playlists the page said may be offered", async () => {
        const wrapper = area("song", ["playlist-2"]);

        expect(wrapper.findAll(".form-select__option")).toHaveLength(0); // the list is closed
        await wrapper.find(".form-select__button").trigger("click");

        expect(wrapper.findAll(".form-select__option").map(option => option.text())).toEqual(["Loud"]);
    });

    it("keeps save disabled until a playlist is chosen", async () => {
        const wrapper = area("song", ["playlist-1", "playlist-2"]);

        expect(wrapper.find("button.btn").attributes("disabled")).toBeDefined();

        await wrapper.find(".form-select__button").trigger("click");
        await wrapper.findAll(".form-select__option")[0].trigger("click");

        expect(wrapper.find("button.btn").attributes("disabled")).toBeUndefined();

        await wrapper.find("button.btn").trigger("click");
        expect(routerCalls).toHaveLength(1);
    });

    it("says the subject is already everywhere rather than vanishing", () => {
        const wrapper = area("song", []);

        expect(wrapper.text()).toContain(translate("playlists.add.exhausted"));
        expect(wrapper.find(".form-select__button").exists()).toBe(false);
    });

    it("renders nothing at all for a reader with no playlists yet", () => {
        // Different from "already in all of them": there is no decision to present, and making
        // the first playlist happens in the Playlists area rather than here.
        setPage({ props: { playlists: [] } });

        expect(area("song", []).find(".add-to-playlist").exists()).toBe(false);
    });
});
