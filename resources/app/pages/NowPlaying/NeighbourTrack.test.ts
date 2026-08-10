import { beforeEach, describe, expect, it, vi } from "vitest";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import NeighbourTrack from "./NeighbourTrack.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The previous / next cards on the Now Playing page.
 *
 * What is worth testing here is what the SERVER cannot see and the queue's own spec does not
 * cover — how a card behaves when the facts are missing, which on a real collection is most of
 * them:
 *
 *   - AN UNTAGGED RIP shows its title and nothing else, rather than a column of blank rows with
 *     icons beside them. Plenty of files carry no album and no genre.
 *   - THE END OF THE QUEUE still renders a card. If it vanished, the queue below would jump up
 *     and down as playback advanced — a layout moving for reasons a reader cannot see.
 *   - THE ACCESSIBLE NAME carries the direction AND the track. The direction alone repeats on two
 *     cards; the title alone gives no clue which way pressing it goes.
 *   - THE TITLE IS A HEADING, AND NOT INSIDE THE BUTTON. Markup, so this is the cheapest layer
 *     that can answer it — and the distinction is not cosmetic: ARIA prunes a button's
 *     descendants, so a heading in there reaches nobody.
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

/** Mount one card. */
const card = (props: Partial<{ direction: "previous" | "next"; track: QueueTrack | null; genre: string | null }> = {}) =>
    mountApp(NeighbourTrack, {
        props: { direction: "next", track: track(), genre: "Post-Rock", ...props }
    });

describe("NeighbourTrack", () => {
    beforeEach(() => {
        resetInertia();
    });

    it("names the direction and shows the track's facts", () => {
        const wrapper = card();
        const text = wrapper.text();

        expect(text).toContain(translate("nowPlaying.next"));
        expect(text).toContain("Svefn-g-englar");
        expect(text).toContain("Sigur Rós");
        expect(text).toContain("Ágætis byrjun");
        expect(text).toContain("Post-Rock");
        // Runtime as a clock, formatted here rather than sent that way — the server-sends-raw rule.
        expect(text).toContain("8:54");
    });

    it("drops the facts an untagged file has no answer for", () => {
        const wrapper = card({ track: track({ artist: null, album: null, duration: null }), genre: null });

        expect(wrapper.text()).toContain("Svefn-g-englar");
        // One row — the title — rather than four with three of them empty.
        expect(wrapper.findAll(".neighbour__fact")).toHaveLength(0);
    });

    it("shows the genre only once the server has answered", () => {
        // The page renders from the queue immediately and fills the genre in a moment later, so
        // "not fetched yet" has to look like "no genre" rather than like a broken row.
        const wrapper = card({ genre: null });

        expect(wrapper.findAll(".neighbour__fact").map(fact => fact.text())).not.toContain("Post-Rock");
    });

    it("makes the title a real heading, and keeps it out of the button", () => {
        /*
         * Both halves are the fix (2026-08-10). A track title is what a reader navigating by
         * headings should land on — and while the card WAS the `<button>`, it could not be one:
         * ARIA prunes a button's descendants ("children presentational"), so an `<h3>` in there
         * satisfies an audit tool and reaches no screen reader, and it is not valid HTML either.
         * Hence the card being a container with the control stretched over it.
         *
         * Level three because the page's own sections are h2 (the hero's title, the queue's
         * heading) and these cards hang under them.
         */
        const heading = card().find("h3.neighbour__title");

        expect(heading.text()).toBe("Svefn-g-englar");
        expect(heading.element.closest("button")).toBeNull();
    });

    it("keeps its place at the end of the queue, disabled rather than gone", () => {
        const wrapper = card({ direction: "previous", track: null });

        expect(wrapper.find(".neighbour").exists()).toBe(true);
        expect(wrapper.find("button").attributes("disabled")).toBeDefined();
        expect(wrapper.text()).toContain(translate("nowPlaying.no.previous"));
    });

    it("announces which way it goes and what it would play", () => {
        const label = card().find("button").attributes("aria-label");

        expect(label).toContain(translate("nowPlaying.next"));
        expect(label).toContain("Svefn-g-englar");
    });

    it("asks to be stepped to when pressed, and not when there is nothing there", async () => {
        const wrapper = card();
        await wrapper.find("button").trigger("click");

        expect(wrapper.emitted("step")).toHaveLength(1);

        const empty = card({ track: null });
        await empty.find("button").trigger("click");

        expect(empty.emitted("step")).toBeUndefined();
    });
});
