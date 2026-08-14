import { describe, expect, it, vi } from "vitest";
import { iconNames, mountApp } from "Testing/mount";
import type { SearchFacts, SearchGroup, SearchRow } from "Types/search";
import SearchResults from "./SearchResults.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The result surface — one component drawn in the header overlay and in the Music page's widget.
 *
 * WHAT THIS LAYER IS FOR, and what it deliberately is not. The RANKING is the server's and is
 * pinned in tests/Feature/Search; the request timing is the composable's and is pinned beside it.
 * What only a rendering test can answer is what a reader is TOLD, and the two ways this component
 * could lie to them:
 *
 *   - "NOTHING FOUND" WHILE STILL LOOKING. A slow answer must not flash an empty state on its way
 *     in, and a query of two characters has not searched anything yet. Both are states with real
 *     copy, and both would otherwise render as "your library does not have that".
 *   - A COUNT PRESENTED AS A NAME. The server sends `count` and `text` raw, and which of the two a
 *     kind uses is fixed per kind — an artist's `12` is pluralised into "12 Alben" HERE, in the
 *     reader's own catalog. Getting that wrong is the exact failure the raw-values rule exists to
 *     prevent, and PHP cannot see it.
 *
 * The German catalog is the real one (mountApp), so a renamed key fails these rather than
 * rendering as itself.
 */

/** One row, carrying whichever facts the case is about. */
const row = (name: string, facts: SearchFacts = {}): SearchRow => ({
    id: `id-${name}`,
    name,
    href: `/somewhere/${name}`,
    facts
});

/** One group, defaulting to no hand-off. */
const group = (kind: SearchGroup["kind"], rows: SearchRow[], overrides: Partial<SearchGroup> = {}): SearchGroup => ({
    kind,
    total: rows.length,
    rows,
    seeAll: null,
    ...overrides
});

/** Mount with the states all off unless the case says otherwise. */
const render = (props: Partial<InstanceType<typeof SearchResults>["$props"]> = {}) =>
    mountApp(SearchResults, {
        props: {
            groups: [],
            listboxId: "results-1",
            loading: false,
            failed: false,
            tooShort: false,
            ...props
        }
    });

