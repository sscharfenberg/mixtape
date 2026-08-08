import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { getLayoutProps, resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import GenrePage from "./GenrePage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * GenrePage is the tabbed detail page, and the tabs are the interesting part: EVERY panel
 * is sent on every request so switching one costs no round trip, and the open tab is
 * mirrored into `?tab=` so a shared link reopens it.
 *
 * The tab COUNTS are the subtle bit. They come from three different places on purpose —
 * albums from the genre's own aggregate, artists from the panel array's length, songs
 * from the hero's number because that panel is paginated and its rows are one page of the
 * whole. Wiring any of them to the wrong source produces a plausible-looking number that
 * silently disagrees with the panel it labels, which no visual check would catch.
 *
 * The ARTISTS tab renders GenreArtists; its own spec covers the card. What is asserted here
 * is only that this page wires the panel up and passes it the right rows.
 */

/** A genre with its aggregates, plus the three panel payloads. */
const props = (overrides: Record<string, unknown> = {}) => ({
    genre: {
        id: "genre-1",
        name: "Alternative Rock",
        artists: 3,
        albums: 2,
        songs: 42,
        duration: 9296,
        size: 314_572_800
    },
    discography: [
        {
            id: "album-1",
            name: "OK Computer",
            year: 1997,
            artist: "Radiohead",
            songs: 12,
            duration: 3235,
            coverUrl: null,
            href: "/music/albums/album-1"
        },
        {
            id: "album-2",
            name: "The Bends",
            year: 1995,
            artist: "Radiohead",
            songs: 12,
            duration: 3061,
            coverUrl: null,
            href: "/music/albums/album-2"
        }
    ],
    artists: [
        {
            id: "artist-1",
            name: "Radiohead",
            songs: 24,
            albums: 2,
            duration: 6296,
            covers: ["/music/albums/album-1/cover", "/music/albums/album-2/cover"],
            href: "/music/artists/artist-1"
        },
        {
            id: "artist-2",
            name: "Blur",
            songs: 10,
            albums: 1,
            duration: 2400,
            covers: ["/music/albums/album-3/cover"],
            href: "/music/artists/artist-2"
        }
    ],
    plays: { own: 0, others: 0 },
    table: {
        rows: [
            {
                id: "song-1",
                name: "Paranoid Android",
                disc: 1,
                discTotal: 1,
                track: 2,
                trackTotal: 12,
                artist: "Radiohead",
                artistUrl: "/music/artists/artist-1",
                album: "OK Computer",
                albumUrl: "/music/albums/album-1",
                year: 1997,
                duration: 383,
                size: 15_728_640,
                coverUrl: null,
                href: "/music/songs/song-1"
            }
        ],
        total: 42,
        totalUnfiltered: 42,
        page: 1,
        pageSize: 25,
        sort: null,
        search: null,
        filters: null
    },
    ...overrides
});

/** Mount the genre page. */
const page = (overrides: Record<string, unknown> = {}) => mountApp(GenrePage, { props: props(overrides) });

/** The rendered tab strip, as `label count` strings. */
const tabLabels = (wrapper: ReturnType<typeof page>): string[] =>
    wrapper.findAll("[role='tab']").map(node => node.text().replace(/\s+/gu, " ").trim());

describe("GenrePage", () => {
    beforeEach(() => {
        resetInertia();
        history.replaceState(null, "", "/music/genres/genre-1");
        Element.prototype.scrollIntoView = vi.fn();
        vi.stubGlobal(
            "matchMedia",
            vi.fn(() => ({ matches: false }))
        );
    });

    it("shows the genre's name as the page heading", () => {
        expect(page().find("h2").text()).toBe("Alternative Rock");
    });

    it("declares a trail whose parent is the listing this row came from", () => {
        page();

        expect(getLayoutProps().breadcrumbs).toStrictEqual([
            { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
            { labelKey: "music.widgets.genres", href: "/music/genres", icon: "genre" },
            { label: "Alternative Rock" }
        ]);
    });

    it("formats the hero's raw aggregates for the reader", () => {
        const text = page().text();

        // 9296 seconds and 314572800 bytes arrive raw.
        expect(text).toContain("2:34:56");
        expect(text).toContain("300,00 MB");
    });

    describe("the tab strip", () => {
        it("leads with albums, the most structural view of the same material", () => {
            expect(tabLabels(page())[0]).toContain(translate("music.columns.albums"));
        });

        it("offers albums, artists and songs", () => {
            const labels = tabLabels(page());

            expect(labels[0]).toContain(translate("music.columns.albums"));
            expect(labels[1]).toContain(translate("music.columns.artists"));
            expect(labels[2]).toContain(translate("music.columns.songs"));
        });

        it("counts albums from the genre's own aggregate, so the pip matches the panel", () => {
            expect(tabLabels(page())[0]).toContain("2");
        });

        it("counts artists from the panel itself", () => {
            expect(tabLabels(page())[1]).toContain("2");
        });

        it("counts songs from the hero's total, not the page of rows on screen", () => {
            // The songs panel is paginated: its 1 visible row is not the count.
            expect(tabLabels(page())[2]).toContain("42");
        });
    });

    describe("the panels", () => {
        it("shows the albums panel by default when the URL names no tab", () => {
            const wrapper = page();

            expect(wrapper.text()).toContain("OK Computer");
        });

        it("names each album's artist, since a genre's records are by different people", () => {
            expect(page().text()).toContain("Radiohead");
        });

        it("opens the tab the URL names", async () => {
            history.replaceState(null, "", "/music/genres/genre-1?tab=artists");
            const wrapper = page();
            await nextTick();

            expect(wrapper.findAll(".genre-artists__name").map(node => node.text())).toStrictEqual([
                "Radiohead",
                "Blur"
            ]);
        });

        it("makes each artist card a real link, so it is keyboard-reachable", async () => {
            // The whole card is the target; a clickable div would take it away from the
            // keyboard and from open-in-new-tab.
            history.replaceState(null, "", "/music/genres/genre-1?tab=artists");
            const wrapper = page();
            await nextTick();

            expect(wrapper.find(".genre-artists__link").attributes("href")).toBe("/music/artists/artist-1");
        });

        it("says so when no artist calls this their main genre", async () => {
            history.replaceState(null, "", "/music/genres/genre-1?tab=artists");
            const wrapper = page({ artists: [] });
            await nextTick();

            expect(wrapper.text()).toContain(translate("music.genre.noArtists"));
        });

        it("points the songs table back at this page, so its state lands in the URL", async () => {
            history.replaceState(null, "", "/music/genres/genre-1?tab=songs");
            const wrapper = page();
            await nextTick();

            expect(wrapper.text()).toContain("Paranoid Android");
        });
    });

    it("changes tabs without a request, since every panel was already sent", async () => {
        const wrapper = page();

        const artistsTab = wrapper.findAll("[role='tab']")[1];
        await artistsTab.trigger("click");
        await nextTick();

        const { routerCalls } = await import("Testing/inertia");
        expect(routerCalls).toHaveLength(0);
        // ...and the choice is mirrored into the URL for a reload or a shared link.
        expect(window.location.search).toBe("?tab=artists");
    });
});
