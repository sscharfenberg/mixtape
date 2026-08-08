import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import AlbumPage from "./AlbumPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The album page's hero and its track listing.
 *
 * Two things here are the page's own decisions rather than the server's, and both are the
 * kind that fail quietly: a hero tile that goes SOMEWHERE (the genre) versus one that is
 * plain text, and the disc/track cells, which show a position over its total rather than a
 * bare number. Getting the latter wrong reads as plausible data — "3" instead of "3/12" —
 * so nothing about the page looks broken.
 */

/** The album, fully tagged; tests override only what they are about. */
const album = (overrides: Record<string, unknown> = {}) => ({
    id: "album-1",
    name: "OK Computer",
    artist: "Radiohead",
    artistUrl: "/music/artists/artist-1",
    year: 1997,
    genre: "Alternative Rock",
    genreUrl: "/music/genres/genre-1",
    songs: 12,
    discs: 1,
    duration: 3235,
    modifiedAt: "2026-07-28T14:23:05+00:00",
    coverUrl: null,
    ...overrides
});

/** One track row. */
const row = (overrides: Record<string, unknown> = {}) => ({
    id: "track-1",
    disc: 1,
    discTotal: 2,
    track: 3,
    trackTotal: 12,
    name: "Subterranean Homesick Alien",
    artist: "Radiohead",
    artistUrl: "/music/artists/artist-1",
    duration: 267,
    size: 10_485_760,
    coverUrl: null,
    href: "/music/songs/track-1",
    ...overrides
});

/** Mount the page. */
const page = (albumOverrides: Record<string, unknown> = {}, rows = [row()]) =>
    mountApp(AlbumPage, {
        props: {
            album: album(albumOverrides),
            plays: { own: 0, others: 0 },
            table: {
                rows,
                total: rows.length,
                totalUnfiltered: rows.length,
                page: 1,
                pageSize: 50,
                sort: null,
                search: null,
                filters: null
            }
        }
    });

/** The hero tile whose label matches, or undefined when it is not rendered. */
const heroTile = (wrapper: ReturnType<typeof page>, label: string) =>
    wrapper.findAll(".fact-pair").find(node => node.text().startsWith(label));

describe("AlbumPage", () => {
    beforeEach(() => {
        resetInertia();
    });

    describe("the genre tile", () => {
        it("shows the album's main genre", () => {
            const tile = heroTile(page(), translate("music.columns.genre"));

            expect(tile?.text()).toContain("Alternative Rock");
        });

        it("links it to that genre's page", () => {
            const tile = heroTile(page(), translate("music.columns.genre"));

            expect(tile?.find("a").attributes("href")).toBe("/music/genres/genre-1");
        });

        it("drops the tile for an album whose tracks carry no genre", () => {
            // Null in, null out — an empty tile would claim the album has a genre we failed
            // to name, which is a different thing from having none.
            const wrapper = page({ genre: null, genreUrl: null });

            expect(heroTile(wrapper, translate("music.columns.genre"))).toBeUndefined();
        });

        it("prints the genre plainly when the server gave no URL, with no dead link", () => {
            const wrapper = page({ genreUrl: null });
            const tile = heroTile(wrapper, translate("music.columns.genre"));

            expect(tile?.text()).toContain("Alternative Rock");
            expect(tile?.find("a").exists()).toBe(false);
        });
    });

    describe("the disc and track cells", () => {
        it("shows each position over its total", () => {
            const text = page().text();

            expect(text).toContain("1/2");
            expect(text).toContain("3/12");
        });

        it("drops the denominator when a rip numbers past its own disc", () => {
            // Multi-disc sets numbered straight through: "17/12" would read as an app bug
            // rather than as sloppy tags (formatPosition).
            const wrapper = page({}, [row({ track: 17, trackTotal: 12 })]);

            expect(wrapper.text()).toContain("17");
            expect(wrapper.text()).not.toContain("17/12");
        });

        it("leaves the cell blank for an untagged file rather than printing a zero", () => {
            const wrapper = page({}, [row({ disc: null, track: null })]);

            expect(wrapper.text()).not.toContain("0/");
            expect(wrapper.text()).not.toContain("null");
        });
    });

    it("still shows the album's name as the page heading", () => {
        expect(page().find("h2").text()).toBe("OK Computer");
    });
});
