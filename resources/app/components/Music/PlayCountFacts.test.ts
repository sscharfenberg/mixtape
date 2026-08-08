import { beforeEach, describe, expect, it, vi } from "vitest";
import { notifyPlayRecorded, resetPlayEventsForTests } from "Composables/usePlayEvents";
import { resetInertia, routerCalls } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import PlayCountFacts from "./PlayCountFacts.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The two listening tiles, shared by the song / artist / genre / album heroes.
 *
 * These assertions were the SongPage's until the tiles were extracted, and they moved here
 * with the markup: the counts are the server's, but WHETHER TO SAY ANYTHING is this
 * component's decision, and there is no point re-asserting it once per page that renders it.
 * assertInertia already pins that each controller sends the numbers (tests/Feature/Music).
 *
 * The live refresh is the half only this layer can answer. It is a `watch` over a module
 * singleton driving `router.reload`, which PHP cannot see and which a browser test could
 * only reach by playing a real track to its threshold — impossible against the E2E
 * fixture's one-second audio (docs/player.md → What counts as a play).
 */

/** Mount the tiles. */
const facts = (
    plays: { own: number; others: number },
    subject: "song" | "artist" | "genre" | "album" = "song",
    locale: "de" | "en" = "de"
) => mountApp(PlayCountFacts, { props: { plays, subject }, locale });

/** The rendered tiles, as `label value` text with the hidden description stripped off. */
const tiles = (wrapper: ReturnType<typeof facts>): string[] =>
    wrapper.findAll(".fact-pair").map(node => {
        const description = node.find(".sr-only");

        return description.exists() ? node.text().replace(description.text(), "") : node.text();
    });

describe("PlayCountFacts", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayEventsForTests();
    });

    describe("what it says, and what it leaves unsaid", () => {
        it("says nothing at all about something nobody has played", () => {
            // A fresh library would otherwise be a wall of "0×" saying only that the
            // feature exists.
            expect(tiles(facts({ own: 0, others: 0 }))).toStrictEqual([]);
        });

        it("counts the reader's own listens", () => {
            expect(tiles(facts({ own: 3, others: 0 }))).toStrictEqual(["Von dir3×"]);
        });

        it("counts everybody else's separately", () => {
            expect(tiles(facts({ own: 0, others: 5 }))).toStrictEqual(["Von anderen5×"]);
        });

        it("shows both when both have happened, the reader's first", () => {
            expect(tiles(facts({ own: 2, others: 4 }))).toStrictEqual(["Von dir2×", "Von anderen4×"]);
        });

        it("needs no plural rule, which is what the tile format buys", () => {
            // As a sentence this was a real fork — German wants "einmal", not "1-mal" — and
            // as a tile it is simply the figure.
            expect(tiles(facts({ own: 1, others: 0 }, "song", "de"))).toStrictEqual(["Von dir1×"]);
            expect(tiles(facts({ own: 1, others: 0 }, "song", "en"))).toStrictEqual(["By you1×"]);
        });
    });

    describe("explaining the number", () => {
        it("explains it to everyone, not only to a pointer", () => {
            /*
             * Three things the figure alone cannot answer: what counts as a play, whether
             * repeats count, and what the subject's total includes. The tooltip says them —
             * and `v-tooltip` is pointer-and-focus only, so the same sentence is also a
             * description, which is the half a test can read.
             */
            const wrapper = facts({ own: 3, others: 5 });
            const described = wrapper.findAll(".fact-pair").filter(tile => tile.attributes("aria-describedby") !== undefined);

            expect(described).toHaveLength(2);

            // And the wiring holds: each tile points at an element that really exists and
            // really carries its sentence. A duplicated or stale id is the failure here.
            for (const tile of described) {
                const target = wrapper.find(`#${tile.attributes("aria-describedby")}`);

                expect(target.exists()).toBe(true);
                expect(target.text().length).toBeGreaterThan(0);
            }
        });

        it("describes nothing it is not showing", () => {
            const wrapper = facts({ own: 0, others: 4 });

            expect(wrapper.findAll(".sr-only")).toHaveLength(1);
            expect(wrapper.find(".sr-only").text()).toBe(translate("music.plays.song.othersTip"));
        });

        it("names the subject, so an artist is not explained as a song", () => {
            // The whole reason `subject` exists. Four pages, one component, four sentences.
            const forSubject = (subject: "song" | "artist" | "genre" | "album") =>
                facts({ own: 1, others: 0 }, subject).find(".sr-only").text();

            expect(forSubject("song")).toBe(translate("music.plays.song.ownTip"));
            expect(forSubject("artist")).toBe(translate("music.plays.artist.ownTip"));
            expect(forSubject("genre")).toBe(translate("music.plays.genre.ownTip"));
            expect(forSubject("album")).toBe(translate("music.plays.album.ownTip"));
        });
    });

    describe("keeping itself up to date", () => {
        it("re-reads only the plays prop when the server has recorded a listen", async () => {
            const wrapper = facts({ own: 1, others: 0 });

            notifyPlayRecorded("track-1");
            await wrapper.vm.$nextTick();

            expect(routerCalls).toHaveLength(1);
            expect(routerCalls[0].method).toBe("reload");
            // `only` is the whole point: without it the reload re-serialises the page's
            // table, discography and hero for the sake of two integers.
            expect(routerCalls[0].options).toStrictEqual({ only: ["plays"] });
        });

        it("asks again for every listen, rather than only for the first", async () => {
            const wrapper = facts({ own: 1, others: 0 });

            notifyPlayRecorded("track-1");
            await wrapper.vm.$nextTick();
            notifyPlayRecorded("track-2");
            await wrapper.vm.$nextTick();

            expect(routerCalls).toHaveLength(2);
        });

        it("does not ask on mount, only on a listen", async () => {
            // The counts already came with the page. A reload here would double every
            // detail-page visit for nothing.
            const wrapper = facts({ own: 4, others: 2 });
            await wrapper.vm.$nextTick();

            expect(routerCalls).toStrictEqual([]);
        });

        it("stops asking once the page it was on is gone", async () => {
            // A listen recorded after the reader has navigated away must not send a reload
            // at a page that no longer exists — the watcher dies with the component.
            const wrapper = facts({ own: 1, others: 0 });
            wrapper.unmount();

            notifyPlayRecorded("track-1");
            await Promise.resolve();

            expect(routerCalls).toStrictEqual([]);
        });
    });
});
