import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetPlayEventsForTests, usePlayEvents } from "Composables/usePlayEvents";
import { resetInertia, setPage } from "Testing/inertia";
import { reportPlay } from "./playBeacon";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The beacon that records a listen, and the one thing it says about a request it otherwise
 * ignores: that the server accepted it.
 *
 * That signal is what keeps a play count on screen from going stale, so the rule worth
 * pinning is WHEN it fires. `fetch` resolves just as happily for a 419 or a 500 as for the
 * 204 that means a row was written — and a page told to refresh after a failed write
 * re-fetches the number it already has, which is indistinguishable from a count that
 * refuses to move.
 */

/** The signal the beacon writes to. Read fresh each time — it is a module singleton. */
const { playsRecorded, lastPlayedTrackId } = usePlayEvents();

/** Stand in for the network with one canned response, and hand back the spy. */
const answerWith = (response: Partial<Response> | Error) => {
    const fetchMock = vi.fn(() => (response instanceof Error ? Promise.reject(response) : Promise.resolve(response as Response)));

    vi.stubGlobal("fetch", fetchMock);

    return fetchMock;
};

/** Let the beacon's promise chain settle — it is deliberately not awaited by the caller. */
const settle = () => new Promise(resolve => setTimeout(resolve, 0));

describe("playBeacon", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayEventsForTests();
        vi.unstubAllGlobals();
        setPage({ props: { auth: { user: { id: "user-1" } }, csrfToken: "token-1" } });
    });

    it("posts the track to the plays route, with the CSRF token by hand", async () => {
        // Not an Inertia visit, so nothing adds the token for it.
        const fetchMock = answerWith({ ok: true, status: 204 });

        reportPlay("track-1");
        await settle();

        expect(fetchMock).toHaveBeenCalledTimes(1);

        const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];

        expect(url).toBe("/player/plays");
        expect(init.method).toBe("POST");
        expect((init.headers as Record<string, string>)["X-CSRF-TOKEN"]).toBe("token-1");
        expect(JSON.parse(init.body as string)).toStrictEqual({ trackId: "track-1" });
    });

    it("announces the listen once the server has accepted it", async () => {
        answerWith({ ok: true, status: 204 });

        reportPlay("track-1");
        await settle();

        expect(playsRecorded.value).toBe(1);
        expect(lastPlayedTrackId.value).toBe("track-1");
    });

    it("stays quiet when the server refuses, though the promise resolved", async () => {
        // The regression this exists for: `fetch` resolving is not the row being written.
        answerWith({ ok: false, status: 419 });

        reportPlay("track-1");
        await settle();

        expect(playsRecorded.value).toBe(0);
    });

    it("stays quiet when the request never lands at all", async () => {
        answerWith(new Error("offline"));

        reportPlay("track-1");
        await settle();

        expect(playsRecorded.value).toBe(0);
    });

    it("records nothing for a listener who is not signed in", async () => {
        // `plays.user_id` is not nullable — a guest on a share link has no history to add
        // to, so there is no request to make and nothing to announce.
        resetInertia();
        setPage({ props: { csrfToken: "token-1" } });
        const fetchMock = answerWith({ ok: true, status: 204 });

        reportPlay("track-1");
        await settle();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(playsRecorded.value).toBe(0);
    });
});
