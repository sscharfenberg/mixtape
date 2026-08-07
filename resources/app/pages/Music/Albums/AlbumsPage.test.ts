import { beforeEach, describe, expect, it, vi } from "vitest";
import { getLayoutProps, resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import AlbumsPage from "./AlbumsPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The albums listing declares columns and formats four cells; AlbumsController owns sort,
 * search and paging through the URL and is pinned by its own feature test. So this stays on
 * what PHP cannot see.
 *
 * The one that earns its place is `modifiedAt`. It arrives as ISO-8601 and is rendered in
 * the VIEWER's locale and timezone — a decision the server cannot make and cannot check,
 * because the same row must read "09.03.2024, 18:04:00" for one reader and "Mar 9, 2024,
 * 6:04:00 PM" for another. Ship the raw string by mistake and the column still renders,
 * just as an unreadable timestamp.
 *
 * The other three are the same argument in miniature: raw seconds are clocked, a missing
 * value has to DISAPPEAR rather than become "0:00" (the library really does hold untagged
 * rips), and the artwork cell resolves through CoverImage, whose placeholder is the normal
 * case here rather than an error — album art rests on a scan-time flag, so an advertised
 * cover can 404.
 *
 * The name link matters more than it looks: the whole row is clickable via a handler, which
 * serves a mouse and nothing else. The <Link> in the name cell IS the keyboard and
 * open-in-new-tab path, so losing it is an accessibility regression no screenshot catches.
 */

/** A table payload, defaulted around one fully-tagged album. */
const table = (overrides: Record<string, unknown> = {}) => ({
    rows: [
        {
            id: "album-1",
            name: "OK Computer",
            artist: "Radiohead",
            year: 1997,
            songs: 12,
            discs: 1,
            duration: 3193,
            modifiedAt: "2024-03-09T18:04:00Z",
            coverUrl: "/covers/album-1.jpg",
            href: "/music/albums/album-1"
        }
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
    mountApp(AlbumsPage, { props: { table: table(overrides) }, locale });

/** Replace the single row's fields. */
const withRow = (fields: Record<string, unknown>) => ({ rows: [{ ...table().rows[0], ...fields }] });

describe("AlbumsPage", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("heads the page with the albums label", () => {
        expect(page().text()).toContain(translate("music.widgets.albums"));
    });

    it("declares a breadcrumb trail whose last crumb is not a link", () => {
        page();

        expect(getLayoutProps().breadcrumbs).toStrictEqual([
            { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
            { labelKey: "music.widgets.albums", icon: "album" }
        ]);
    });

    it("leads with artwork and then the eight columns in reading order", () => {
        const headers = page()
            .findAll("th")
            .map(node => node.text());

        expect(headers).toStrictEqual([
            translate("music.columns.cover"),
            translate("music.columns.year"),
            translate("music.columns.album"),
            translate("music.columns.artist"),
            translate("music.columns.songs"),
            translate("music.columns.discs"),
            translate("music.columns.modifiedAt"),
            translate("music.columns.duration")
        ]);
    });

    it("renders the file date in the reader's own locale, not as the ISO the server sent", () => {
        expect(page().text()).toContain("09.03.2024, 18:04:00");
        expect(page().text()).not.toContain("2024-03-09T18:04:00Z");

        expect(page({}, "en").text()).toContain("Mar 9, 2024, 6:04:00 PM");
    });

    it("clocks the album's total playing time", () => {
        expect(page().text()).toContain("53:13");
        expect(page().text()).not.toContain("3193");
    });

    it("leaves an untagged album's playing time and date blank rather than filling them in", () => {
        // A rip with no durations must not read "0:00", and one with no files no date.
        const wrapper = page(withRow({ duration: null, modifiedAt: null }));

        expect(wrapper.text()).not.toContain("0:00");
        expect(wrapper.text()).not.toContain("null");
    });

    it("shows the artwork as decoration, since the album's name is in the very next cell", () => {
        // Naming it again makes a screen reader read every row twice.
        const cover = page().findComponent({ name: "CoverImage" });

        expect(cover.props("src")).toBe("/covers/album-1.jpg");
        expect(cover.props("decorative")).toBe(true);
    });

    it("falls back to the placeholder for an album with no art on file", () => {
        const wrapper = page(withRow({ coverUrl: null }));

        expect(wrapper.findComponent({ name: "CoverImage" }).props("src")).toBeNull();
        expect(wrapper.find("img").exists()).toBe(false);
    });

    it("renders the album name as a real link, which is the keyboard path into it", () => {
        const link = page().find(".albums__title");

        expect(link.element.tagName).toBe("A");
        expect(link.attributes("href")).toBe("/music/albums/album-1");
        expect(link.text()).toBe("OK Computer");
    });

    it("shows an explicit empty message rather than a bare table", () => {
        expect(page({ rows: [], total: 0 }).text()).toContain(translate("components.datatable.no_results"));
    });
});
