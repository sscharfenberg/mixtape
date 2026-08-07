import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import ArtistSongs, { type SongRow } from "./ArtistSongs.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The artist page's songs tab. Three of its decisions are this component's own and none of
 * them shows up as a broken page when it goes wrong.
 *
 * NO ARTIST COLUMN. Every row is by the artist whose page this is, so the column would
 * repeat one name down the whole table; the ALBUM takes that slot. Re-adding it (by copying
 * the songs listing's column set, the obvious thing to do) wastes a column and looks fine.
 *
 * DISC AND TRACK READ AS A POSITION, "3/12" rather than "3". A bare number is plausible
 * data, which is exactly why the difference has to be pinned: nobody reviewing a screenshot
 * would call "3" wrong. `formatPosition` also drops a denominator that would LIE — a total
 * smaller than the index, which happens in the collection's odd rips — so both branches
 * matter.
 *
 * THE ALBUM CELL LEADS SOMEWHERE ELSE THAN ITS ROW. The row opens the song; this opens the
 * album. It is the only cell that does, which is why it links at all — and only when the
 * track belongs to a collection, since `albumUrl` is null otherwise and would render an
 * href of "null".
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
    album: "OK Computer",
    year: 1997,
    albumUrl: "/music/albums/album-1",
    duration: 383,
    size: 10_485_760,
    coverUrl: null,
    href: "/music/songs/song-1",
    ...overrides
});

/** Mount the tab over one row. */
const tab = (overrides: Partial<SongRow> = {}, locale: "de" | "en" = "de") =>
    mountApp(ArtistSongs, {
        props: {
            baseUrl: "/music/artists/artist-1",
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
    const index = wrapper.findAll("th").findIndex(th => th.text() === header);

    return wrapper.findAll("tbody td")[index].text();
};

describe("ArtistSongs", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("leaves out the artist column, since every row is by the same one", () => {
        const headers = tab()
            .findAll("th")
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
