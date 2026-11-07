import { beforeEach, describe, expect, it, vi } from "vitest";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import PartyTrack from "./PartyTrack.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * One entry in party mode's track box. In fullscreen the player bar is gone, so these three
 * entries ARE the transport — which is what makes their names and their states worth pinning:
 *
 *   - THE CURRENT ENTRY SAYS WHAT A PRESS WILL DO. "Pause: <title>" while playing, "Play:
 *     <title>" while paused — a screen-reader user in fullscreen has no other play button.
 *   - THE NEIGHBOURS SAY WHICH WAY AND TO WHAT, like the cards on the page.
 *   - THE SAME FACTS AS THE CARDS (trackFacts) — an untagged field disappears here exactly as it
 *     does there.
 *   - THE TITLE IS A REAL HEADING, NOT INSIDE THE BUTTON, for the reason NeighbourTrack's own spec
 *     gives: ARIA prunes a button's descendants.
 */

/** A queue track, with everything present unless a test takes something away. */
const track = (overrides: Partial<QueueTrack> = {}): QueueTrack => ({
    id: "track-1",
    name: "Svefn-g-englar",
    artist: "Sigur Rós",
    album: "Ágætis byrjun",
    coverUrl: null,
    duration: 534,
    href: "/music/songs/track-1",
    streamUrl: "/music/songs/track-1/stream",
    ...overrides
});

/** Mount one entry. */
const entry = (
    props: Partial<{
        kind: "previous" | "current" | "next";
        track: QueueTrack | null;
        genre: string | null;
        playing: boolean;
    }> = {}
) => mountApp(PartyTrack, { props: { kind: "next", track: track(), genre: "Post-Rock", ...props } });

describe("PartyTrack", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("names the direction, the track and its facts for a neighbour, and steps when pressed", async () => {
        const wrapper = entry({ kind: "previous" });

        expect(wrapper.text()).toContain(translate("nowPlaying.previous"));
        expect(wrapper.text()).toContain("Sigur Rós");
        expect(wrapper.text()).toContain("Post-Rock");
        expect(wrapper.text()).toContain("8:54");
        expect(wrapper.get(".party-track__action").attributes("aria-label")).toBe(
            `${translate("nowPlaying.previous")}: Svefn-g-englar`
        );

        await wrapper.get(".party-track__action").trigger("click");
        expect(wrapper.emitted("activate")).toHaveLength(1);
    });

    it("offers to pause the current track while it plays, and to play it while paused", () => {
        const playing = entry({ kind: "current", playing: true });
        expect(playing.text()).toContain(translate("nowPlaying.status.playing"));
        expect(playing.get(".party-track__action").attributes("aria-label")).toBe(
            translate("nowPlaying.party.pause").replace("{name}", "Svefn-g-englar")
        );
        playing.unmount();

        const paused = entry({ kind: "current", playing: false });
        expect(paused.text()).toContain(translate("nowPlaying.status.paused"));
        expect(paused.get(".party-track__action").attributes("aria-label")).toBe(
            translate("nowPlaying.party.play").replace("{name}", "Svefn-g-englar")
        );
    });

    it("drops a fact the file has no answer for, as the neighbour cards do", () => {
        const wrapper = entry({ track: track({ album: null, duration: null }), genre: null });

        expect(wrapper.findAll(".party-track__fact")).toHaveLength(1);
        expect(wrapper.text()).not.toContain("0:00");
    });

    it("keeps its place at the end of the queue, saying so, with nothing to press", () => {
        const wrapper = entry({ kind: "next", track: null });

        expect(wrapper.text()).toContain(translate("nowPlaying.no.next"));
        expect(wrapper.get(".party-track__action").attributes("disabled")).toBeDefined();
    });

    it("makes the current title a level-two heading and the neighbours level three, outside the button", () => {
        const current = entry({ kind: "current" });
        expect(current.find("h2.party-track__title").exists()).toBe(true);
        expect(current.find(".party-track__action h2").exists()).toBe(false);
        current.unmount();

        const next = entry({ kind: "next" });
        expect(next.find("h3.party-track__title").exists()).toBe(true);
    });
});
