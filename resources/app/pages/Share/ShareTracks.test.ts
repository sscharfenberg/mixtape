import { beforeEach, describe, expect, it, vi } from "vitest";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetInertia, setPage } from "Testing/inertia";
import { mountApp } from "Testing/mount";
import ShareTracks from "./ShareTracks.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The guest page's track list.
 *
 * ONE THING HERE IS WORTH A TEST AND IT IS WHAT THE BUTTON MEANS: pressing play on the fourth
 * row queues EVERYTHING the link grants and starts at that row, rather than queueing that one
 * track. Both behaviours look identical on screen — a track starts playing — and the wrong one
 * silently throws away the rest of the album a guest was sent. It is also the rule the
 * playlist's rows follow, so the two must not drift.
 *
 * The rest of the row is markup the server decided (which URLs, which facts), pinned in
 * tests/Feature/Shares/ShowShareTest.php. What a row shows at which BREAKPOINT is a CSS
 * decision and belongs to Playwright — happy-dom has no layout to ask.
 */

/** Three tracks in the shape the server sends them: queue entries with `/s/` URLs. */
const TRACKS = [
    {
        id: "track-1",
        name: "Storm",
        artist: "Godspeed You! Black Emperor",
        album: "Lift Your Skinny Fists",
        coverUrl: "/s/share-1/tracks/track-1/cover",
        duration: 1342,
        href: "/s/share-1",
        streamUrl: "/s/share-1/tracks/track-1/stream"
    },
    {
        id: "track-2",
        name: "Static",
        artist: "Godspeed You! Black Emperor",
        album: "Lift Your Skinny Fists",
        coverUrl: null,
        duration: 1420,
        href: "/s/share-1",
        streamUrl: "/s/share-1/tracks/track-2/stream"
    },
    {
        id: "track-3",
        name: "Sleep",
        artist: "Godspeed You! Black Emperor",
        album: "Lift Your Skinny Fists",
        coverUrl: null,
        duration: 1094,
        href: "/s/share-1",
        streamUrl: "/s/share-1/tracks/track-3/stream"
    }
];

const list = () => mountApp(ShareTracks, { props: { tracks: TRACKS } });

describe("ShareTracks", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        setPage({ props: { auth: { user: null }, csrfToken: "test-token" } });
    });

    it("renders one row per granted track", () => {
        expect(list().findAll(".share-tracks__item")).toHaveLength(3);
    });

    it("queues the whole share and starts at the row that was pressed", async () => {
        const wrapper = list();

        await wrapper.findAll(".share-tracks__play")[2].trigger("click");

        const { tracks, currentIndex } = usePlayerQueue();

        // All three, not one: a share is a running order, and a button that queued only its
        // own row would throw away the rest of what a guest was sent.
        expect(tracks.value.map(track => track.id)).toEqual(["track-1", "track-2", "track-3"]);
        expect(currentIndex.value).toBe(2);
    });

    it("hands the player the share's own stream URL, not a /music one", () => {
        const wrapper = list();

        void wrapper.findAll(".share-tracks__play")[0].trigger("click");

        // The whole reason a guest can play anything: `/music/songs/…/stream` sits behind
        // `auth`, so a queue entry carrying one plays for the owner testing the link and
        // bounces everybody else to the login form.
        expect(usePlayerQueue().tracks.value[0].streamUrl).toBe("/s/share-1/tracks/track-1/stream");
    });

    describe("the fact chips", () => {
        /*
         * A fact that is true of every row is a fact about the SUBJECT, and the hero has
         * already said it. Twelve rows reading "Radiohead · OK Computer" under a hero saying
         * exactly that is noise, and it is the same rule the artist page's songs table
         * follows by hand — worked out from the data here, so a COMPILATION shared as an
         * album still gets its performers.
         */

        it("drops the artist and album when every row carries the same ones", () => {
            const wrapper = list();

            expect(wrapper.find(".share-tracks__fact--artist").exists()).toBe(false);
            expect(wrapper.find(".share-tracks__fact--album").exists()).toBe(false);
        });

        it("keeps the artist on a compilation, where it is what tells the rows apart", () => {
            const wrapper = mountApp(ShareTracks, {
                props: { tracks: [TRACKS[0], { ...TRACKS[1], artist: "Portishead" }] }
            });

            expect(wrapper.findAll(".share-tracks__fact--artist")).toHaveLength(2);
            // …and the album still goes, because that one has not changed.
            expect(wrapper.find(".share-tracks__fact--album").exists()).toBe(false);
        });

        it("keeps the album on an artist share, where the records differ", () => {
            const wrapper = mountApp(ShareTracks, {
                props: { tracks: [TRACKS[0], { ...TRACKS[1], album: "Kid A" }] }
            });

            expect(wrapper.findAll(".share-tracks__fact--album")).toHaveLength(2);
        });
    });

    it("names the track in the button's label, since the glyph cannot", () => {
        const label = list().findAll(".share-tracks__play")[1].attributes("aria-label");

        expect(label).toContain("Static");
    });
});
