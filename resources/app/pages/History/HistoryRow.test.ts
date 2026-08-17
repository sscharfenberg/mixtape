import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import type { HistoryPlay } from "./HistoryRow.vue";
import HistoryRow from "./HistoryRow.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * One listen, as a row.
 *
 * The server decides what a row HOLDS — which credit a kind is known by, where it leads — and
 * tests/Feature/History/HistoryPageTest.php pins all of it. What this layer owns is what the
 * row does with those fields:
 *
 *   - THE KIND PICKS THREE GLYPHS AND A WORD. A song and a chapter differ in nothing else — both
 *     have a title, a credit and a container — so an `author` glyph on a song, or "Song" over a
 *     book, is a mistake with no other symptom.
 *   - THE CLOCK IS THE READER'S. The server sends an instant and knows neither their timezone
 *     nor their language.
 *   - IT SHOWS THE TIME AND ANNOUNCES THE DATE. Under a heading that already says which day it
 *     is, a date on every row is noise — but read on its own by a screen reader, "23:30" is a
 *     time attached to nothing.
 *   - A MISSING FACT IS DROPPED, not drawn empty: a file crediting nobody, a loose track under
 *     no album.
 */

/** One listen; tests override only what they are about. */
const play = (overrides: Partial<HistoryPlay> = {}): HistoryPlay => ({
    id: "play-1",
    playedAt: "2026-08-16T21:30:00+00:00",
    kind: "music",
    name: "Paranoid Android",
    creator: "Radiohead",
    container: "OK Computer",
    href: "/music/songs/song-1",
    ...overrides
});

/** Mount one row. */
const row = (overrides: Partial<HistoryPlay> = {}) => mountApp(HistoryRow, { props: { play: play(overrides) } });

/** The row's glyphs, in DOM order — the kind pip, then one per fact. */
const glyphs = (wrapper: ReturnType<typeof row>) =>
    wrapper.findAll("use").map(node => node.attributes("href"));

describe("HistoryRow", () => {
    beforeEach(() => {
        resetInertia();
        setPage({ props: { auth: { user: { id: "user-1", name: "Ash", email: "a@b.c" } } } });
    });

    it("dresses a song as a song: its own glyph, an artist and an album", () => {
        expect(glyphs(row())).toStrictEqual(["#song", "#artist", "#album", "#recent"]);
        expect(row().find(".history-row__kind").text()).toBe(translate("history.kind.music"));
    });

    it("dresses a chapter as an audiobook: an AUTHOR beside it, and the book it is from", () => {
        // The one field this page's shape disagrees with a queue entry's about — an audiobook's
        // author hangs off the chapter (docs/audiobooks.md) — so the glyph has to follow the
        // kind rather than the position.
        const wrapper = row({ kind: "audiobook", creator: "H.P. Lovecraft", container: "Necrophobia 1" });

        expect(glyphs(wrapper)).toStrictEqual(["#audiobook", "#author", "#audiobook", "#recent"]);
        expect(wrapper.find(".history-row__kind").text()).toBe(translate("history.kind.audiobook"));
    });

    it("leads to the thing that was played, and offers nothing to press", () => {
        // A link, never a play button: a history that could add to itself would be the wrong
        // page. (Whether the link is PREFETCHED cannot be asserted here — the Inertia test
        // double declares `prefetch` purely to absorb it, so it never reaches the DOM.)
        const wrapper = row();

        expect(wrapper.find(".history-row__name").attributes("href")).toBe("/music/songs/song-1");
        expect(wrapper.findAll("button")).toHaveLength(0);
    });

    it("prints the clock in the reader's own locale, and no date", () => {
        // The section heading above already says which day this is; repeating it on every row
        // is what the time replaces. Read off the VISIBLE half of the pip: the other half is
        // the announced instant, which carries the date on purpose (see the test below).
        const visible = row({ playedAt: "2026-08-16T21:30:00+00:00" }).find(".history-row__when [aria-hidden]");

        expect(visible.text()).toBe("21:30");
    });

    it("announces the whole instant, since a bare time is attached to nothing", () => {
        const wrapper = row();

        expect(wrapper.find(".history-row__when .sr-only").text()).toBe(
            translate("history.playedAt").replace("{date}", "16.08.2026, 21:30:00")
        );
    });

    it("drops a fact the tags do not carry rather than drawing it empty", () => {
        const wrapper = row({ creator: null, container: null });

        expect(glyphs(wrapper)).toStrictEqual(["#song", "#recent"]);
        expect(wrapper.findAll(".history-row__fact")).toHaveLength(0);
    });
});
