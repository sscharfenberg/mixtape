import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import type { ArtistFilterStat, ArtistStats } from "Types/music";
import ArtistsStats from "./ArtistsStats.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * Its own tests rather than the songs strip's, because the glyph table, the wording and the
 * draw-a-link decision are this component's own code (the two strips are deliberately not one).
 * The counts and hrefs belong to ArtistsStatsTest.
 */

/** One filter tile, defaulted to a count worth following. */
const stat = (overrides: Partial<ArtistFilterStat> = {}): ArtistFilterStat => ({
    key: "lookalike-name",
    count: 110,
    href: "/music/artists?filter=lookalike-name",
    active: false,
    ...overrides
});

/** Mount the strip over a payload. */
const strip = (stats: Partial<ArtistStats> = {}) =>
    mountApp(ArtistsStats, { props: { total: 632, filters: [stat()], ...stats } });

/** The links the tiles offer, by their text. */
const actions = (wrapper: ReturnType<typeof strip>): string[] =>
    wrapper.findAll(".widget-stats__action").map(node => node.text());

describe("ArtistsStats", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("leads with the listing's own total, in the collection card's words", () => {
        const wrapper = strip();

        expect(wrapper.find(".widget-stats__part").text()).toBe("632");
        expect(wrapper.text()).toContain(translate("music.stats.label.artists"));
    });

    it("labels each filter by its own key", () => {
        const wrapper = strip();

        expect(wrapper.text()).toContain(translate("music.artistFilters.label.lookalike-name"));
    });

    it("offers 'show' into a filtered table and 'show all' back out of one", () => {
        expect(actions(strip())).toStrictEqual([translate("music.listingFilters.show")]);

        const active = strip({ filters: [stat({ href: "/music/artists", active: true })] });

        expect(actions(active)).toStrictEqual([translate("music.listingFilters.showAll")]);
        expect(active.find(".widget-stats__action").attributes("href")).toBe("/music/artists");
        expect(active.findAll(".widget-stats__cell--active")).toHaveLength(1);
    });

    it("draws no link for a filter the server sent no href for", () => {
        const wrapper = strip({ filters: [stat({ count: 0, href: null })] });

        expect(actions(wrapper)).toStrictEqual([]);
        expect(wrapper.text()).toContain("0");
    });

    it("gives every tile a glyph of its own", () => {
        const keys: ArtistFilterStat["key"][] = ["never-played", "compilations-only", "added-this-month", "lookalike-name"];
        const wrapper = strip({ filters: keys.map(key => stat({ key })) });
        const icons = wrapper.findAll(".widget-stats__head .icon use").map(node => node.attributes("href"));

        // Five tiles, five glyphs: the total plus one per filter. Two tiles wearing one glyph read
        // as a duplicate rather than as two questions.
        expect(icons).toHaveLength(5);
        expect(new Set(icons).size).toBe(icons.length);
    });

    it("wears the songs strip's glyph for the question they share", () => {
        // `mute` for never played means one thing across the app; a second glyph for one question
        // would read as two questions.
        const wrapper = strip({ filters: [stat({ key: "never-played" })] });
        const icons = wrapper.findAll(".widget-stats__head .icon use").map(node => node.attributes("href"));

        expect(icons[1]).toContain("mute");
    });
});
