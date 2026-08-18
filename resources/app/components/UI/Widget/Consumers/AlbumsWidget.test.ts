import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import type { AlbumEntry, WidgetModes } from "Types/music";
import AlbumsWidget from "./AlbumsWidget.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * What this widget decides is which facts an album shows and which icon stands for each —
 * and, the part worth a test, that a MISSING tag drops its pip instead of rendering an
 * empty one. Half this collection's rips are untagged in one field or another, so a chip
 * reading "—" would be a common sight rather than an edge case.
 *
 * SongsWidget is the same shape (a droppable artist and year), so the two share these
 * cases; the counts-based widgets are covered by ArtistsWidget/GenresWidget instead.
 */

/** An album entry with everything tagged. */
const album = (overrides: Partial<AlbumEntry> = {}): AlbumEntry => ({
    id: "album-1",
    name: "OK Computer",
    artist: "Radiohead",
    year: 1997,
    plays: 0,
    href: "/music/albums/album-1",
    ...overrides
});

/** Mount the widget over one album. */
const widget = (entry: AlbumEntry) => mountApp(AlbumsWidget, { props: { latest: [entry], random: [entry] } });

/**
 * Mount over explicit per-mode sets with a mode already stored.
 *
 * The stored key is what selects the mode rather than a click on the toggle: `useWidgetMode`
 * reads localStorage synchronously, so this is the state a returning reader actually mounts
 * in — and it exercises the persistence contract at the same time.
 */
const inMode = (mode: string, sets: Partial<WidgetModes<AlbumEntry>>) => {
    localStorage.setItem("mixtape:widget-mode:albums", mode);

    return mountApp(AlbumsWidget, { props: { latest: [], random: [], ...sets } });
};

/** The rendered pip values, in order. */
const pips = (wrapper: ReturnType<typeof widget>): string[] =>
    wrapper.findAll(".widget-list__pip").map(node => node.text());

describe("AlbumsWidget", () => {
    beforeEach(() => {
        resetInertia();
        localStorage.clear();
    });

    it("shows the album's artist and year as pips", () => {
        expect(pips(widget(album()))).toStrictEqual(["Radiohead", "1997"]);
    });

    it("links the entry to the album's own page", () => {
        expect(widget(album()).find(".widget-list__item").attributes("href")).toBe("/music/albums/album-1");
    });

    it("drops the artist pip for a compilation credited to nobody", () => {
        expect(pips(widget(album({ artist: null })))).toStrictEqual(["1997"]);
    });

    it("drops the year pip for an untagged rip", () => {
        expect(pips(widget(album({ year: null })))).toStrictEqual(["Radiohead"]);
    });

    it("renders no pips at all when neither is tagged", () => {
        const wrapper = widget(album({ artist: null, year: null }));

        expect(pips(wrapper)).toStrictEqual([]);
        expect(wrapper.find(".widget-list__name").text()).toBe("OK Computer");
    });

    it("shows the popular set when that is the stored mode", () => {
        const wrapper = inMode("popular", {
            latest: [album({ name: "Newest Rip" })],
            popular: [album({ name: "Most Played" })]
        });

        expect(wrapper.find(".widget-list__name").text()).toBe("Most Played");
    });

    it("says 'not enough data' for an empty popular set rather than the generic empty line", () => {
        // The two emptinesses mean different things and the card is the only place that can
        // tell them apart: popular holds only albums the reader has played, so nothing there
        // is "you have not listened yet" — while the same blank list under `latest` really
        // would mean the collection is empty. The server sends both as `[]`.
        const wrapper = inMode("popular", { popular: [] });

        expect(wrapper.find(".widget-list__empty").text()).toBe(translate("music.notEnoughData"));
    });

    it("keeps the generic empty line for a mode that is not popular", () => {
        const wrapper = inMode("latest", { latest: [] });

        expect(wrapper.find(".widget-list__empty").text()).toBe(translate("music.empty"));
    });

    it("keeps a year of 0 rather than treating it as absent", () => {
        // `year !== null`, not a truthiness check — 0 is a real (if odd) tag value, and a
        // falsy test would silently drop it.
        expect(pips(widget(album({ year: 0 })))).toStrictEqual(["Radiohead", "0"]);
    });
});
