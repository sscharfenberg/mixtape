import { beforeEach, describe, expect, it, vi } from "vitest";
import SearchHub from "Components/Search/SearchHub.vue";
import Tooltip from "Components/UI/Tooltip/Tooltip.vue";
import { resetInertia } from "Testing/inertia";
import type { TestLocale } from "Testing/mount";
import { iconNames, mountApp, translate } from "Testing/mount";
import type { CollectionStats } from "Types/music";
import StatsWidget from "./StatsWidget.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * What this card decides is how a raw number becomes a printed one — which is the whole of what
 * PHP cannot see here. MusicPageTest already pins the props it receives (`assertInertia`), so
 * nothing below re-checks that a number arrived; every case is about the locale or the catalogue.
 *
 * THE ONE TO PROTECT IS THE YEAR RANGE, because it is this card's exception to its own rule: every
 * count on it goes through `formatDecimals` and gets its locale's separators, and the years must
 * NOT — a German "1.965–2.024" reads as two quantities rather than as a span. A tidying pass that
 * makes the card "consistent" is exactly the change that should fail here.
 *
 * The second is the tile disappearing. The server sends null for both ends when no album carries a
 * year (SQL's MIN/MAX skip untagged rows), and a dash with blanks either side would be worse than
 * one fewer fact — so the card must draw the other tiles and nothing where that one was.
 */

/** A fully-tagged library, with numbers big enough that a locale's separators are visible. */
const stats = (overrides: Partial<CollectionStats> = {}): CollectionStats => ({
    songs: 12074,
    sizeBytes: 96 * 1024 ** 3,
    playtimeSeconds: 2 * 86400 + 3 * 3600 + 4 * 60 + 5,
    albums: 1238,
    artists: 639,
    genres: 71,
    firstYear: 1965,
    lastYear: 2024,
    ...overrides
});

/**
 * Mount the card over one library, in German unless a case is about the other locale.
 *
 * `searchable` defaults to the component's own default rather than being spelled out, so every
 * case above meets the card as the Music page builds it; only the two cases about the field
 * pass it.
 */
const widget = (overrides: Partial<CollectionStats> = {}, locale: TestLocale = "de", searchable = true) =>
    mountApp(StatsWidget, { props: { ...stats(overrides), searchable }, locale });

/**
 * A tile's label, looked up rather than spelled out.
 *
 * These are the handle each case grabs its tile by, so what matters is WHICH fact is meant, not
 * how it is worded — the wording is asserted where it is the point (the values below).
 */
const label = (key: string, locale: TestLocale = "de"): string => translate(`music.stats.label.${key}`, locale);

/** The tile drawn for one label, or undefined when the card drew none — which is a real answer here. */
const cellFor = (wrapper: ReturnType<typeof widget>, name: string) =>
    wrapper.findAll(".widget-stats__cell").find(cell => cell.find(".widget-stats__label").text() === name);

/** The value printed on that tile. */
const valueFor = (wrapper: ReturnType<typeof widget>, name: string): string | undefined =>
    cellFor(wrapper, name)?.find(".widget-stats__value").text();

/** The unbreakable pieces one tile's value is drawn in — see the `--part` rule in the component. */
const parts = (wrapper: ReturnType<typeof widget>, name: string): string[] =>
    cellFor(wrapper, name)?.findAll(".widget-stats__part").map(node => node.text()) ?? [];

/** Every label the card drew, in the order it drew them. */
const labels = (wrapper: ReturnType<typeof widget>): string[] =>
    wrapper.findAll(".widget-stats__label").map(node => node.text());

describe("StatsWidget", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("prints the album years as a range, with an en dash and no thousands separator", () => {
        expect(valueFor(widget(), label("years"))).toBe("1965–2024");
    });

    it("still separates the counts, which is what makes the year the exception", () => {
        // Same mount, same locale: the contrast is the test. 12074 songs print with a separator and
        // the years beside them do not, because one is a quantity and the other is not.
        const wrapper = widget();

        expect(valueFor(wrapper, label("songs"))).toBe("12.074");
        expect(valueFor(wrapper, label("years"))).toBe("1965–2024");
    });

    it("prints the range identically in English, where every count around it changes", () => {
        const wrapper = widget({}, "en");

        expect(valueFor(wrapper, label("years", "en"))).toBe("1965–2024");
        expect(valueFor(wrapper, label("songs", "en"))).toBe("12,074");
    });

    it("collapses a library that spans a single year to that one year", () => {
        // Not "1994–1994", which reads as a range somebody forgot to fill in.
        expect(valueFor(widget({ firstYear: 1994, lastYear: 1994 }), label("years"))).toBe("1994");
    });

    it("drops the years tile entirely when no album carries a year", () => {
        const wrapper = widget({ firstYear: null, lastYear: null });

        expect(cellFor(wrapper, label("years"))).toBeUndefined();
        expect(labels(wrapper)).toStrictEqual(
            ["songs", "size", "albums", "artists", "genres", "playtime"].map(key => label(key))
        );
    });

    it("draws the range between the counts and the playtime when there is one", () => {
        expect(labels(widget())).toStrictEqual(
            ["songs", "size", "albums", "artists", "genres", "years", "playtime"].map(key => label(key))
        );
    });

    it("marks the range with the same glyph the album and song pips give a year", () => {
        expect(iconNames(cellFor(widget(), label("years"))!)).toStrictEqual(["calendar"]);
    });

    it("spells the playtime out as a phrase, pluralised through the catalogue", () => {
        // This also pins the SEPARATOR, which is a text node outside the unbreakable spans: lose it
        // and the tile still looks right at a glance while reading and copying as "2 Tage,3 Stunden".
        expect(valueFor(widget(), label("playtime"))).toBe("2 Tage, 3 Stunden, 4 Minuten, 5 Sekunden");
    });

    it("keeps a number and the unit it belongs to in one unbreakable piece", () => {
        // "96,00" with "GB" alone on the line below reads as two facts. The range is a live case
        // rather than a theoretical one: a dash is a break opportunity, so "1965–" could end a line.
        const wrapper = widget();

        expect(parts(wrapper, label("size"))).toStrictEqual(["96,00 GB"]);
        expect(parts(wrapper, label("years"))).toStrictEqual(["1965–2024"]);
        expect(parts(wrapper, label("songs"))).toStrictEqual(["12.074"]);
    });

    it("lets the playtime break between its units, with each comma riding the unit before it", () => {
        // The comma belongs to the piece it follows: a break must land in the space AFTER it, never
        // leave a line opening with ", 3 Stunden".
        expect(parts(widget(), label("playtime")))
            .toStrictEqual(["2 Tage,", "3 Stunden,", "4 Minuten,", "5 Sekunden"]);
    });

    it("gives a one-unit playtime no trailing comma to break after", () => {
        expect(parts(widget({ playtimeSeconds: 0 }), label("playtime"))).toStrictEqual(["0 Sekunden"]);
    });

    it("uses each unit's singular where there is exactly one of it", () => {
        // The pluralisation is the wiring under test: formatDuration knows nothing about language
        // and asks back through `t(key, count)`, so a broken bridge shows up as "1 Tage".
        const oneOfEach = 86400 + 3600 + 60 + 1;

        expect(valueFor(widget({ playtimeSeconds: oneOfEach }), label("playtime")))
            .toBe("1 Tag, 1 Stunde, 1 Minute, 1 Sekunde");
    });

    it("leaves the leading units off a playtime that does not reach them", () => {
        // Nothing reads "0 Monate, 0 Tage, …" — the phrase starts at the largest unit with a value.
        expect(valueFor(widget({ playtimeSeconds: 125 }), label("playtime"))).toBe("2 Minuten, 5 Sekunden");
    });

    it("gives the playtime phrase a line of its own, and gives it to nothing else", () => {
        // The `wide` flag is the tile list's one styling decision, so it is asserted through the
        // class it produces rather than left to a screenshot.
        const wide = widget().findAll(".widget-stats__cell--wide");

        expect(wide).toHaveLength(1);
        expect(wide[0].find(".widget-stats__label").text()).toBe(label("playtime"));
    });

    it("humanises the byte count instead of printing it", () => {
        expect(valueFor(widget(), label("size"))).toBe("96,00 GB");
    });

    it("explains every tile it draws, the new one included", () => {
        // Read off the Tooltip components rather than the DOM, because the directive only writes
        // `aria-describedby` while a tip is actually showing — a tile whose hint never arrived
        // looks identical in the markup, and the tooltips are the only place this card says how a
        // number is derived.
        const hints = widget()
            .findAllComponents(Tooltip)
            .map(tip => tip.props("text"));

        expect(hints).toHaveLength(7);
        expect(hints).toContain(translate("music.stats.hint.years"));
        expect(hints.every(hint => hint !== "")).toBe(true);
    });

    /*
     * The field is optional since 2026-08-14, because the welcome page draws this card to a
     * visitor with no session and `/search` is inside the auth group — a box there would answer
     * 401 to everything typed into it.
     *
     * What is worth pinning is that dropping the field drops NOTHING ELSE. The card is one flex
     * column holding the field and the tiles, so a `v-if` in the wrong place, or a stray tile
     * that assumed a search above it, would show up as a landing page missing a number rather
     * than as an error.
     */
    it("carries the search field by default, which is the card the Music page asks for", () => {
        expect(widget().findComponent(SearchHub).exists()).toBe(true);
    });

    it("drops the field when told it has no search, and keeps every tile", () => {
        const wrapper = widget({}, "de", false);

        expect(wrapper.findComponent(SearchHub).exists()).toBe(false);
        expect(labels(wrapper)).toStrictEqual(
            ["songs", "size", "albums", "artists", "genres", "years", "playtime"].map(key => label(key))
        );
    });
});
