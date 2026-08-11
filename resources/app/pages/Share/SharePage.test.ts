import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetPlayerQueueForTests } from "Composables/usePlayerQueue";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import SharePage, { type ShareKind } from "./SharePage.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The guest page at /s/{share} — what somebody with a link and no account sees.
 *
 * WHAT IS TESTED HERE IS WHAT PHP CANNOT SEE, per the repo's layer rule. The props
 * themselves are pinned by `assertInertia` in tests/Feature/Shares/ShowShareTest.php, and
 * repeating them here would only be a second copy of the same contract. What is this page's
 * own is: the expiry rendered in the READER's locale (the server sends a raw instant and
 * knows neither their language nor their timezone), the expired branch replacing the whole
 * page, the missing tiles for a subject that has no such fact, and the decision not to list
 * one track under a hero that has just described it.
 */

/** A live song share, fully tagged; tests override only what they are about. */
const props = (overrides: Record<string, unknown> = {}) => ({
    share: { kind: "song" as ShareKind, validUntil: "2026-08-18T09:30:00+00:00", expired: false },
    subject: {
        name: "Storm",
        artist: "Godspeed You! Black Emperor",
        album: "Lift Your Skinny Fists",
        year: 2000,
        songs: 1,
        duration: 1342,
        coverUrl: "/s/share-1/cover",
        sleeves: []
    },
    tracks: [
        {
            id: "track-1",
            name: "Storm",
            artist: "Godspeed You! Black Emperor",
            album: "Lift Your Skinny Fists",
            coverUrl: "/s/share-1/tracks/track-1/cover",
            duration: 1342,
            href: "/s/share-1",
            streamUrl: "/s/share-1/tracks/track-1/stream"
        }
    ],
    ...overrides
});

/** Mount the page, optionally in English. */
const page = (overrides: Record<string, unknown> = {}, locale: "de" | "en" = "de") =>
    mountApp(SharePage, { props: props(overrides), locale });

/** The hero tile whose label matches, or undefined when it is not rendered. */
const tile = (wrapper: ReturnType<typeof page>, label: string) =>
    wrapper.findAll(".fact-pair").find(node => node.text().startsWith(label));

describe("SharePage", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        // The page renders inside ShareLayout only in the real app; mounted directly it
        // still reads the shared props its components ask for (the queue's user scope).
        setPage({ props: { auth: { user: null }, csrfToken: "test-token" } });
    });

    it("names the shared thing as the page heading", () => {
        expect(page().find("h2").text()).toBe("Storm");
    });

    describe("when the link is still live", () => {
        it("says when it stops working, in the reader's own locale", () => {
            // THE REASON THIS PAGE HAS A VITEST FILE AT ALL: the server sends an ISO-8601
            // instant, and which of "18.8.2026" and "18/08/2026" a recipient reads is decided
            // here, against their language.
            const german = tile(page(), translate("share.expires.label"));
            const english = tile(page({}, "en"), translate("share.expires.label", "en"));

            expect(german?.text()).toContain("18.08.2026");
            expect(english?.text()).toContain("Aug 18, 2026");
        });

        it("shows how many tracks the link grants", () => {
            expect(tile(page(), translate("music.columns.songs"))?.text()).toContain("1");
        });

        it("drops the tiles for facts the tags do not carry", () => {
            // Null in, nothing out. An empty tile claims the file has an artist we failed to
            // name, which is a different thing from having none.
            const wrapper = page({
                subject: { ...props().subject, artist: null, album: null, year: null, duration: null }
            });

            expect(tile(wrapper, translate("music.columns.artist"))).toBeUndefined();
            expect(tile(wrapper, translate("music.columns.album"))).toBeUndefined();
            expect(tile(wrapper, translate("music.columns.year"))).toBeUndefined();
            expect(tile(wrapper, translate("music.columns.duration"))).toBeUndefined();
        });

        it("offers the two verbs that play it", () => {
            expect(page().find(".subject-actions").exists()).toBe(true);
        });
    });

    describe("the track list", () => {
        it("is left out for a song share, which the hero has already described", () => {
            // A one-row list under a hero naming that same track, its artist, its album and
            // its playing time repeats all of it and reads as a rendering fault.
            expect(page().find(".share-tracks").exists()).toBe(false);
        });

        it("is drawn for an album share, which is the content of the page", () => {
            const wrapper = page({
                share: { kind: "album" as ShareKind, validUntil: "2026-08-18T09:30:00+00:00", expired: false }
            });

            expect(wrapper.find(".share-tracks").exists()).toBe(true);
        });
    });

    describe("when the link has expired", () => {
        /** The expired page: no tracks, no cover — the shape the server actually sends. */
        const dead = () =>
            page({
                share: { kind: "album" as ShareKind, validUntil: "2026-08-01T09:30:00+00:00", expired: true },
                subject: { ...props().subject, coverUrl: null },
                tracks: []
            });

        it("says so, and keeps the name so a reader can ask for a new one", () => {
            const wrapper = dead();

            expect(wrapper.text()).toContain(translate("share.expired.title"));
            expect(wrapper.find("h2").text()).toBe("Storm");
        });

        it("offers nothing to press", () => {
            // Not merely empty: the hero and its buttons are GONE. A play button over an
            // empty queue is a button that does nothing, which reads as the page being broken
            // rather than the link being over.
            const wrapper = dead();

            expect(wrapper.find(".hero-section").exists()).toBe(false);
            expect(wrapper.find(".subject-actions").exists()).toBe(false);
            expect(wrapper.find(".share-tracks").exists()).toBe(false);
        });
    });

    describe("an artist share", () => {
        /** An artist link: no cover of its own, a fan of their sleeves instead. */
        const artist = () =>
            page({
                share: { kind: "artist" as ShareKind, validUntil: "2026-08-18T09:30:00+00:00", expired: false },
                subject: {
                    ...props().subject,
                    artist: null,
                    album: null,
                    year: null,
                    coverUrl: null,
                    sleeves: ["/s/share-1/tracks/track-1/cover", "/s/share-1/tracks/track-2/cover"]
                }
            });

        it("fans their own sleeves in place of the photograph this app does not store", () => {
            expect(artist().find(".cover-sleeves").exists()).toBe(true);
        });
    });
});
