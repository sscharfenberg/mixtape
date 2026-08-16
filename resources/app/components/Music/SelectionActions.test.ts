import { flushPromises } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick, ref } from "vue";
import type { AddablePlaylistSubject } from "Composables/useAddToPlaylist";
import { resetPlayerQueueForTests } from "Composables/usePlayerQueue";
import { resetToastsForTests } from "Composables/useToast";
import { resetInertia, routerCalls, setPage } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import { DATA_TABLE_KEY } from "Types/dataTable";
import SelectionActions from "./SelectionActions.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The bulk-action row that appears over a DataTable once rows are ticked.
 *
 * WHAT IS TESTED HERE AND NOT IN useSelectionActions' spec: this file is about the RELATIONSHIP
 * with the table — that the row is absent until something is ticked, that the page's row-kind
 * reaches the request unchanged, and above all WHEN THE TICKS ARE DROPPED.
 *
 * That last one is the reason this spec exists at all. Nothing clears the ticks on this
 * component's behalf — the table clears only when the QUESTION changes, and none of the three
 * verbs changes the sort, the search or a filter — so every clearing is a deliberate call, and
 * "cleared on success, kept on failure" is a rule only a test can hold in place. Keeping the
 * ticks after a failed press is what makes pressing again the retry; dropping them after a
 * successful one is what stops them riding along to the next page.
 *
 * The table is provided by hand rather than by mounting a real DataTable — the component injects
 * DATA_TABLE_KEY, so the seam is the injection, and a real table would drag pagination, sorting
 * and a server response into a spec about three menu entries.
 */

/** A stand-in DataTable context, with spies where the component reaches back into the table. */
const tableContext = (selected: string[] = []) => ({
    selectedIds: ref([...selected]),
    toggleSelection: vi.fn(),
    togglePageSelection: vi.fn(),
    clearSelection: vi.fn()
});

/** Mount the actions row inside a table holding `selected`. */
const actions = (selected: string[] = [], subject: AddablePlaylistSubject = "album") => {
    const table = tableContext(selected);
    const wrapper = mountApp(SelectionActions, {
        props: { subject },
        global: { provide: { [DATA_TABLE_KEY as symbol]: table } }
    });

    return { wrapper, table };
};

/** Stand in for the endpoint with one canned answer. */
const respond = (status: number, body: unknown = []): void => {
    vi.stubGlobal(
        "fetch",
        vi.fn().mockResolvedValue({
            ok: status >= 200 && status < 300,
            status,
            json: () => Promise.resolve(body)
        })
    );
};

/** A queue entry as the endpoint hands it back. */
const track = {
    id: "track-1",
    name: "Alison",
    artist: "Slowdive",
    album: "Souvlaki",
    href: "/music/songs/track-1",
    coverUrl: null,
    streamUrl: "/music/songs/track-1/stream",
    duration: 200
};

/**
 * The menu's three entries, in document order: play, enqueue, add to playlist.
 *
 * Scoped to `.popover-list-item` rather than every `button`, because the trigger that opens the
 * menu is a button too — and it is FIRST, so an unscoped query would silently shift every index
 * by one and still pass a length check.
 *
 * No opening step is needed: a native `[popover]` panel stays in the DOM where it is declared
 * (it is promoted to the top layer visually, not moved), so happy-dom renders its contents
 * whether or not it has been shown. That the trigger really reveals them is a rendering
 * question, and it belongs to the Playwright spec.
 */
const entries = (wrapper: ReturnType<typeof actions>["wrapper"]) => wrapper.findAll(".popover-list-item");

