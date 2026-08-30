import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import ArtistSongs, { type SongRow } from "./ArtistSongs.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The artist page's songs tab. Four of its decisions are this component's own and none of
 * them shows up as a broken page when it goes wrong.
 *
 * THE ARTIST COLUMN IS CONDITIONAL, on the server's `showArtist`. Off, every row is by the
 * artist whose page this is and the column could only repeat one name down the whole table;
 * on, the rows are collaborations or a compilation's individual performers and without it the
 * table says who nothing is by. Both readings look like a working page, which is why both
 * branches are pinned here — and why the column's POSITION is asserted too: appending it (the
 * obvious way to add a column) puts the performer after the file size.
 *
 * DISC AND TRACK READ AS A POSITION, "3/12" rather than "3". A bare number is plausible
 * data, which is exactly why the difference has to be pinned: nobody reviewing a screenshot
 * would call "3" wrong. `formatPosition` also drops a denominator that would LIE — a total
 * smaller than the index, which happens in the collection's odd rips — so both branches
 * matter.
 *
 * THE ALBUM AND ARTIST CELLS LEAD SOMEWHERE ELSE THAN THEIR ROW. The row opens the song;
 * those two open the album and the performer. They are the only cells that do, which is why
 * they link at all — and only when there is something to link to, since `albumUrl` /
 * `artistUrl` are null otherwise and would render an href of "null".
 *
 * Sorting, searching and paging are the server's and are covered by ArtistController's
 * feature test plus datatable.spec.ts in a browser.
 */

/** One row, fully tagged; tests override only what they are about. */
const row = (overrides: Partial<SongRow> = {}): SongRow => ({
    id: "song-1",
    name: "Paranoid Android",
    disc: 1,
    discTotal: 2,
    track: 3,
    trackTotal: 12,
    artist: "Radiohead",
    artistUrl: "/music/artists/artist-1",
    album: "OK Computer",
    year: 1997,
    albumUrl: "/music/albums/album-1",
    duration: 383,
    size: 10_485_760,
    coverUrl: null,
    href: "/music/songs/song-1",
    ...overrides
});

/** Mount the tab over one row. `showArtist` is off unless a test is about it. */
const tab = (overrides: Partial<SongRow> = {}, locale: "de" | "en" = "de", showArtist = false) =>
    mountApp(ArtistSongs, {
        props: {
            baseUrl: "/music/artists/artist-1",
            showArtist,
            table: {
                rows: [row(overrides)],
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

/** The cell under a given column header. */
const cell = (wrapper: ReturnType<typeof tab>, header: string): string => {
    const index = wrapper.findAll("th:not(.dt-head__check)").findIndex(th => th.text() === header);

    return wrapper.findAll("tbody td:not(.dt-body__check)")[index].text();
};

describe("ArtistSongs", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("leaves out the artist column when nothing on the page is by anybody else", () => {
        const headers = tab()
            .findAll("th:not(.dt-head__check)")
            .map(node => node.text());

        expect(headers).not.toContain(translate("music.columns.artist"));
        expect(headers).toStrictEqual([
            translate("music.columns.cover"),
            translate("music.columns.title"),
            translate("music.columns.album"),
            translate("music.columns.year"),
            translate("music.song.labels.disc"),
            translate("music.song.labels.track"),
            translate("music.columns.duration"),
            translate("music.song.labels.size")
        ]);
    });

    it("draws the artist column right after the title when the server asks for it", () => {
        const headers = tab({}, "de", true)
            .findAll("th:not(.dt-head__check)")
            .map(node => node.text());

        // Between the title and the album: who it is by comes before where it sits, and
        // appending it instead would put the performer after the file size.
        expect(headers).toStrictEqual([
            translate("music.columns.cover"),
            translate("music.columns.title"),
            translate("music.columns.artist"),
            translate("music.columns.album"),
            translate("music.columns.year"),
            translate("music.song.labels.disc"),
            translate("music.song.labels.track"),
            translate("music.columns.duration"),
            translate("music.song.labels.size")
        ]);
    });

    it("links the artist cell to the performer, and leaves it plain when nobody is credited", () => {
        // A third destination: the row opens the song, the album cell opens the album.
        const link = tab({ artist: "Bring Me The Horizon feat. BABYMETAL", artistUrl: "/music/artists/artist-2" }, "de", true)
            .find(".artist-songs__artist");

        expect(link.element.tagName).toBe("A");
        expect(link.attributes("href")).toBe("/music/artists/artist-2");

        const untagged = tab({ artist: null, artistUrl: null }, "de", true);

        expect(untagged.find(".artist-songs__artist").exists()).toBe(false);
        expect(cell(untagged, translate("music.columns.artist"))).toBe("");
    });

    it("reads disc and track as a position in their set, not as bare numbers", () => {
        const wrapper = tab();

        expect(cell(wrapper, translate("music.song.labels.disc"))).toBe("1/2");
        expect(cell(wrapper, translate("music.song.labels.track"))).toBe("3/12");
    });

    it("drops a denominator that would lie, and blanks the cell for an untagged file", () => {
        // A total smaller than the index is a rip whose tags disagree with themselves.
        expect(cell(tab({ track: 14, trackTotal: 12 }), translate("music.song.labels.track"))).toBe("14");
        expect(cell(tab({ disc: null, discTotal: null }), translate("music.song.labels.disc"))).toBe("");
    });

    it("links the album cell away from where its own row leads", () => {
        // The row opens the song; this one cell opens the album.
        const link = tab().find(".artist-songs__album");

        expect(link.element.tagName).toBe("A");
        expect(link.attributes("href")).toBe("/music/albums/album-1");
        expect(tab().find(".artist-songs__title").attributes("href")).toBe("/music/songs/song-1");
    });

    it("leaves the album as plain text for a track belonging to no collection", () => {
        const wrapper = tab({ album: "Diverses", albumUrl: null });

        expect(wrapper.find(".artist-songs__album").exists()).toBe(false);
        expect(cell(wrapper, translate("music.columns.album"))).toBe("Diverses");
    });

    it("clocks the duration and sizes the file in the reader's own locale", () => {
        expect(cell(tab(), translate("music.columns.duration"))).toBe("6:23");
        expect(cell(tab(), translate("music.song.labels.size"))).toBe("10,00 MB");
        expect(cell(tab({}, "en"), translate("music.song.labels.size", "en"))).toBe("10.00 MB");
    });

    it("blanks an untagged size rather than rendering it as zero bytes", () => {
        expect(cell(tab({ size: null }), translate("music.song.labels.size"))).toBe("");
    });

    it("shows the artwork as decoration, since the title is in the very next cell", () => {
        expect(tab().findComponent({ name: "CoverImage" }).props("decorative")).toBe(true);
    });

    it("sends its own navigation back to the artist page it was handed", () => {
        expect(tab().findComponent({ name: "DataTable" }).props("baseUrl")).toBe("/music/artists/artist-1");
    });
});
