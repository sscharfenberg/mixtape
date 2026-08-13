import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, nextTick } from "vue";
import { resetInertia, routerCalls } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import type { SearchGroup } from "Types/search";
import type { UseLibrarySearchReturn } from "./useLibrarySearch";
import { useLibrarySearch } from "./useLibrarySearch";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The client half of the cross-kind search — docs/search.md → "On the client".
 *
 * WHY THIS IS A COMPOSABLE TEST: everything here is about WHEN a request is made and WHICH
 * answer is allowed to paint, and none of it is visible in markup. What SearchResults draws is
 * its own file's business; what the server decides is pinned by tests/Feature/Search.
 *
 * The four things a reader would notice going wrong, in the order they would bite:
 *
 *   1. A STALE ANSWER PAINTING. A slow response for "bla" landing after "black" shows rows that
 *      do not match what is on screen, which reads as the search being WRONG rather than late —
 *      by far the worst failure available here, and the reason the abort exists.
 *   2. A REQUEST PER KEYSTROKE. Five requests for one typed word is five times the load and
 *      four answers nobody sees.
 *   3. A TWO-CHARACTER QUERY BEING ASKED. It matches half the library and cannot use the
 *      trigram index; the floor is the client's half of a rule the server also enforces.
 *   4. A CHIP THAT DOES NOT NARROW. The chips and the results are one question; a chip that
 *      failed to reach the URL would silently show every kind while claiming one.
 *
 * Fake timers throughout, because the debounce is the subject rather than an inconvenience:
 * asserting "no request yet" is only meaningful if the clock is under the test's control.
 */

/** Mount a throwaway component just to run the composable inside a Vue setup scope. */
const librarySearch = (options: { onNavigate?: () => void } = {}): UseLibrarySearchReturn => {
    let api!: UseLibrarySearchReturn;

    mountApp(
        defineComponent({
            setup() {
                api = useLibrarySearch(options);

                return () => null;
            }
        })
    );

    return api;
};

/** One group with `count` rows, named so an assertion can tell two answers apart. */
const group = (kind: SearchGroup["kind"], names: string[], seeAll: string | null = null): SearchGroup => ({
    kind,
    total: names.length,
    rows: names.map((name, index) => ({
        id: `${kind}-${index}`,
        name,
        href: `/${kind}/${index}`,
        facts: {}
    })),
    seeAll
});

/** Stand in for the endpoint with one canned answer for every call. */
const respond = (groups: SearchGroup[]): ReturnType<typeof vi.fn> => {
    const fetchMock = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ groups })
    });
    vi.stubGlobal("fetch", fetchMock);

    return fetchMock;
};

/**
 * Let the debounce fire and the promise chain settle.
 *
 * Both halves are needed and neither is enough: advancing the clock queues the `fetch`, and
 * only the microtask drain lets its two `await`s (the response, then `json()`) resolve.
 */
const settle = async (ms = 200): Promise<void> => {
    await vi.advanceTimersByTimeAsync(ms);
    await nextTick();
};

