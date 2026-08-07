import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import GenreSongs, { type GenreSongRow } from "./GenreSongs.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The genre page's songs tab. It is ArtistSongs with the columns swapped around, and the
 * swap is the point: on a genre page the two facts telling one row from the next are WHO
 * made it and WHICH RECORD it is from, so both are columns and both link out — while the
 * genre column, which would repeat one name down the whole table, is gone.
 *
 * That makes this the only table in the app with TWO cells leading somewhere other than
 * their own row. Each has to survive its own null independently (`artistUrl` and `albumUrl`
 * are null for different reasons — a file crediting nobody, a track in no collection), and
 * a shared guard is the plausible tidy-up that renders `href="null"` for one of them.
 *
 * Disc and track read as positions rather than bare numbers, for the reason spelled out in
 * ArtistSongs.test.ts: "3" is plausible data, so only a test tells the two apart.
 */

/** One row, fully tagged; tests override only what they are about. */
const row = (overrides: Partial<GenreSongRow> = {}): GenreSongRow => ({
    id: "song-1",
    name: "Paranoid Android",
    disc: 1,
    discTotal: 1,
    track: 3,
    trackTotal: 12,
    artist: "Radiohead",
    artistUrl: "/music/artists/artist-1",
    album: "OK Computer",
    albumUrl: "/music/albums/album-1",
    year: 1997,
    duration: 383,
    size: 10_485_760,
    coverUrl: null,
    href: "/music/songs/song-1",
    ...overrides
});

/** Mount the tab over one row. */
const tab = (overrides: Partial<GenreSongRow> = {}, locale: "de" | "en" = "de") =>
    mountApp(GenreSongs, {
        props: {
            baseUrl: "/music/genres/genre-1",
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

/**
 * The hrefs of the cells that lead away from the row, in column order.
 *
 * Scoped to `tbody` because DataTable renders BOTH layouts at once — the desktop table and
 * the narrow card list — from the same cell slots, so an unscoped `findAll` returns every
 * link twice and turns "one link" into a passing assertion for two.
 */
const outboundHrefs = (wrapper: ReturnType<typeof tab>): (string | undefined)[] =>
    wrapper.findAll("tbody .genre-songs__link").map(link => link.attributes("href"));

describe("GenreSongs", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("leaves out the genre column and gives its space to the artist and the album", () => {
        const headers = tab()
            .findAll("th")
            .map(node => node.text());

        expect(headers).not.toContain(translate("music.columns.genre"));
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

    it("sends the artist and the album cells somewhere other than the row's own song", () => {
        expect(outboundHrefs(tab())).toStrictEqual(["/music/artists/artist-1", "/music/albums/album-1"]);
        expect(tab().find(".genre-songs__title").attributes("href")).toBe("/music/songs/song-1");
    });

    it("drops each outbound link on its own, since the two are absent for different reasons", () => {
        // A file crediting nobody still belongs to an album, and vice versa.
        expect(outboundHrefs(tab({ artistUrl: null }))).toStrictEqual(["/music/albums/album-1"]);
        expect(outboundHrefs(tab({ albumUrl: null }))).toStrictEqual(["/music/artists/artist-1"]);
        expect(outboundHrefs(tab({ artistUrl: null, albumUrl: null }))).toStrictEqual([]);
    });

    it("keeps the name readable when there is no page behind it", () => {
        const wrapper = tab({ artist: "Various Artists", artistUrl: null });

        expect(cell(wrapper, translate("music.columns.artist"))).toBe("Various Artists");
    });

    it("reads disc and track as a position in their set, not as bare numbers", () => {
        expect(cell(tab(), translate("music.song.labels.disc"))).toBe("1/1");
        expect(cell(tab(), translate("music.song.labels.track"))).toBe("3/12");
        expect(cell(tab({ track: null, trackTotal: 12 }), translate("music.song.labels.track"))).toBe("");
    });

    it("clocks the duration and sizes the file in the reader's own locale", () => {
        expect(cell(tab(), translate("music.columns.duration"))).toBe("6:23");
        expect(cell(tab(), translate("music.song.labels.size"))).toBe("10,00 MB");
        expect(cell(tab({}, "en"), translate("music.song.labels.size", "en"))).toBe("10.00 MB");
    });

    it("blanks an untagged size rather than rendering it as zero bytes", () => {
        expect(cell(tab({ size: null }), translate("music.song.labels.size"))).toBe("");
    });

    it("sends its own navigation back to the genre page it was handed", () => {
        expect(tab().findComponent({ name: "DataTable" }).props("baseUrl")).toBe("/music/genres/genre-1");
    });
});