describe("SearchResults", () => {
    describe("the states, and which one wins", () => {
        it("asks for more characters rather than claiming nothing matched", () => {
            const wrapper = render({ tooShort: true });

            expect(wrapper.text()).toContain("mindestens 3 Zeichen");
            expect(wrapper.find('[role="listbox"]').exists()).toBe(false);
        });

        /** The one that reads as a broken feature: an empty state flashed while the answer is in flight. */
        it("says it is searching rather than showing an empty result", () => {
            const wrapper = render({ loading: true });

            expect(wrapper.text()).toContain("Wird gesucht");
            expect(wrapper.text()).not.toContain("nichts in der Sammlung");
        });

        it("says nothing matched only once there is an answer", () => {
            const wrapper = render();

            expect(wrapper.text()).toContain("nichts in der Sammlung");
        });

        it("reports a refusal as a refusal, not as an empty library", () => {
            const wrapper = render({ failed: true });

            expect(wrapper.text()).toContain("nicht geantwortet");
            expect(wrapper.find(".search-results__note--failed").exists()).toBe(true);
        });

        /** Rows outrank the spinner: a reader typing on must keep seeing the last real answer. */
        it("keeps the rows on screen while the next answer is in flight", () => {
            const wrapper = render({ loading: true, groups: [group("song", [row("Black Dog")])] });

            expect(wrapper.text()).toContain("Black Dog");
        });
    });

    describe("the groups", () => {
        it("draws them in the order it is given, without sorting", () => {
            const wrapper = render({
                groups: [group("artist", [row("Black Sabbath")]), group("song", [row("Black Dog")])]
            });

            const headings = wrapper.findAll(".search-results__heading").map(node => node.text());
            expect(headings[0]).toContain("Künstler");
            expect(headings[1]).toContain("Songs");
        });

        /** The heading shows the REAL total, which is what makes showing five of seventy-seven honest. */
        it("heads a group with its real total rather than the rows on screen", () => {
            const wrapper = render({ groups: [group("song", [row("Black Dog")], { total: 77 })] });

            expect(wrapper.find(".search-results__heading").text()).toContain("77");
        });

        it("names the group for assistive tech, so the visible heading can stay decorative", () => {
            const wrapper = render({ groups: [group("song", [row("Black Dog")], { total: 77 })] });

            expect(wrapper.find('[role="group"]').attributes("aria-label")).toBe("Songs: 77");
            expect(wrapper.find(".search-results__heading").attributes("aria-hidden")).toBe("true");
        });
    });

    describe("a row's facts, drawn as pips", () => {
        /** Two facts per kind, in the order the kind lists them. */
        it("draws the two pips its kind carries, in order", () => {
            const wrapper = render({
                groups: [group("artist", [row("Blackfield", { albums: 12, duration: 4322 })])]
            });

            const pips = wrapper.findAll(".search-results__pip-value").map(node => node.text());
            expect(pips).toEqual(["12", "1:12:02"]);
        });

        /** The raw-values rule: seconds arrive as seconds and are clocked in the reader's locale. */
        it("clocks a runtime rather than printing its seconds", () => {
            const wrapper = render({ groups: [group("song", [row("Black Dog", { duration: 285 })])] });

            expect(wrapper.find(".search-results__pip-value").text()).toBe("4:45");
        });

        it("prints a credit as it stands", () => {
            const wrapper = render({ groups: [group("song", [row("Black Dog", { artist: "Led Zeppelin" })])] });

            expect(wrapper.find(".search-results__pip-value").text()).toBe("Led Zeppelin");
        });

        /**
         * A MISSING FACT DRAWS NO PIP. Both cases are real — a file crediting nobody, an artist
         * who performs no tracks of their own — and "0:00" there reads as a broken row.
         */
        it("leaves out a fact the row does not have", () => {
            const wrapper = render({
                groups: [group("artist", [row("Black Compilations", { albums: 1, duration: null })])]
            });

            expect(wrapper.findAll(".search-results__pip")).toHaveLength(1);
            expect(wrapper.find(".search-results__pip-value").text()).toBe("1");
        });

        it("draws no pip row at all when the kind sent nothing", () => {
            const wrapper = render({ groups: [group("song", [row("Black Dog")])] });

            expect(wrapper.find(".search-results__pips").exists()).toBe(false);
        });

        /**
         * THE GLYPH IS THE PIP'S ONLY VISIBLE LABEL, so which one is drawn is the whole of what a
         * reader has to go on. Asserted as the group's own glyph followed by one per fact, in the
         * kind's order. (The words survive in the tooltip, which `v-tooltip` keeps on the element
         * rather than in an attribute — so what a spec can check here is the icons.)
         */
        it("gives each fact its own glyph, in the kind's order", () => {
            const wrapper = render({ groups: [group("genre", [row("Blackgaze", { artists: 3, songs: 40 })])] });

            expect(iconNames(wrapper)).toEqual(["genre", "artist", "song"]);
        });
    });

    describe("the hand-off", () => {
        it("names the total and the listing it leads to", () => {
            const wrapper = render({
                groups: [group("song", [row("Black Dog")], { total: 77, seeAll: "/music/songs?search=black" })]
            });

            const link = wrapper.find(".search-results__row--all");
            expect(link.text()).toBe("Alle 77 in Songs anzeigen");
            expect(link.attributes("href")).toBe("/music/songs?search=black");
        });

        it("is absent for a group that has none", () => {
            const wrapper = render({ groups: [group("playlist", [row("Roadtrip", { tracks: 3 })])] });

            expect(wrapper.find(".search-results__row--all").exists()).toBe(false);
        });
    });

    describe("the walked row", () => {
        it("marks itself selected for assistive tech and lit for everyone else", () => {
            const wrapper = render({
                groups: [group("song", [row("Black Dog"), row("Back in Black")])],
                activeOptionId: "results-1-song-id-Back in Black"
            });

            const rows = wrapper.findAll(".search-results__row");
            expect(rows[0].attributes("aria-selected")).toBe("false");
            expect(rows[1].attributes("aria-selected")).toBe("true");
            expect(rows[1].classes()).toContain("search-results__row--active");
        });

        /**
         * The ids are prefixed with the listbox's own, because both mountings can be on the page at
         * once — two elements sharing an id is a bug only a screen reader would show you.
         */
        it("stamps ids that cannot collide with the other mounting's", () => {
            const wrapper = render({ listboxId: "results-9", groups: [group("song", [row("Black Dog")])] });

            expect(wrapper.find(".search-results__row").attributes("id")).toBe("results-9-song-id-Black Dog");
        });
    });

    it("tells its host when a row is opened, so an overlay can put itself away", async () => {
        const wrapper = render({ groups: [group("song", [row("Black Dog")])] });

        await wrapper.find(".search-results__row").trigger("click");

        expect(wrapper.emitted("navigate")).toHaveLength(1);
    });
});
