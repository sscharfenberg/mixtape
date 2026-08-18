import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import type { SongFilterStat, SongStats } from "Types/music";
import SongsStats from "./SongsStats.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * What this strip DECIDES is small and all of it is invisible to PHP: which glyph stands for a
 * filter, which word its link wears, and whether a link is drawn at all. The counts and the
 * hrefs are the server's (SongsStatsTest owns those), and the tile layout is StatTiles'.
 *
 * The word matters more than it looks. The same href means two different things depending on
 * `active` — a way IN to a filtered table, or the way back OUT of one — so a tile that said
 * "anzeigen" while showing the door out would send a reader in a circle, with nothing on screen
 * to explain why the table did not change.
 */

/** One filter tile, defaulted to a count worth following. */
const stat = (overrides: Partial<SongFilterStat> = {}): SongFilterStat => ({
    key: "never-played",
    count: 412,
    href: "/music/songs?filter=never-played",
    active: false,
    ...overrides
});

/** Mount the strip over a payload. */
const strip = (stats: Partial<SongStats> = {}) =>
    mountApp(SongsStats, { props: { total: 12074, filters: [stat()], ...stats } });

/** The links the tiles offer, by their text. */
const actions = (wrapper: ReturnType<typeof strip>): string[] =>
    wrapper.findAll(".widget-stats__action").map(node => node.text());

describe("SongsStats", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("leads with the library's size, formatted for the reader's locale", () => {
        // Raw from the server, grouped here — 12074 reads as five digits otherwise, and the
        // separator is not the same character in every language.
        expect(strip().find(".widget-stats__part").text()).toBe("12.074");
        expect(
            mountApp(SongsStats, { props: { total: 12074, filters: [] }, locale: "en" })
                .find(".widget-stats__part")
                .text()
        ).toBe("12,074");
    });

    it("draws the total plus one tile per filter the server sent", () => {
        const wrapper = strip({
            filters: [
                stat(),
                stat({ key: "added-this-week", count: 3, href: "/music/songs?filter=added-this-week" })
            ]
        });

        expect(wrapper.findAll(".widget-stats__cell")).toHaveLength(3);
    });

    it("labels each filter by its own key, so a new one needs no mapping here", () => {
        const wrapper = strip({ filters: [stat({ key: "no-cover", href: "/music/songs?filter=no-cover" })] });

        expect(wrapper.text()).toContain(translate("music.songFilters.label.no-cover"));
    });

    it("offers 'show' on a tile that leads into a filtered table", () => {
        expect(actions(strip())).toStrictEqual([translate("music.listingFilters.show")]);
        expect(strip().find(".widget-stats__action").attributes("href")).toBe("/music/songs?filter=never-played");
    });

    it("offers 'show all' on the tile whose filter is already applied", () => {
        // Same href field, opposite direction: the server points an active tile at the
        // unfiltered listing, and the word has to say so or the link reads as a no-op.
        const wrapper = strip({ filters: [stat({ href: "/music/songs", active: true })] });

        expect(actions(wrapper)).toStrictEqual([translate("music.listingFilters.showAll")]);
        expect(wrapper.find(".widget-stats__action").attributes("href")).toBe("/music/songs");
    });

    it("marks the tile whose filter is applied, not just its link's word", () => {
        // A reader who arrived at a filtered URL rather than by pressing a tile has read nothing
        // yet — the mark is what says which question the short table is answering.
        const wrapper = strip({ filters: [stat({ href: "/music/songs", active: true }), stat({ key: "no-cover" })] });

        expect(wrapper.findAll(".widget-stats__cell--active")).toHaveLength(1);
        expect(wrapper.find(".widget-stats__cell--active").text()).toContain(
            translate("music.songFilters.label.never-played")
        );
    });

    it("draws no link for a filter the server sent no href for", () => {
        // Which is how a count of zero arrives. The tile still shows its 0 — that a library has
        // nothing filed twice is worth reading — it just has nowhere to send anybody.
        const wrapper = strip({ filters: [stat({ key: "duplicates", count: 0, href: null })] });

        expect(actions(wrapper)).toStrictEqual([]);
        expect(wrapper.text()).toContain("0");
    });

    it("gives every filter a glyph of its own", () => {
        // Four filters, four different icons — a strip where two tiles wear one glyph reads as a
        // duplicate rather than as two questions.
        const keys: SongFilterStat["key"][] = ["never-played", "added-this-week", "duplicates", "no-cover"];
        const wrapper = strip({ filters: keys.map(key => stat({ key })) });
        const icons = wrapper.findAll(".widget-stats__head .icon use").map(node => node.attributes("href"));

        expect(new Set(icons).size).toBe(icons.length);
    });
});
