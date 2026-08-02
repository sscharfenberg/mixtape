import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import type { ArtistEntry } from "Types/music";
import ArtistsWidget from "./ArtistsWidget.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The counts-based shape, as opposed to the albums/songs widgets' droppable tags: all
 * three pips ALWAYS render, because 0 is an answer about an artist rather than a missing
 * tag. Dropping a zero would quietly turn "this artist has no album of their own" into
 * "we don't know", which are different things.
 *
 * The duration is the other half: the server sends raw seconds and the widget clocks them,
 * so a regression here shows as a five-digit number of seconds in a pip.
 */

/** An artist entry. */
const artist = (overrides: Partial<ArtistEntry> = {}): ArtistEntry => ({
    id: "artist-1",
    name: "Devin Townsend",
    albums: 24,
    songs: 406,
    duration: 131_284,
    href: "/music/artists/artist-1",
    ...overrides
});

/** Mount the widget over one artist. */
const widget = (entry: ArtistEntry) =>
    mountApp(ArtistsWidget, { props: { latest: [entry], random: [entry], popular: [entry] } });

/** The rendered pip values, in order. */
const pips = (wrapper: ReturnType<typeof widget>): string[] =>
    wrapper.findAll(".widget-list__pip").map(node => node.text());

describe("ArtistsWidget", () => {
    beforeEach(() => {
        resetInertia();
        localStorage.clear();
    });

    it("shows album count, song count and playing time", () => {
        expect(pips(widget(artist()))).toStrictEqual(["24", "406", "36:28:04"]);
    });

    it("clocks the raw seconds rather than printing them", () => {
        const shown = pips(widget(artist({ duration: 131_284 })));

        expect(shown).toContain("36:28:04");
        expect(shown.join(" ")).not.toContain("131284");
    });

    it("keeps every pip when the counts are zero", () => {
        // 0 is an answer here — an artist can perform tracks without owning an album.
        expect(pips(widget(artist({ albums: 0, songs: 0, duration: 0 })))).toStrictEqual(["0", "0", "0:00"]);
    });

    it("links the entry to the artist's own page", () => {
        expect(widget(artist()).find(".widget-list__item").attributes("href")).toBe("/music/artists/artist-1");
    });

    it("labels the pips with an album, a song and a duration icon", () => {
        const symbols = widget(artist())
            .findAll(".widget-list__pip use")
            .map(node => node.attributes("href"));

        expect(symbols).toStrictEqual(["#album", "#song", "#duration"]);
    });
});
