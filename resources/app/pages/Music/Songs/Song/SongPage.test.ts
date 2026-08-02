import { beforeEach, describe, expect, it, vi } from "vitest";
import { getLayoutProps, resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import type { SongDetail } from "Types/music";
import SongPage from "./SongPage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * SongPage turns one raw SongDetail into a hero plus four cards of facts.
 *
 * What is worth testing HERE, given the PHP side already asserts the props arrive: the
 * server sends raw seconds / bytes / Hz / ISO-8601 and every rendering decision is made
 * in this file, in the reader's locale. None of that is visible to `assertInertia`, and
 * none of it is covered by the formatter unit tests either — those prove formatClock
 * works, not that the duration row actually calls it.
 *
 * The other half is the null handling. Almost every field on a SongDetail is nullable
 * because the tags are whatever the ripper wrote, and the page's rule is that an
 * untagged field DISAPPEARS rather than rendering "null", "0:00" or an empty tile.
 */

/** A fully-tagged song; tests override only the fields they are about. */
const song = (overrides: Partial<SongDetail> = {}): SongDetail => ({
    id: "11111111-1111-4111-8111-111111111111",
    name: "Paranoid Android",
    artist: "Radiohead",
    artistUrl: "/music/artists/radiohead",
    album: "OK Computer",
    albumUrl: "/music/albums/ok-computer",
    genre: "Alternative Rock",
    genreUrl: "/music/genres/alternative-rock",
    year: 1997,
    composer: "Thom Yorke",
    publisher: "Parlophone",
    duration: 383,
    track: 2,
    trackTotal: 12,
    disc: 1,
    discTotal: 1,
    codec: "MPEG1 L3",
    channel: "stereo",
    sampleRate: 44100,
    bitRate: 320000,
    vbr: false,
    cover: true,
    coverUrl: "/music/songs/11111111/cover",
    sizeBytes: 15_728_640,
    modifiedAt: "2026-07-28T14:23:05+00:00",
    addedAt: "2026-07-21T09:00:00+00:00",
    path: "Radiohead/OK Computer/02 Paranoid Android.mp3",
    ...overrides
});

/** Mount the page for a song. */
const page = (overrides: Partial<SongDetail> = {}, locale: "de" | "en" = "de") =>
    mountApp(SongPage, { props: { song: song(overrides) }, locale });

/** The rendered value sitting next to `label`, or undefined when the row is absent. */
const factValue = (wrapper: ReturnType<typeof page>, label: string): string | undefined =>
    wrapper
        .findAll(".fact-pair")
        .find(node => node.text().startsWith(label))
        ?.text()
        .slice(label.length)
        .trim();

describe("SongPage", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("shows the song's title as the page heading", () => {
        expect(page().find("h1").text()).toBe("Paranoid Android");
    });

    it("declares a breadcrumb trail ending in the song's own title", () => {
        page();

        // The title is DATA, so it must be a raw label rather than a catalog key.
        expect(getLayoutProps().breadcrumbs).toStrictEqual([
            { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
            { labelKey: "music.widgets.songs", href: "/music/songs", icon: "song" },
            { label: "Paranoid Android" }
        ]);
    });

    describe("the raw values the server sends", () => {
        it("renders the duration as a clock, not seconds", () => {
            expect(factValue(page(), translate("music.columns.duration"))).toBe("6:23");
        });

        it("renders the file size in the reader's locale", () => {
            // 15728640 bytes = exactly 15 MiB; German uses a comma for the decimal.
            expect(factValue(page(), translate("music.song.labels.size"))).toBe("15,00 MB");
            expect(factValue(page({}, "en"), translate("music.song.labels.size", "en"))).toBe("15.00 MB");
        });

        it("renders the sample rate grouped, with its unit", () => {
            const value = factValue(page(), translate("music.song.labels.sampleRate"));

            expect(value).toBe(`44.100 ${translate("music.song.units.sampleRate")}`);
        });

        it("converts the bit rate from bits to kbit/s", () => {
            // Stored as 320000 bps; every encoder dialog says 320.
            expect(factValue(page(), translate("music.song.labels.bitRate"))).toBe(
                `320 ${translate("music.song.units.bitRate")}`
            );
        });

        it("marks a VBR file's bit rate as the average it is", () => {
            const value = factValue(page({ vbr: true, bitRate: 192000 }), translate("music.song.labels.bitRate"));

            expect(value).toBe(`192 ${translate("music.song.units.bitRate")} (${translate("music.song.vbr")})`);
        });

        it("renders the track position as index/total", () => {
            expect(factValue(page(), translate("music.song.labels.track"))).toBe("2/12");
        });

        it("drops the denominator when a multi-disc rip numbers past its own disc", () => {
            expect(factValue(page({ track: 17, trackTotal: 8 }), translate("music.song.labels.track"))).toBe("17");
        });

        it("renders timestamps as locale date-times", () => {
            const value = factValue(page(), translate("music.song.labels.modifiedAt"));

            // TZ is pinned to UTC in vitest.config.ts, so this is the sent instant.
            expect(value).toBe("28.07.2026, 14:23:05");
        });

        it("translates the channel layout rather than printing the raw enum", () => {
            expect(factValue(page(), translate("music.song.labels.channel"))).toBe(translate("music.channel.stereo"));
        });

        it("renders the embedded-cover flag as a yes/no", () => {
            expect(factValue(page({ cover: true }), translate("music.song.labels.cover"))).toBe(
                translate("common.yes")
            );
            expect(factValue(page({ cover: false }), translate("music.song.labels.cover"))).toBe(
                translate("common.no")
            );
        });
    });

    describe("untagged fields", () => {
        it("drops a row whose value is absent rather than printing null", () => {
            const wrapper = page({ composer: null, publisher: null });

            expect(wrapper.text()).not.toContain("null");
            expect(factValue(wrapper, translate("music.song.labels.composer"))).toBeUndefined();
        });

        it("drops the duration rather than claiming 0:00", () => {
            expect(factValue(page({ duration: null }), translate("music.columns.duration"))).toBeUndefined();
        });

        it("drops the track row for a file with no track tag", () => {
            expect(factValue(page({ track: null }), translate("music.song.labels.track"))).toBeUndefined();
        });

        it("drops both timestamps when the scan recorded neither", () => {
            const wrapper = page({ modifiedAt: null, addedAt: null });

            expect(factValue(wrapper, translate("music.song.labels.modifiedAt"))).toBeUndefined();
            expect(factValue(wrapper, translate("music.song.labels.addedAt"))).toBeUndefined();
        });

        it("still renders the page when almost nothing is tagged", () => {
            // A bare rip: a title and a path, nothing else. It must not throw.
            const wrapper = page({
                artist: null,
                artistUrl: null,
                album: null,
                albumUrl: null,
                genre: null,
                genreUrl: null,
                year: null,
                composer: null,
                publisher: null,
                duration: null,
                track: null,
                trackTotal: null,
                disc: null,
                discTotal: null,
                codec: null,
                channel: null,
                sampleRate: null,
                bitRate: null,
                sizeBytes: null,
                modifiedAt: null,
                addedAt: null,
                coverUrl: null
            });

            expect(wrapper.find("h1").text()).toBe("Paranoid Android");
            expect(wrapper.text()).not.toContain("null");
        });
    });

    describe("the facts that lead somewhere", () => {
        it("links the artist, album and genre to the URLs the server decided", () => {
            const hrefs = page()
                .findAll("a")
                .map(node => node.attributes("href"));

            expect(hrefs).toContain("/music/artists/radiohead");
            expect(hrefs).toContain("/music/albums/ok-computer");
            expect(hrefs).toContain("/music/genres/alternative-rock");
        });

        it("prints the name plainly when the server gave no URL, with no dead link", () => {
            const wrapper = page({ genreUrl: null });

            expect(wrapper.text()).toContain("Alternative Rock");
            expect(wrapper.findAll("a").map(node => node.attributes("href"))).not.toContain(
                "/music/genres/alternative-rock"
            );
        });
    });

    describe("the hero", () => {
        it("shows the artwork as the subject, so it is not decorative here", () => {
            // Unlike a listing row, where the title sits in the next cell.
            const cover = page().find("img");

            expect(cover.attributes("src")).toBe("/music/songs/11111111/cover");
            expect(cover.attributes("alt")).toBe("OK Computer");
        });

        it("falls back to the song's own name as alt when it is filed under no album", () => {
            expect(page({ album: null, albumUrl: null }).find("img").attributes("alt")).toBe("Paranoid Android");
        });

        it("draws the placeholder instead of requesting an image that would 404", () => {
            const wrapper = page({ coverUrl: null });

            expect(wrapper.find("img").exists()).toBe(false);
        });

        it("omits a hero tile whose tag is missing", () => {
            const wrapper = page({ year: null });
            const heroTiles = wrapper.findAll(".hero-section .fact-pair");

            expect(heroTiles.map(node => node.text()).join(" ")).not.toContain(translate("music.columns.year"));
        });
    });
});
