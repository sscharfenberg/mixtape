import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import type { SongEntry } from "Types/music";
import SongsWidget from "./SongsWidget.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The same droppable-tag shape as AlbumsWidget, over a song's performer and the year of
 * the album it sits on — a track carries no year of its own, so a song filed under no
 * album has none, and that is the case worth pinning.
 */

/** A song entry. */
const song = (overrides: Partial<SongEntry> = {}): SongEntry => ({
    id: "song-1",
    name: "Paranoid Android",
    artist: "Radiohead",
    year: 1997,
    href: "/music/songs/song-1",
    ...overrides
});

/** Mount the widget over one song. */
const widget = (entry: SongEntry) =>
    mountApp(SongsWidget, { props: { latest: [entry], random: [entry], popular: [entry] } });

/** The rendered pip values, in order. */
const pips = (wrapper: ReturnType<typeof widget>): string[] =>
    wrapper.findAll(".widget-list__pip").map(node => node.text());

describe("SongsWidget", () => {
    beforeEach(() => {
        resetInertia();
        localStorage.clear();
    });

    it("shows the performer and the album's year as pips", () => {
        expect(pips(widget(song()))).toStrictEqual(["Radiohead", "1997"]);
    });

    it("drops the year for a song filed under no album", () => {
        expect(pips(widget(song({ year: null })))).toStrictEqual(["Radiohead"]);
    });

    it("drops the artist for a file crediting nobody", () => {
        expect(pips(widget(song({ artist: null })))).toStrictEqual(["1997"]);
    });

    it("links the entry to the song's own page", () => {
        expect(widget(song()).find(".widget-list__item").attributes("href")).toBe("/music/songs/song-1");
    });
});