describe("useLibrarySearch", () => {
    beforeEach(() => {
        resetInertia();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    describe("when to ask", () => {
        it("waits for the reader to stop typing before asking anything", async () => {
            const fetchMock = respond([group("song", ["Black Dog"])]);
            const search = librarySearch();

            // One "word", typed a character at a time inside the debounce window.
            for (const query of ["bla", "blac", "black"]) {
                search.query.value = query;
                await vi.advanceTimersByTimeAsync(50);
            }

            expect(fetchMock).not.toHaveBeenCalled();

            await settle();

            expect(fetchMock).toHaveBeenCalledTimes(1);
            expect(fetchMock.mock.calls[0][0]).toContain("q=black");
        });

        it("says it is working from the keystroke, not from the request", async () => {
            respond([group("song", ["Black Dog"])]);
            const search = librarySearch();

            search.query.value = "black";
            await nextTick();

            // Still inside the debounce: nothing has been asked, and the field must not look
            // like it answered "nothing".
            expect(search.loading.value).toBe(true);

            await settle();

            expect(search.loading.value).toBe(false);
        });

        it("refuses to ask about fewer than three characters", async () => {
            const fetchMock = respond([]);
            const search = librarySearch();

            search.query.value = "bl";
            await settle();

            expect(fetchMock).not.toHaveBeenCalled();
            expect(search.tooShort.value).toBe(true);
            // …and it says so rather than sitting blank: the block is open, just not answering.
            expect(search.active.value).toBe(true);
            expect(search.loading.value).toBe(false);
        });

        it("measures the floor against the trimmed query, not the keystrokes", async () => {
            const fetchMock = respond([]);
            const search = librarySearch();

            search.query.value = "  a  ";
            await settle();

            expect(fetchMock).not.toHaveBeenCalled();
            expect(search.tooShort.value).toBe(true);
        });

        it("forgets everything when the field is emptied", async () => {
            respond([group("song", ["Black Dog"])]);
            const search = librarySearch();

            search.query.value = "black";
            await settle();
            expect(search.groups.value).toHaveLength(1);

            search.query.value = "";
            await nextTick();

            expect(search.active.value).toBe(false);
            expect(search.groups.value).toEqual([]);
            expect(search.loading.value).toBe(false);
        });
    });

    describe("which answer paints", () => {
        /**
         * THE ONE THAT MATTERS. Two overlapping requests, the older one resolving LAST — which is
         * exactly the order that puts the wrong rows on screen, and the order a real network
         * produces whenever the first query is the more expensive one.
         */
        it("discards an answer to a question the reader has moved on from", async () => {
            const slow = group("song", ["a stale row"]);
            const fresh = group("song", ["the right row"]);

            let releaseSlow!: (value: unknown) => void;
            const fetchMock = vi
                .fn()
                .mockImplementationOnce(
                    () =>
                        new Promise(resolve => {
                            releaseSlow = resolve;
                        })
                )
                .mockResolvedValueOnce({ ok: true, status: 200, json: () => Promise.resolve({ groups: [fresh] }) });
            vi.stubGlobal("fetch", fetchMock);

            const search = librarySearch();

            search.query.value = "bla";
            await settle();

            search.query.value = "black";
            await settle();

            expect(search.groups.value[0].rows[0].name).toBe("the right row");

            // The first request answers now — for a query nobody is looking at any more.
            releaseSlow({ ok: true, status: 200, json: () => Promise.resolve({ groups: [slow] }) });
            await nextTick();
            await nextTick();

            expect(search.groups.value[0].rows[0].name).toBe("the right row");
        });

        /**
         * Only a request still IN FLIGHT is aborted — one that has already answered has nothing to
         * cancel, so the first call here is left hanging on purpose. Without the hang this test
         * would pass against an implementation that never aborted anything.
         */
        it("aborts the request it is superseding", async () => {
            const signals: AbortSignal[] = [];
            const fetchMock = vi
                .fn()
                .mockImplementationOnce(
                    (_url: string, init: RequestInit) =>
                        new Promise(() => {
                            signals.push(init.signal as AbortSignal);
                        })
                )
                .mockImplementationOnce((_url: string, init: RequestInit) => {
                    signals.push(init.signal as AbortSignal);

                    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ groups: [] }) });
                });
            vi.stubGlobal("fetch", fetchMock);

            const search = librarySearch();

            search.query.value = "bla";
            await settle();
            search.query.value = "black";
            await settle();

            expect(signals).toHaveLength(2);
            expect(signals[0].aborted).toBe(true);
            expect(signals[1].aborted).toBe(false);
        });

        it("reports a refusal without pretending the library is empty", async () => {
            vi.stubGlobal(
                "fetch",
                vi.fn().mockResolvedValue({ ok: false, status: 429, json: () => Promise.resolve({}) })
            );

            const search = librarySearch();
            search.query.value = "black";
            await settle();

            expect(search.failed.value).toBe(true);
            expect(search.groups.value).toEqual([]);
            expect(search.loading.value).toBe(false);
        });
    });

    describe("the chips", () => {
        it("narrows the request to one kind", async () => {
            const fetchMock = respond([group("album", ["Black Album"])]);
            const search = librarySearch();

            search.query.value = "black";
            await settle();
            expect(fetchMock.mock.calls[0][0]).not.toContain("kinds=");

            search.scope.value = "album";
            await settle();

            expect(fetchMock).toHaveBeenCalledTimes(2);
            expect(fetchMock.mock.calls[1][0]).toContain("kinds=album");
        });

        /** A click is not a burst — waiting a fifth of a second after one reads as lag. */
        it("asks straight away rather than waiting out the typing debounce", async () => {
            const fetchMock = respond([]);
            const search = librarySearch();

            search.query.value = "black";
            await settle();

            search.scope.value = "genre";
            // No clock advance at all: just let the promise chain run.
            await nextTick();

            expect(fetchMock).toHaveBeenCalledTimes(2);
        });
    });

    describe("the keyboard walk", () => {
        /** Two groups, the second offering a hand-off — so the walk covers three rows and a link. */
        const walkable = async (): Promise<UseLibrarySearchReturn> => {
            respond([
                group("artist", ["Black Sabbath"]),
                group("song", ["Black Dog", "Back in Black"], "/music/songs?search=black")
            ]);
            const search = librarySearch();
            search.query.value = "black";
            await settle();

            return search;
        };

        const press = (search: UseLibrarySearchReturn, key: string): KeyboardEvent => {
            const event = new KeyboardEvent("keydown", { key, cancelable: true });
            search.onKeydown(event);

            return event;
        };

        it("starts nowhere, so the first press does not skip a row", async () => {
            const search = await walkable();

            expect(search.activeOptionId.value).toBeUndefined();

            press(search, "ArrowDown");
            expect(search.activeOptionId.value).toBe(`${search.listboxId}-artist-artist-0`);
        });

        it("walks across a group boundary and onto the hand-off", async () => {
            const search = await walkable();

            press(search, "ArrowDown"); // the artist
            press(search, "ArrowDown"); // first song
            press(search, "ArrowDown"); // second song
            press(search, "ArrowDown"); // "see all in Songs" — a walkable option like any row

            expect(search.activeOptionId.value).toBe(`${search.listboxId}-song-all`);
        });

        it("wraps at both ends rather than stopping", async () => {
            const search = await walkable();

            // Up from nowhere is the LAST target, which is the only reading of -1 that isn't
            // arbitrary — and one press to reach the hand-off.
            press(search, "ArrowUp");
            expect(search.activeOptionId.value).toBe(`${search.listboxId}-song-all`);

            press(search, "ArrowDown");
            expect(search.activeOptionId.value).toBe(`${search.listboxId}-artist-artist-0`);
        });

        it("opens the walked row on Enter", async () => {
            const closed = vi.fn();
            respond([group("artist", ["Black Sabbath"])]);
            const search = librarySearch({ onNavigate: closed });
            search.query.value = "black";
            await settle();

            press(search, "ArrowDown");
            const event = press(search, "Enter");

            expect(event.defaultPrevented).toBe(true);
            const visits = routerCalls.filter(call => call.method === "visit");
            expect(visits).toHaveLength(1);
            expect(visits[0].url).toBe("/artist/0");
            // The host is told, which is how the overlay puts itself away.
            expect(closed).toHaveBeenCalledTimes(1);
        });

        /** Swallowing Enter on nothing would make a field that silently eats the obvious key. */
        it("leaves Enter alone when no row has been walked to", async () => {
            const search = await walkable();

            const event = press(search, "Enter");

            expect(event.defaultPrevented).toBe(false);
            expect(routerCalls.filter(call => call.method === "visit")).toHaveLength(0);
        });

        /**
         * Escape must NOT be prevented: in the header the field lives inside a native popover
         * whose light dismiss IS Escape, so preventing it would leave the overlay open and empty.
         */
        it("clears on Escape without swallowing the key", async () => {
            const search = await walkable();

            const event = press(search, "Escape");

            expect(event.defaultPrevented).toBe(false);
            expect(search.query.value).toBe("");
            expect(search.groups.value).toEqual([]);
        });

        it("forgets where it had got to when a new answer lands", async () => {
            const search = await walkable();

            press(search, "ArrowDown");
            expect(search.activeOptionId.value).toBeDefined();

            search.query.value = "blackest";
            await settle();

            expect(search.activeOptionId.value).toBeUndefined();
        });
    });

    /** Two boxes can be on one page — the overlay and the Music widget — and must not collide. */
    it("gives every mounting DOM ids of its own", () => {
        respond([]);

        expect(librarySearch().listboxId).not.toBe(librarySearch().listboxId);
    });
});
