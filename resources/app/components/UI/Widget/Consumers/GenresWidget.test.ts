import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import type { GenreEntry } from "Types/music";
import GenresWidget from "./GenresWidget.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The genre pips are the three numbers its own detail page shows, by the same rules —
 * artists and albums by DOMINANT genre, songs literally. That agreement is the point: a
 * reader meeting a genre here and then on its page must not be told two different things,
 * and the counts come from the server, so what is tested here is that the card shows all
 * three and shows them in that order.
 */

/** A genre entry. */
const genre = (overrides: Partial<GenreEntry> = {}): GenreEntry => ({
    id: "genre-1",
    name: "Black Metal",
    artists: 20,
    albums: 45,
    songs: 360,
    href: "/music/genres/genre-1",
    ...overrides
});

/** Mount the widget over one genre. */
const widget = (entry: GenreEntry) =>
    mountApp(GenresWidget, { props: { latest: [entry], random: [entry], popular: [entry] } });

/** The rendered pip values, in order. */
const pips = (wrapper: ReturnType<typeof widget>): string[] =>
    wrapper.findAll(".widget-list__pip").map(node => node.text());

describe("GenresWidget", () => {
    beforeEach(() => {
        resetInertia();
        localStorage.clear();
    });

    it("shows artist, album and song counts in that order", () => {
        expect(pips(widget(genre()))).toStrictEqual(["20", "45", "360"]);
    });

    it("keeps every pip when a genre is nobody's main genre", () => {
        // The server COALESCEs those two to 0; the card must show the 0 rather than drop it,
        // because "no artist calls this their main genre" is a fact about the genre.
        expect(pips(widget(genre({ artists: 0, albums: 0 })))).toStrictEqual(["0", "0", "360"]);
    });

    it("links the entry to the genre's own page", () => {
        expect(widget(genre()).find(".widget-list__item").attributes("href")).toBe("/music/genres/genre-1");
    });

    it("labels the pips with an artist, an album and a song icon", () => {
        const symbols = widget(genre())
            .findAll(".widget-list__pip use")
            .map(node => node.attributes("href"));

        expect(symbols).toStrictEqual(["#artist", "#album", "#song"]);
    });
});
