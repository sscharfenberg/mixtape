import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetPlayerAudioForTests, usePlayerAudio } from "Composables/usePlayerAudio";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import type { QueueTrack } from "Composables/usePlayerQueue";
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
 * page, the missing tiles for a subject that has no such fact — and, since 2026-08-12, WHAT
 * THE PAGE DOES TO THE QUEUE ON ARRIVAL.
 *
 * That last one earns a unit test rather than a browser one because it is a decision about
 * module state made in `onMounted`, and both of its branches are cheap to set up here and
 * awkward to stage in a browser: filling an empty queue, and standing down for a player that
 * is already running. Whether a guest then HEARS anything is Playwright's (guest/share.spec.ts)
 * — happy-dom has no decoder.
 */

/** A live song share, fully tagged; tests override only what they are about. */
const props = (overrides: Record<string, unknown> = {}) => ({
    share: { kind: "song" as ShareKind, validUntil: "2026-08-18T09:30:00+00:00", expired: false },
    subject: {
        name: "Storm",
        artist: "Godspeed You! Black Emperor",
        album: "Lift Your Skinny Fists",
        year: 2000,
        genre: "Post-Rock",
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

/**
 * A node's text with runs of whitespace squeezed to one space.
 *
 * The intro is assembled around an ELEMENT (the kind chip, which carries an icon), so its
 * markup leaves newlines and indentation between the parts. A browser collapses those when it
 * lays the sentence out; happy-dom's `text()` hands them over as they are, so an assertion on
 * the sentence has to do the collapsing itself or it is testing the template's indentation.
 */
const sentence = (text: string): string => text.replace(/\s+/gu, " ").trim();

/** The hero tile whose label matches, or undefined when it is not rendered. */
const tile = (wrapper: ReturnType<typeof page>, label: string) =>
    wrapper.findAll(".fact-pair").find(node => node.text().startsWith(label));

/** A queue entry from somewhere else entirely — what a reader might already be listening to. */
const elsewhere: QueueTrack = {
    id: "other-1",
    name: "Sleep Walk",
    artist: "Santo & Johnny",
    album: null,
    coverUrl: null,
    duration: 140,
    href: "/music/songs/other-1",
    streamUrl: "/music/songs/other-1/stream"
};

describe("SharePage", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        resetPlayerAudioForTests();
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
                subject: { ...props().subject, artist: null, album: null, year: null, genre: null, duration: null }
            });

            expect(tile(wrapper, translate("music.columns.artist"))).toBeUndefined();
            expect(tile(wrapper, translate("music.columns.album"))).toBeUndefined();
            expect(tile(wrapper, translate("music.columns.year"))).toBeUndefined();
            expect(tile(wrapper, translate("music.columns.genre"))).toBeUndefined();
            expect(tile(wrapper, translate("music.columns.duration"))).toBeUndefined();
        });

        it("says what kind of music it is, and does not link into the library", () => {
            // The genre tile arrived on 2026-08-13 (the owner): it is the fact that means most
            // to somebody who does not know the band. UNLINKED, unlike every other page that
            // draws it — a genre page lives under `/music`, so a guest following it would land
            // on the login form.
            const genre = tile(page(), translate("music.columns.genre"));

            expect(genre?.text()).toContain("Post-Rock");
            expect(genre?.element.tagName).not.toBe("A");
            expect(genre?.find("a").exists()).toBe(false);
        });

        it("explains itself to somebody who has never been here", () => {
            // The only page in the app a stranger can reach, and the header above it looks like
            // an app they have never signed into — so the hero says the music was sent to them
            // and that no account is involved. It is also what fills the space beside the cover.
            //
            // ONE SENTENCE PER KIND, because German agrees its determiner and pronoun with the
            // noun — so this asserts the SONG copy with the noun substituted, which is exactly
            // what a reader of a song link sees. The noun is an element (a chip with the app's
            // glyph), which is why the sentence is assembled by `<i18n-t>` rather than by `t()`.
            const intro = page().find(".share__intro");

            expect(sentence(intro.text())).toBe(
                translate("share.intro.song").replace("{kind}", translate("share.kind.song"))
            );
            expect(sentence(intro.find(".share__kind").text())).toBe(translate("share.kind.song"));
        });

        it("warns that nothing here is remembered, and says what to do about it", () => {
            /*
             * ShareLayout runs the queue in ephemeral mode, so a reader who closes the tab and
             * comes back starts at the top. Unsaid, that reads as the page having forgotten
             * rather than never having been asked to remember — and it is the one honest pitch
             * this page can make, the app being invite-only.
             *
             * ONE SENTENCE FOR EVERY KIND, unlike the intro above it, because it names no noun
             * for German to agree with. Asserted on all five so a future kind cannot quietly
             * lose it the way the intro's own per-kind copy nearly did.
             */
            for (const kind of ["song", "album", "artist", "playlist", "audiobook"] as const) {
                const wrapper = page({ share: { kind, validUntil: "2026-08-18T09:30:00+00:00", expired: false } });

                expect(sentence(wrapper.find(".share__not-kept").text())).toBe(translate("share.notKept"));
                wrapper.unmount();
            }

            // And in English, because a guest sent a link is the reader least likely to share
            // the owner's language — the same reason the expiry tile is asserted in both.
            expect(sentence(page({}, "en").find(".share__not-kept").text())).toBe(
                translate("share.notKept", "en")
            );
        });

        it("keeps the two halves of the description apart, so neither runs into the other", () => {
            // A `condense`d template drops the whitespace between them outright, so they are
            // two blocks rather than two sentences on one line — see the style note. Without
            // that they render as "…ohne Anmeldung.Auf dieser Seite…".
            const description = page().find(".hero-section__description");

            expect(description.find(".share__intro").exists()).toBe(true);
            expect(description.find(".share__not-kept").exists()).toBe(true);
            // Invalid HTML a browser would silently un-nest: HeroSection already wraps the slot
            // in a <p>, so the second half has to be a span.
            expect(description.find(".share__not-kept").element.tagName).toBe("SPAN");
        });

        it("names the kind in the intro the way that kind's own grammar needs", () => {
            // The bug this pins is a German one: a single sentence with the noun swapped in
            // reads "Dieses Song" / "Diese Album" in three of four cases. Each kind therefore
            // has its own copy, and the page picks it off `share.kind`.
            //
            // EVERY kind, which is the half this loop was missing: `audiobook` arrived written
            // to a different pattern entirely — `<strong>{name}</strong>`, with no `{kind}` for
            // the chip to fill and HTML vue-i18n warns about on every render. A loop over four
            // of five kinds is a loop that cannot see the fifth being wrong.
            for (const kind of ["song", "album", "artist", "playlist", "audiobook"] as const) {
                const wrapper = page({ share: { kind, validUntil: "2026-08-18T09:30:00+00:00", expired: false } });

                expect(sentence(wrapper.find(".share__intro").text())).toBe(
                    translate(`share.intro.${kind}`).replace("{kind}", translate(`share.kind.${kind}`))
                );
                wrapper.unmount();
            }
        });

        it("offers one verb, not the pair a Music hero wears", () => {
            // `enqueue` is gone because the link's tracks are already queued — appending them
            // would be a way to hear everything twice.
            expect(page().find(".share__play").exists()).toBe(true);
            expect(page().find(".subject-actions").exists()).toBe(false);
        });
    });

    describe("the queue it arrives with", () => {
        it("holds the link's tracks, pointing at the share's own stream", () => {
            page();

            const { tracks, current } = usePlayerQueue();

            expect(tracks.value).toHaveLength(1);
            expect(current.value?.streamUrl).toBe("/s/share-1/tracks/track-1/stream");
        });

        it("is loaded but not started, because a browser would refuse anyway", () => {
            page();

            expect(usePlayerAudio().isPlaying.value).toBe(false);
        });

        it("leaves a player that is already running alone", () => {
            // The reader this protects is the OWNER opening a link they minted: replacing the
            // queue unasked would cut their own music off mid-track.
            usePlayerQueue().playNow([elsewhere]);
            usePlayerAudio().isPlaying.value = true;

            page();

            expect(usePlayerQueue().current.value?.id).toBe("other-1");
        });

        it("shows the player's own rows below the hero", () => {
            expect(page().find(".now-playing-section").exists()).toBe(true);
        });

        it("leaves out the previous / next pair for a one-track link", async () => {
            // Every song share is one track, and a guest has no library to queue from — so
            // nothing can ever arrive either side of it, and the pair would be two dead boxes.
            // Now Playing keeps them for the opposite reason: its queue grows.
            const wrapper = page();
            await wrapper.vm.$nextTick();

            expect(wrapper.find(".now-playing-section__neighbours").exists()).toBe(false);
        });

        it("keeps the pair as soon as there is something on one side", async () => {
            const wrapper = page({
                share: { kind: "album" as ShareKind, validUntil: "2026-08-18T09:30:00+00:00", expired: false },
                tracks: [...props().tracks, { ...props().tracks[0], id: "track-2", name: "Sleep" }]
            });
            // A TICK IS REQUIRED, and it is the queue-on-arrival that makes it so: the first
            // render happens before `onMounted` fills the queue, so the pair is decided against
            // an empty one. The same tick every reader gets, and never sees.
            await wrapper.vm.$nextTick();

            expect(wrapper.find(".now-playing-section__neighbours").exists()).toBe(true);
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
            // Not merely empty: the hero, its button and the player's rows are all GONE. A
            // play button over an empty queue is a button that does nothing, which reads as
            // the page being broken rather than the link being over.
            const wrapper = dead();

            expect(wrapper.find(".hero-section").exists()).toBe(false);
            expect(wrapper.find(".share__play").exists()).toBe(false);
            expect(wrapper.find(".now-playing-section").exists()).toBe(false);
        });

        it("does not empty a queue on its way past", () => {
            // `playNow([])` would, which is why the mount guard asks the TRACKS rather than
            // the expiry flag: a dead link is not a reason to stop somebody's music.
            usePlayerQueue().playNow([elsewhere]);

            dead();

            expect(usePlayerQueue().tracks.value).toHaveLength(1);
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

    describe("a playlist share", () => {
        /** A shared playlist: no cover of its own, a fan of its records instead. */
        const playlist = () =>
            page({
                share: { kind: "playlist" as ShareKind, validUntil: "2026-08-18T09:30:00+00:00", expired: false },
                subject: {
                    ...props().subject,
                    name: "Freitagabend",
                    artist: null,
                    album: null,
                    year: null,
                    genre: null,
                    coverUrl: null,
                    sleeves: ["/s/share-1/tracks/track-1/cover"]
                }
            });

        it("fans its records, because a playlist is not one", () => {
            // Shareable since 2026-08-13. The hero it gets is the ARTIST's shape rather than the
            // album's — no single picture — which is also what the playlist's own page draws.
            const wrapper = playlist();

            expect(wrapper.find(".cover-sleeves").exists()).toBe(true);
            expect(wrapper.find("h2").text()).toBe("Freitagabend");
        });

        it("wears the playlist glyph, in the heading and in the intro's chip", () => {
            // The same icon the app uses for a playlist everywhere else — the heading and the
            // sentence read it off one map, so they cannot come to disagree.
            const wrapper = playlist();

            expect(wrapper.find("h2").html()).toContain("playlist");
            expect(sentence(wrapper.find(".share__kind").text())).toBe(translate("share.kind.playlist"));
        });
    });
});
