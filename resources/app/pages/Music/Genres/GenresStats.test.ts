import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import type { GenreFilterStat, GenreStats } from "Types/music";
import GenresStats from "./GenresStats.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * Its own tests rather than the artists strip's, for the same reason that one has its own: the
 * glyphs, the wording and the draw-a-link decision are this component's code. What each count MEANS
 * — above all "one artist", which is not the listing's `artists` column — belongs to
 * GenresStatsTest.
 */

/** One filter tile, defaulted to a count worth following. */
const stat = (overrides: Partial<GenreFilterStat> = {}): GenreFilterStat => ({
    key: "one-artist",
    count: 110,
    href: "/music/genres?filter=one-artist",
    active: false,
    ...overrides
});

/** Mount the strip over a payload. */
const strip = (stats: Partial<GenreStats> = {}) =>
    mountApp(GenresStats, { props: { total: 140, filters: [stat()], ...stats } });

/** The links the tiles offer, by their text. */
const actions = (wrapper: ReturnType<typeof strip>): string[] =>
    wrapper.findAll(".widget-stats__action").map(node => node.text());

describe("GenresStats", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("leads with the listing's own total, in the collection card's words", () => {
        const wrapper = strip();

        expect(wrapper.find(".widget-stats__part").text()).toBe("140");
        expect(wrapper.text()).toContain(translate("music.stats.label.genres"));
    });

    it("labels each filter by its own key", () => {
        const wrapper = strip();

        expect(wrapper.text()).toContain(translate("music.genreFilters.label.one-artist"));
    });

    it("offers 'show' into a filtered table and 'show all' back out of one", () => {
        expect(actions(strip())).toStrictEqual([translate("music.listingFilters.show")]);

        const active = strip({ filters: [stat({ href: "/music/genres", active: true })] });

        expect(actions(active)).toStrictEqual([translate("music.listingFilters.showAll")]);
        expect(active.find(".widget-stats__action").attributes("href")).toBe("/music/genres");
        expect(active.findAll(".widget-stats__cell--active")).toHaveLength(1);
    });

    it("draws no link for a filter the server sent no href for", () => {
        const wrapper = strip({ filters: [stat({ count: 0, href: null })] });

        expect(actions(wrapper)).toStrictEqual([]);
        expect(wrapper.text()).toContain("0");
    });

    it("gives every tile a glyph of its own", () => {
        const keys: GenreFilterStat["key"][] = ["never-played", "one-artist", "added-this-week", "one-song"];
        const wrapper = strip({ filters: keys.map(key => stat({ key })) });
        const icons = wrapper.findAll(".widget-stats__head .icon use").map(node => node.attributes("href"));

        // Five tiles, five glyphs: the total plus one per filter. Two tiles wearing one glyph read
        // as a duplicate rather than as two questions.
        expect(icons).toHaveLength(5);
        expect(new Set(icons).size).toBe(icons.length);
    });
});
