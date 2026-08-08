import { beforeEach, describe, expect, it, vi } from "vitest";
import { getLayoutProps, resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import ArtistsPage from "./ArtistsPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The artists listing. Same shape as the other three, so the tests concentrate on the two
 * things that are only true here.
 *
 * ZERO IS AN ANSWER. Every other listing treats a missing number as missing; this one does
 * not, and the difference is a real reading of the data. ArtistsController COALESCEs both
 * sums, so an artist who only ever guested on other people's compilations reads "3 Alben,
 * 0:00" — credited with records whose songs are filed under the individual performers.
 * Blanking that out on the grounds that "0 looks like missing data" would delete the
 * informative case, so both formatters must be allowed to render zero.
 *
 * SIZE IS LOCALE-FORMATTED. Bytes arrive raw and become "10,00 MB" for a German reader and
 * "10.00 MB" for an English one — a decision the server cannot make, since the same row is
 * read both ways.
 *
 * There is no artwork column at all, and that is deliberate rather than missing: MixTape
 * stores no artist images, so there is nothing to point an <img> at.
 */

/** A table payload, defaulted around one artist with a catalogue of their own. */
const table = (overrides: Record<string, unknown> = {}) => ({
    rows: [
        { id: "artist-1", name: "Radiohead", albums: 9, songs: 118, duration: 51727, size: 10485760, plays: 34, href: "/music/artists/artist-1" }
    ],
    total: 1,
    totalUnfiltered: 1,
    page: 1,
    pageSize: 25,
    sort: { key: "name", direction: "asc" as const },
    search: null,
    filters: null,
    ...overrides
});

/** Mount the listing over a table payload. */
const page = (overrides: Record<string, unknown> = {}, locale: "de" | "en" = "de") =>
    mountApp(ArtistsPage, { props: { table: table(overrides) }, locale });

describe("ArtistsPage", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("heads the page with the artists label", () => {
        expect(page().text()).toContain(translate("music.widgets.artists"));
    });

    it("declares a breadcrumb trail whose last crumb is not a link", () => {
        page();

        expect(getLayoutProps().breadcrumbs).toStrictEqual([
            { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
            { labelKey: "music.widgets.artists", icon: "artist" }
        ]);
    });

    it("declares the six columns, with no artwork among them", () => {
        const headers = page()
            .findAll("th")
            .map(node => node.text());

        expect(headers).toStrictEqual([
            translate("music.columns.artist"),
            translate("music.columns.albums"),
            translate("music.columns.songs"),
            translate("music.columns.duration"),
            translate("music.columns.size"),
            translate("music.plays.columnLabel")
        ]);
        expect(headers).not.toContain(translate("music.columns.cover"));
    });

    it("clocks the catalogue's playing time across hours", () => {
        expect(page().text()).toContain("14:22:07");
        expect(page().text()).not.toContain("51727");
    });

    it("sizes the files in the reader's own locale", () => {
        expect(page().text()).toContain("10,00 MB");
        expect(page({}, "en").text()).toContain("10.00 MB");
    });

    it('shows a credited-only artist as "0:00", because here zero is the informative answer', () => {
        // Nine albums and no files of their own: a compilation owner whose tracks are filed
        // under the individual performers. Blanking this would delete the reading.
        const wrapper = page({ rows: [{ ...table().rows[0], duration: 0, size: 0 }] });

        expect(wrapper.text()).toContain("0:00");
        expect(wrapper.text()).toContain("9");
    });

    it("renders the name as a real link, which is the keyboard path into the artist", () => {
        const link = page().find(".artists__name");

        expect(link.element.tagName).toBe("A");
        expect(link.attributes("href")).toBe("/music/artists/artist-1");
        expect(link.text()).toBe("Radiohead");
    });

    it("shows an explicit empty message rather than a bare table", () => {
        expect(page({ rows: [], total: 0 }).text()).toContain(translate("components.datatable.no_results"));
    });
});
