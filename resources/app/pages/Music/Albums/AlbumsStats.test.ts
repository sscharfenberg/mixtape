import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import type { AlbumFilterStat, AlbumStats } from "Types/music";
import AlbumsStats from "./AlbumsStats.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The albums strip. It decides the same three things SongsStats does — glyph, wording, whether a
 * link is drawn — and those decisions are its OWN code rather than shared, so they are asserted
 * here as well rather than assumed from the songs tests. That duplication is the honest price of
 * two page-local strips; the day a third listing wants one, the tests are what will say whether
 * the shape has really repeated.
 *
 * The counts and hrefs belong to AlbumsStatsTest, which also owns the interesting one (a gap in
 * the track numbering, asked per disc).
 */

/** One filter tile, defaulted to a count worth following. */
const stat = (overrides: Partial<AlbumFilterStat> = {}): AlbumFilterStat => ({
    key: "incomplete",
    count: 12,
    href: "/music/albums?filter=incomplete",
    active: false,
    ...overrides
});

/** Mount the strip over a payload. */
const strip = (stats: Partial<AlbumStats> = {}) =>
    mountApp(AlbumsStats, { props: { total: 925, filters: [stat()], ...stats } });

/** The links the tiles offer, by their text. */
const actions = (wrapper: ReturnType<typeof strip>): string[] =>
    wrapper.findAll(".widget-stats__action").map(node => node.text());

describe("AlbumsStats", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("leads with the album count, in the collection card's own words", () => {
        // The same number that card shows, so it says it the same way — two phrasings for one
        // fact is one too many.
        const wrapper = strip();

        expect(wrapper.find(".widget-stats__part").text()).toBe("925");
        expect(wrapper.text()).toContain(translate("music.stats.label.albums"));
    });

    it("labels each filter by its own key", () => {
        const wrapper = strip({ filters: [stat({ key: "single-track" })] });

        expect(wrapper.text()).toContain(translate("music.albumFilters.label.single-track"));
    });

    it("offers 'show' into a filtered table and 'show all' back out of one", () => {
        expect(actions(strip())).toStrictEqual([translate("music.listingFilters.show")]);

        const active = strip({ filters: [stat({ href: "/music/albums", active: true })] });

        expect(actions(active)).toStrictEqual([translate("music.listingFilters.showAll")]);
        expect(active.find(".widget-stats__action").attributes("href")).toBe("/music/albums");
        expect(active.findAll(".widget-stats__cell--active")).toHaveLength(1);
    });

    it("draws no link for a filter the server sent no href for", () => {
        const wrapper = strip({ filters: [stat({ count: 0, href: null })] });

        expect(actions(wrapper)).toStrictEqual([]);
        expect(wrapper.text()).toContain("0");
    });

    it("gives every filter a glyph of its own", () => {
        const keys: AlbumFilterStat["key"][] = ["never-played", "added-this-week", "incomplete", "single-track"];
        const wrapper = strip({ filters: keys.map(key => stat({ key })) });
        const icons = wrapper.findAll(".widget-stats__head .icon use").map(node => node.attributes("href"));

        // Five tiles, five glyphs: the album total plus one per filter. A strip where two tiles
        // wear the same glyph reads as a duplicate rather than as two questions.
        expect(icons).toHaveLength(5);
        expect(new Set(icons).size).toBe(icons.length);
    });

    it("shares the two glyphs that mean the same thing on the songs strip", () => {
        // `mute` for never played and `recent` for new arrivals are the same questions there, so
        // a reader meeting them twice meets one glyph.
        const wrapper = strip({ filters: [stat({ key: "never-played" }), stat({ key: "added-this-week" })] });
        const icons = wrapper.findAll(".widget-stats__head .icon use").map(node => node.attributes("href"));

        expect(icons[1]).toContain("mute");
        expect(icons[2]).toContain("recent");
    });
});
