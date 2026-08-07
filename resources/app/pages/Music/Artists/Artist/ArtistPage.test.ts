import { beforeEach, describe, expect, it, vi } from "vitest";
import { getLayoutProps, resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import ArtistPage from "./ArtistPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The artist page's hero and its two tabs. What is this page's own — not the server's, and
 * not TabbedNavigation's — comes down to four decisions.
 *
 * THE COUNTS ALWAYS RENDER, ZERO INCLUDED, while the genre tile disappears when there is
 * none. That asymmetry is the page's reading of the data and it is deliberate: the server
 * COALESCEs the sums, so "0 Alben / 0:00" says *this artist has no files of their own*,
 * which beside a non-zero album count is the informative case. A genre, by contrast, is
 * either known or not, and "Genre: —" is noise. Treating all five tiles the same is the
 * plausible tidy-up that loses one of the two meanings.
 *
 * THE GENRE TILE LINKS ONLY WHEN THE SERVER GAVE IT SOMEWHERE TO GO. `genreUrl` can be null
 * while `genre` is set; passing it through regardless yields `href="null"` — a tile that
 * still looks clickable and navigates to a 404.
 *
 * THE BREADCRUMB'S LAST CRUMB IS A RAW LABEL, not a translation key, because the artist's
 * name is data. Sent as `labelKey` it would be looked up, miss, and render the name as a
 * missing-key warning.
 *
 * THERE IS NO #cover SLOT AT ALL — not an empty one. HeroSection draws its dashed
 * placeholder when the slot EXISTS and holds no image ("no artwork on file"); omitting the
 * slot says "this kind of page has no artwork", which is the true statement, since MixTape
 * stores no artist images.
 */

/** The artist, fully described; tests override only what they are about. */
const artist = (overrides: Record<string, unknown> = {}) => ({
    id: "artist-1",
    name: "Radiohead",
    albums: 9,
    songs: 118,
    duration: 51_727,
    size: 10_485_760,
    genre: "Alternative Rock",
    genreUrl: "/music/genres/genre-1",
    ...overrides
});

/** One song row for the songs tab. */
const row = () => ({
    id: "song-1",
    name: "Paranoid Android",
    disc: 1,
    discTotal: 1,
    track: 2,
    trackTotal: 12,
    album: "OK Computer",
    year: 1997,
    albumUrl: "/music/albums/album-1",
    duration: 383,
    size: 10_485_760,
    coverUrl: null,
    href: "/music/songs/song-1"
});

/** Mount the page. */
const page = (overrides: Record<string, unknown> = {}, locale: "de" | "en" = "de") =>
    mountApp(ArtistPage, {
        props: {
            artist: artist(overrides),
            discography: [],
            table: {
                rows: [row()],
                total: 1,
                totalUnfiltered: 1,
                page: 1,
                pageSize: 25,
                sort: { key: "year", direction: "desc" as const },
                search: null,
                filters: null
            }
        },
        locale
    });

/** The hero's fact tiles as label → value. */
const tiles = (wrapper: ReturnType<typeof page>): Record<string, string> =>
    Object.fromEntries(
        wrapper.findAll(".fact-pair").map(tile => [tile.find(".fact-pair__label").text(), tile.find(".fact-pair__value").text()])
    );

describe("ArtistPage", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("titles the hero with the artist's name", () => {
        expect(page().find("h2").text()).toBe("Radiohead");
    });

    it("puts the artist's own name in the trail as a label, since it is data and not a key", () => {
        page();

        expect(getLayoutProps().breadcrumbs).toStrictEqual([
            { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
            { labelKey: "music.widgets.artists", href: "/music/artists", icon: "artist" },
            { label: "Radiohead" }
        ]);
    });

    it("describes the catalogue with the genre first, then how much of it there is", () => {
        expect(tiles(page())).toStrictEqual({
            [translate("music.columns.genre")]: "Alternative Rock",
            [translate("music.columns.albums")]: "9",
            [translate("music.columns.songs")]: "118",
            [translate("music.columns.duration")]: "14:22:07",
            [translate("music.columns.size")]: "10,00 MB"
        });
    });

    it("sizes the catalogue in the reader's own locale", () => {
        expect(tiles(page({}, "en"))[translate("music.columns.size", "en")]).toBe("10.00 MB");
    });

    it("keeps every count even at zero, because zero is an answer about this artist", () => {
        // Credited with albums, owning no files: the songs are filed under the performers.
        const shown = tiles(page({ songs: 0, duration: 0, size: 0 }));

        expect(shown[translate("music.columns.songs")]).toBe("0");
        expect(shown[translate("music.columns.duration")]).toBe("0:00");
    });

    it("drops the genre tile entirely for an artist with none, rather than saying 'unknown'", () => {
        expect(tiles(page({ genre: null, genreUrl: null }))).not.toHaveProperty(translate("music.columns.genre"));
    });

    it("links the genre tile only where the server gave it somewhere to go", () => {
        expect(page().find(".fact-pair--link a").attributes("href")).toBe("/music/genres/genre-1");
        // Same genre, no page for it: plain text, not an href of "null".
        expect(page({ genreUrl: null }).find(".fact-pair--link").exists()).toBe(false);
    });

    it("offers no artwork slot at all, since MixTape stores no artist images", () => {
        // Not an EMPTY cover slot — that is how HeroSection is told to draw its "no artwork
        // on file" placeholder, which would be the wrong statement here.
        expect(page().find(".hero-section__cover").exists()).toBe(false);
    });

    it("puts the counts on the tabs, so a reader sees how much is behind each", () => {
        const tabs = page().findAll('[role="tab"]');

        expect(tabs).toHaveLength(2);
        expect(tabs[0].text()).toContain(translate("music.columns.albums"));
        expect(tabs[0].text()).toContain("9");
        expect(tabs[1].text()).toContain(translate("music.columns.songs"));
        expect(tabs[1].text()).toContain("118");
    });

    it("opens on albums, the smaller structural view of the same catalogue", () => {
        expect(page().find('[aria-selected="true"]').text()).toContain(translate("music.columns.albums"));
    });

    it("points the songs table back at the artist's own URL, so its state lands here", () => {
        expect(page().findComponent({ name: "ArtistSongs" }).props("baseUrl")).toBe("/music/artists/artist-1");
    });
});
