import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetToastsForTests, useToast } from "Composables/useToast";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import type { UseSelectionActionsReturn } from "./useSelectionActions";
import { useSelectionActions } from "./useSelectionActions";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * "Play these" and "queue these" for a table's ticked rows.
 *
 * WHY A COMPOSABLE TEST: everything worth pinning is about the REQUEST and what is done with
 * its answer, none of which is visible in markup. SelectionActions has its own spec for what it
 * draws and when.
 *
 * What a reader would notice going wrong:
 *
 *   - AN EMPTY ANSWER AND A FAILED ONE MUST NOT SAY THE SAME THING. Ticking three albums that
 *     turn out to hold nothing playable is "nothing here"; a 500 is "that did not work". The
 *     composable returns `[]` for the first and null for the second precisely so these two
 *     sentences cannot be swapped, and swapping them is invisible to types.
 *   - PLAY REPLACES, ENQUEUE APPENDS. The same rule useSubjectTracks holds, and the one thing a
 *     listener would notice instantly if it inverted.
 *   - IT MUST NOT RESOLVE TWICE. The buttons are disabled while in flight, but a keyboard repeat
 *     can outrun a render — and for enqueue a second pass means the selection queued twice.
 *
 * The CSRF token is asserted because it is the header Inertia would normally add for us: this is
 * a plain `fetch`, so forgetting it is a 419 that only shows up against a real server.
 */

/** A queue entry as the endpoint hands it back. */
const track = (id: string) => ({
    id,
    name: `Track ${id}`,
    artist: "Slowdive",
    album: "Souvlaki",
    href: `/music/songs/${id}`,
    coverUrl: null,
    streamUrl: `/music/songs/${id}/stream`,
    duration: 200
});

/** Mount a throwaway component just to run the composable inside a Vue setup scope. */
const actions = (): UseSelectionActionsReturn => {
    let api!: UseSelectionActionsReturn;

    setPage({ props: { auth: { user: null }, csrfToken: "a-token" } });
    mountApp(
        defineComponent({
            setup() {
                api = useSelectionActions();

                return () => null;
            }
        })
    );

    return api;
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

/** The messages currently on screen, so a test can say which sentence was raised. */
const toastMessages = (): string[] => useToast().activeToasts.value.map(toast => toast.message);

describe("useSelectionActions", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        resetToastsForTests();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it("asks the endpoint for the ticked rows, with the kind and the CSRF token", async () => {
        respond(200, [track("a")]);

        await actions().playSelection("album", ["album-1", "album-2"]);

        const [url, init] = vi.mocked(fetch).mock.calls[0] as [string, RequestInit];

        expect(url).toBe("/queue/tracks");
        expect(init.method).toBe("POST");
        expect((init.headers as Record<string, string>)["X-CSRF-TOKEN"]).toBe("a-token");
        expect(JSON.parse(init.body as string)).toEqual({ subject: "album", ids: ["album-1", "album-2"] });
    });

    it("play REPLACES the queue and enqueue APPENDS to it", async () => {
        const queue = usePlayerQueue();
        queue.playNow([track("already-there")]);

        respond(200, [track("a"), track("b")]);
        await actions().enqueueSelection("song", ["a", "b"]);

        expect(queue.tracks.value.map(entry => entry.id)).toEqual(["already-there", "a", "b"]);

        respond(200, [track("c")]);
        await actions().playSelection("song", ["c"]);

        expect(queue.tracks.value.map(entry => entry.id)).toEqual(["c"]);
    });

    it("says 'nothing playable' when the rows resolve to no tracks, and leaves the queue alone", async () => {
        // Three ticked albums that turn out to hold only audiobook chapters. A real answer, not
        // a failure — so the sentence is about the music, not about the request.
        const queue = usePlayerQueue();
        queue.playNow([track("already-there")]);

        respond(200, []);

        await expect(actions().playSelection("album", ["album-1"])).resolves.toBe(false);
        expect(toastMessages()).toEqual(["Hier ist nichts abspielbar."]);
        expect(queue.tracks.value.map(entry => entry.id)).toEqual(["already-there"]);
    });

    it("says something DIFFERENT when the request itself fails", async () => {
        respond(500);

        await expect(actions().playSelection("song", ["a"])).resolves.toBe(false);
        expect(toastMessages()).toEqual(["Die Auswahl konnte nicht geladen werden."]);
    });

    it("gives the 429 and the 422 their own messages", async () => {
        respond(429);
        await actions().playSelection("song", ["a"]);

        respond(422);
        await actions().playSelection("song", ["a"]);

        expect(toastMessages()).toEqual([
            "Das war gerade sehr viel auf einmal — bitte einen Moment warten.",
            "Diese Auswahl ist zu groß — bitte weniger auswählen."
        ]);
    });

    it("survives the network being gone", async () => {
        vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("offline")));

        await expect(actions().enqueueSelection("song", ["a"])).resolves.toBe(false);
        expect(toastMessages()).toEqual(["Die Auswahl konnte nicht geladen werden."]);
    });

    it("refuses a second press while the first is still in flight, and says nothing about it", async () => {
        // The press that loses is not a mistake — the first one is still working — so it must
        // not raise a toast of its own.
        let release!: (value: unknown) => void;
        const pending = new Promise(resolve => {
            release = resolve;
        });

        vi.stubGlobal(
            "fetch",
            vi.fn().mockReturnValue(
                pending.then(() => ({ ok: true, status: 200, json: () => Promise.resolve([track("a")]) }))
            )
        );

        const api = actions();
        const first = api.enqueueSelection("song", ["a"]);
        const second = api.enqueueSelection("song", ["a"]);

        await expect(second).resolves.toBe(false);
        expect(toastMessages()).toEqual([]);

        release(null);
        await first;

        expect(vi.mocked(fetch)).toHaveBeenCalledTimes(1);
        expect(usePlayerQueue().tracks.value.map(entry => entry.id)).toEqual(["a"]);
    });

    it("does not go to the server for an empty selection", async () => {
        respond(200, [track("a")]);

        await expect(actions().playSelection("song", [])).resolves.toBe(false);

        expect(vi.mocked(fetch)).not.toHaveBeenCalled();
        expect(toastMessages()).toEqual([]);
    });

    it("raises the busy flag for the length of the request", async () => {
        respond(200, [track("a")]);

        const api = actions();
        const inFlight = api.playSelection("song", ["a"]);

        expect(api.busy.value).toBe(true);

        await inFlight;

        expect(api.busy.value).toBe(false);
    });
});
