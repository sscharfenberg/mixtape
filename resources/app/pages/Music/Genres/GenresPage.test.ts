import { beforeEach, describe, expect, it, vi } from "vitest";
import { getLayoutProps, resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import GenresPage from "./GenresPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The genres listing — structurally the artists one with a different first column, so the
 * tests concentrate on what only this page decides.
 *
 * The ARTISTS column is the trap, and it is a reading trap rather than a rendering one. It
 * counts the artists whose MAIN genre this is (GenresController / DominantGenre), not
 * everyone who ever recorded a song in it — which is why the column adds up to the library's
 * artist count, and why a genre with hundreds of songs can legitimately show 0: it is
 * nobody's main genre. A zero here must therefore render as a zero. Suppressing it as
 * "missing" is the plausible change that turns a fact into a blank.
 *
 * Sizes and playing times are raw from the server and formatted against the viewer's locale,
 * as everywhere else — the half PHP cannot check because the same row is read both ways.
 */

/** A table payload, defaulted around one well-populated genre. */
const table = (overrides: Record<string, unknown> = {}) => ({
    rows: [
        { id: "genre-1", name: "Alternative Rock", artists: 42, songs: 118, duration: 51727, size: 10485760, plays: 12, href: "/music/genres/genre-1" }
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

/**
 * The strip's payload, defaulted to a listing with nothing to report.
 *
 * Zeroed on purpose: the strip has its own tests, so what a page test wants from it is the quietest
 * version — present, so the page mounts as the controller renders it, and silent, so nothing it
 * draws can be mistaken for something the page decided.
 */
const stats = () => ({ total: 1, filters: [] });

/** Mount the listing over a table payload. */
const page = (overrides: Record<string, unknown> = {}, locale: "de" | "en" = "de") =>
    mountApp(GenresPage, { props: { table: table(overrides), stats: stats() }, locale });

describe("GenresPage", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("heads the page with the genres label", () => {
        expect(page().text()).toContain(translate("music.widgets.genres"));
    });

    it("declares a breadcrumb trail whose last crumb is not a link", () => {
        page();

        expect(getLayoutProps().breadcrumbs).toStrictEqual([
            { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
            { labelKey: "music.widgets.genres", icon: "genre" }
        ]);
    });

    it("declares the six columns in reading order", () => {
        expect(
            page()
                .findAll("th:not(.dt-head__check)")
                .map(node => node.text())
        ).toStrictEqual([
            translate("music.columns.genre"),
            translate("music.columns.artists"),
            translate("music.columns.songs"),
            translate("music.columns.duration"),
            translate("music.columns.size"),
            translate("music.plays.columnLabel")
        ]);
    });

    it("clocks the playing time and sizes the files in the reader's own locale", () => {
        expect(page().text()).toContain("14:22:07");
        expect(page().text()).toContain("10,00 MB");
        expect(page({}, "en").text()).toContain("10.00 MB");
    });

    it("shows a genre that is nobody's main genre as 0 artists, not as a blank", () => {
        // 118 songs and 0 artists is a real state, not missing data — the column counts
        // artists whose DOMINANT genre this is.
        const cells = page({ rows: [{ ...table().rows[0], artists: 0 }] })
            .findAll("td")
            .map(cell => cell.text());

        expect(cells).toContain("0");
    });

    it("renders the name as a real link, which is the keyboard path into the genre", () => {
        const link = page().find(".genres__name");

        expect(link.element.tagName).toBe("A");
        expect(link.attributes("href")).toBe("/music/genres/genre-1");
        expect(link.text()).toBe("Alternative Rock");
    });

    it("shows an explicit empty message rather than a bare table", () => {
        expect(page({ rows: [], total: 0 }).text()).toContain(translate("components.datatable.no_results"));
    });
});