describe("SelectionActions", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        resetToastsForTests();
        document.body.innerHTML = "";
        setPage({ props: { auth: { user: null }, csrfToken: "a-token", playlists: [{ id: "p1", name: "Loud" }] } });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it("draws nothing at all until a row is ticked", () => {
        // A table nobody has touched has to look exactly as it did before this feature existed —
        // an empty toolbar block would still take its gap.
        const { wrapper } = actions([]);

        expect(wrapper.find(".selection-actions").exists()).toBe(false);
        expect(entries(wrapper)).toHaveLength(0);
    });

    it("offers the three verbs once something is ticked", () => {
        const { wrapper } = actions(["album-1"]);

        expect(entries(wrapper).map(button => button.text())).toEqual([
            "Auswahl abspielen",
            "Auswahl anhängen",
            "Zur Wiedergabeliste"
        ]);
    });

    it("sends the page's row-kind and the ticked ids", async () => {
        respond(200, [track]);
        const { wrapper } = actions(["artist-1", "artist-2"], "artist");

        await entries(wrapper)[0].trigger("click");
        await flushPromises();

        const [, init] = vi.mocked(fetch).mock.calls[0] as [string, RequestInit];

        expect(JSON.parse(init.body as string)).toEqual({
            subject: "artist",
            ids: ["artist-1", "artist-2"]
        });
    });

    it("drops the ticks once an action has been carried out", async () => {
        respond(200, [track]);
        const { wrapper, table } = actions(["album-1"]);

        await entries(wrapper)[1].trigger("click");
        await flushPromises();

        expect(table.clearSelection).toHaveBeenCalledTimes(1);
    });

    it("KEEPS the ticks when the action failed, so pressing again is the retry", async () => {
        respond(500);
        const { wrapper, table } = actions(["album-1"]);

        await entries(wrapper)[0].trigger("click");
        await flushPromises();

        expect(table.clearSelection).not.toHaveBeenCalled();
    });

    it("keeps them too when the rows held nothing playable", async () => {
        // A real answer rather than a failure — but still nothing happened, so the selection the
        // reader made is still the selection they meant.
        respond(200, []);
        const { wrapper, table } = actions(["album-1"]);

        await entries(wrapper)[0].trigger("click");
        await flushPromises();

        expect(table.clearSelection).not.toHaveBeenCalled();
    });

    it("sends a track table's ticks as a `song` SUBJECT, so the server orders them", async () => {
        /*
         * Not as `{ tracks: [...] }`, which would be written in the order the boxes were
         * CLICKED — making a checkbox a position, which PlaylistAdditions says it is not, and
         * putting a book's chapters in a playlist in whatever order they were ticked. The
         * subject shape resolves album-then-disc-then-track, the same order pressing play
         * gives. Chapters survive it because `song` is exempt from the music-only filter on
         * both services (PlaylistAdditions, QueueSelection).
         */
        const { wrapper } = actions(["chapter-1", "chapter-2"], "song");

        await entries(wrapper)[2].trigger("click");
        await nextTick();

        document.querySelector<HTMLButtonElement>(".form-select__button")!.click();
        await nextTick();
        document.querySelectorAll<HTMLButtonElement>(".form-select__option")[0].click();
        await nextTick();

        document
            .querySelector("#add-to-playlist-form")!
            .dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));

        expect(routerCalls[0].data).toEqual({ subject: "song", ids: ["chapter-1", "chapter-2"] });
    });

    it("sends a CONTAINER table's ticked rows as a subject, for the server to expand", async () => {
        const { wrapper } = actions(["genre-1"], "genre");

        await entries(wrapper)[2].trigger("click");
        await nextTick();

        // Modal teleports to <body>, so the form is queried off the document.
        document.querySelector<HTMLButtonElement>(".form-select__button")!.click();
        await nextTick();
        document.querySelectorAll<HTMLButtonElement>(".form-select__option")[0].click();
        await nextTick();

        document
            .querySelector("#add-to-playlist-form")!
            .dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));

        expect(routerCalls[0].url).toBe("/playlists/p1/tracks");
        expect(routerCalls[0].data).toEqual({ subject: "genre", ids: ["genre-1"] });
    });

    it("drops the ticks when the playlist write lands, and not before", async () => {
        const { wrapper, table } = actions(["genre-1"], "genre");

        await entries(wrapper)[2].trigger("click");
        await nextTick();

        document.querySelector<HTMLButtonElement>(".form-select__button")!.click();
        await nextTick();
        document.querySelectorAll<HTMLButtonElement>(".form-select__option")[0].click();
        await nextTick();

        document
            .querySelector("#add-to-playlist-form")!
            .dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));

        expect(table.clearSelection).not.toHaveBeenCalled();

        (routerCalls[0].options!.onSuccess as () => void)();

        expect(table.clearSelection).toHaveBeenCalledTimes(1);
    });
});
