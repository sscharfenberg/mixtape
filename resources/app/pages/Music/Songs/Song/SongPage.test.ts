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
    albumArtist: "Radiohead",
    albumArtistUrl: "/music/artists/radiohead",
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
    streamUrl: "/music/songs/11111111/stream",
    sizeBytes: 15_728_640,
    modifiedAt: "2026-07-28T14:23:05+00:00",
    addedAt: "2026-07-21T09:00:00+00:00",
    path: "Radiohead/OK Computer/02 Paranoid Android.mp3",
    ...overrides
});

/** Mount the page for a song. Nobody has played it unless a test says otherwise. */
const page = (
    overrides: Partial<SongDetail> = {},
    locale: "de" | "en" = "de",
    plays: { own: number; others: number } = { own: 0, others: 0 }
) => mountApp(SongPage, { props: { song: song(overrides), plays }, locale });

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
        expect(page().find("h2").text()).toBe("Paranoid Android");
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
                albumArtist: null,
                albumArtistUrl: null,
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

            expect(wrapper.find("h2").text()).toBe("Paranoid Android");
            expect(wrapper.text()).not.toContain("null");
        });
    });

    describe("the album card", () => {
        /*
         * Scoped to the card, because the HERO renders tiles of its own from the same
         * component — an unscoped `.fact-pair` search finds the hero's album and artist
         * tiles first, so a "the album row precedes the credit" assertion would compare a
         * hero index against a card index and pass whatever the card's own order was.
         */
        const albumCard = (wrapper: ReturnType<typeof page>) =>
            wrapper.findAll(".card").find(card => card.find(".card__title").text() === translate("music.song.groups.album"))!;

        /** The card's fact labels, in the order they are read. */
        const cardLabels = (wrapper: ReturnType<typeof page>): string[] =>
            albumCard(wrapper)
                .findAll(".fact-pair__label")
                .map(node => node.text());

        /** One of the card's tiles, by its label. */
        const cardTile = (wrapper: ReturnType<typeof page>, label: string) =>
            albumCard(wrapper)
                .findAll(".fact-pair")
                .find(node => node.find(".fact-pair__label").text() === label);

        it("names who the RELEASE is credited to, beside who performed the track", () => {
            /*
             * Two facts, not one. They agree on most records, which is exactly why the case
             * that matters is a compilation: the album is credited to "Various Artists"
             * while the track credits its own performer. Collapsing them — or labelling both
             * "Künstler" — would show a reader two tiles with different names and no way to
             * tell which is which.
             */
            const wrapper = page({ artist: "Godspeed You! Black Emperor", albumArtist: "Various Artists" });

            expect(cardTile(wrapper, translate("music.song.labels.albumArtist"))!.text()).toContain("Various Artists");
            // The track's own credit lives in the TAGS card and is untouched by this.
            expect(factValue(wrapper, translate("music.columns.artist"))).toBe("Godspeed You! Black Emperor");
        });

        it("leads to that artist's page, since the credit names something with one", () => {
            const tile = cardTile(page(), translate("music.song.labels.albumArtist"))!;

            expect(tile.find("a").attributes("href")).toBe("/music/artists/radiohead");
        });

        it("prints the credit plainly when the server gave no URL", () => {
            const tile = cardTile(page({ albumArtistUrl: null }), translate("music.song.labels.albumArtist"))!;

            expect(tile.text()).toContain("Radiohead");
            expect(tile.find("a").exists()).toBe(false);
        });

        it("drops the row for an album nobody is credited with, rather than showing a blank", () => {
            const wrapper = page({ albumArtist: null, albumArtistUrl: null });

            expect(cardTile(wrapper, translate("music.song.labels.albumArtist"))).toBeUndefined();
            // …and the rest of the card is untouched.
            expect(cardTile(wrapper, translate("music.columns.album"))!.text()).toContain("OK Computer");
        });

        it("reads the release's identity in order: its name, its credit, then its label", () => {
            // The card is about the release, so the three facts identifying it sit together
            // and in that order — a reordering is invisible in a diff and obvious on screen.
            const labels = cardLabels(page());
            const at = (label: string) => labels.indexOf(label);

            expect(at(translate("music.columns.album"))).toBeGreaterThanOrEqual(0);
            expect(at(translate("music.columns.album"))).toBeLessThan(at(translate("music.song.labels.albumArtist")));
            expect(at(translate("music.song.labels.albumArtist"))).toBeLessThan(
                at(translate("music.song.labels.publisher"))
            );
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

    describe("what it says about listening", () => {
        /*
         * The counts are the server's, but WHETHER TO SAY ANYTHING is this page's decision —
         * which is why it is tested here and not in assertInertia. A zero is left unsaid: a
         * fresh library would otherwise be a wall of "0×" tiles saying only that the feature
         * exists.
         *
         * Read out of the HERO's own metadata row, because that is the claim — these are
         * tiles like the artist and the year beside them, not prose under them.
         */

        /** The listening tiles in the hero, as `label value` text. */
        const tiles = (wrapper: ReturnType<typeof page>): string[] =>
            wrapper
                .findAll(".hero-section__metadata .fact-pair")
                .map(node => node.text())
                .filter(text => text.includes("×"));

        it("says nothing at all about a song nobody has played", () => {
            expect(tiles(page())).toStrictEqual([]);
        });

        it("counts the reader's own listens", () => {
            expect(tiles(page({}, "de", { own: 3, others: 0 }))).toStrictEqual(["Von dir3×"]);
        });

        it("counts everybody else's separately", () => {
            expect(tiles(page({}, "de", { own: 0, others: 5 }))).toStrictEqual(["Von anderen5×"]);
        });

        it("shows both when both have happened, the reader's first", () => {
            expect(tiles(page({}, "de", { own: 2, others: 4 }))).toStrictEqual(["Von dir2×", "Von anderen4×"]);
        });

        it("explains what the number means to everyone, not only to a pointer", () => {
            /*
             * Three things the figure alone cannot answer: what counts as a play, whether
             * repeats count, and whether the same recording elsewhere counts here. The
             * tooltip says all three — and `v-tooltip` is pointer-and-focus only, so the
             * same sentence is also a description, which is the half a test can read.
             */
            const wrapper = page({}, "de", { own: 3, others: 5 });
            const tiles = wrapper.findAll(".hero-section__metadata .fact-pair");
            const described = tiles.filter(tile => tile.attributes("aria-describedby") !== undefined);

            expect(described).toHaveLength(2);
            expect(wrapper.find("#song-plays-own").text()).toContain("Hälfte");
            expect(wrapper.find("#song-plays-others").text()).toContain("gehört");
        });

        it("describes nothing it is not showing", () => {
            const wrapper = page({}, "de", { own: 0, others: 4 });

            expect(wrapper.find("#song-plays-own").exists()).toBe(false);
            expect(wrapper.find("#song-plays-others").exists()).toBe(true);
        });

        it("needs no plural rule, which is what the tile format buys", () => {
            // As a sentence this was a real fork — German wants "einmal", not "1-mal" — and
            // as a tile it is simply the figure.
            expect(tiles(page({}, "de", { own: 1, others: 0 }))).toStrictEqual(["Von dir1×"]);
            expect(tiles(page({}, "en", { own: 1, others: 0 }))).toStrictEqual(["By you1×"]);
        });
    });
});
