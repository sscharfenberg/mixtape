import { beforeEach, describe, expect, it, vi } from "vitest";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import SongsPage from "./SongsPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * SongsPage is the thin half of the listing: the controller owns sort / search /
 * pagination through the URL, and this page only declares columns and hands the payload
 * to DataTable. So the tests stay on what the PAGE decides and PHP cannot see —
 * the column set, the two cell slots that override the default rendering (a raw
 * duration clocked, a title turned into a real link), and the breadcrumb trail.
 *
 * The title link matters more than it looks: the row itself is clickable via a handler,
 * which gives a mouse user everything and a keyboard user nothing. The <Link> in the
 * title cell IS the keyboard and open-in-new-tab path, so its disappearance would be an
 * accessibility regression that no visual check would catch.
 */

/** A table payload with `rows`, defaulted around one song. */
const table = (overrides: Record<string, unknown> = {}) => ({
    rows: [
        {
            id: "song-1",
            name: "Paranoid Android",
            artist: "Radiohead",
            album: "OK Computer",
            genre: "Alternative Rock",
            duration: 383,
            href: "/music/songs/song-1"
        }
    ],
    total: 1,
    page: 1,
    pageSize: 25,
    sort: { key: "name", direction: "asc" as const },
    search: null,
    filters: null,
    ...overrides
});

/** Mount the listing over a table payload. */
const page = (overrides: Record<string, unknown> = {}) => mountApp(SongsPage, { props: { table: table(overrides) } });

describe("SongsPage", () => {
    beforeEach(() => {
        resetInertia();
        useBreadcrumbs().setBreadcrumbs([]);
    });

    it("heads the page with the songs label", () => {
        expect(page().text()).toContain(translate("music.widgets.songs"));
    });

    it("declares a breadcrumb trail whose last crumb is not a link", () => {
        page();

        expect(useBreadcrumbs().crumbs.value).toStrictEqual([
            { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
            { labelKey: "music.widgets.songs", icon: "song" }
        ]);
    });

    it("declares the five columns in reading order", () => {
        const headers = page()
            .findAll("th")
            .map(node => node.text());

        expect(headers).toStrictEqual([
            translate("music.columns.title"),
            translate("music.columns.artist"),
            translate("music.columns.album"),
            translate("music.columns.genre"),
            translate("music.columns.duration")
        ]);
    });

    it("renders a row's values", () => {
        const text = page().text();

        expect(text).toContain("Paranoid Android");
        expect(text).toContain("Radiohead");
        expect(text).toContain("OK Computer");
    });

    it("clocks the raw duration the server sent", () => {
        // 383 seconds arrives as a number; the cell slot is what makes it readable.
        expect(page().text()).toContain("6:23");
        expect(page().text()).not.toContain("383");
    });

    it("leaves an untagged duration blank rather than showing 0:00", () => {
        const wrapper = page({ rows: [{ ...table().rows[0], duration: null }] });

        expect(wrapper.text()).not.toContain("0:00");
    });

    it("renders the title as a real link, which is the keyboard path to the song", () => {
        // The clickable row serves the mouse; this serves everything else.
        const link = page().find(".songs__title");

        expect(link.element.tagName).toBe("A");
        expect(link.attributes("href")).toBe("/music/songs/song-1");
        expect(link.text()).toBe("Paranoid Android");
    });

    it("shows an explicit empty message rather than a bare table", () => {
        const wrapper = page({ rows: [], total: 0 });

        expect(wrapper.text()).toContain(translate("components.datatable.no_results"));
    });

    it("renders in English when the locale says so", () => {
        const wrapper = mountApp(SongsPage, { props: { table: table() }, locale: "en" });

        expect(wrapper.findAll("th")[0].text()).toBe(translate("music.columns.title", "en"));
    });
});
