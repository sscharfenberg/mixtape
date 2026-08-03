import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetInertia } from "Testing/inertia";
import { mountApp, translate } from "Testing/mount";
import PlayQueue from "./PlayQueue.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The panel is a thin view over usePlayerQueue (whose own spec covers the operations), so
 * what is left to prove here is the part the composable cannot: what gets DRAWN.
 *
 * Chiefly that an empty queue renders nothing at all. FullLayout keys its grid column off
 * the same `isEmpty`, so a panel that rendered an empty shell would leave a 240px hole
 * beside every page — and that is invisible in a unit test of the composable, which would
 * happily report a queue of length zero either way.
 */

/** A queue track with just enough shape to be identifiable in the DOM. */
const track = (id: string, name: string, artist: string | null = "Radiohead"): QueueTrack => ({
    id,
    name,
    artist,
    album: "The Bends",
    coverUrl: null,
    duration: 120,
    href: `/music/songs/${id}`,
    streamUrl: `/music/songs/${id}/stream`
});

/** Fill the queue, then mount the panel over it. */
const panel = async (tracks: QueueTrack[]) => {
    if (tracks.length) usePlayerQueue().enqueue(tracks);
    const wrapper = mountApp(PlayQueue);
    await nextTick();

    return wrapper;
};

describe("PlayQueue", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        window.localStorage.clear();
    });

    it("renders nothing at all while the queue is empty", async () => {
        const wrapper = await panel([]);

        // Not an empty panel: FullLayout gives the column its 240px off the same
        // condition, so a shell here would indent every page for a queue that is not there.
        expect(wrapper.find("aside").exists()).toBe(false);
        expect(wrapper.html()).toBe("<!--v-if-->");
    });

    it("lists the queue in play order", async () => {
        const wrapper = await panel([track("a", "Airbag"), track("b", "Bones")]);

        expect(wrapper.findAll(".play-queue__name").map(node => node.text())).toStrictEqual(["Airbag", "Bones"]);
    });

    it("marks the loaded track, and only it", async () => {
        const wrapper = await panel([track("a", "Airbag"), track("b", "Bones")]);
        usePlayerQueue().jumpTo(1);
        await nextTick();

        const current = wrapper.findAll(".play-queue__row--current");
        expect(current).toHaveLength(1);
        expect(current[0].text()).toContain("Bones");
        expect(current[0].attributes("aria-current")).toBe("true");
    });

    it("loads the track whose row is clicked", async () => {
        const wrapper = await panel([track("a", "Airbag"), track("b", "Bones")]);

        await wrapper.findAll(".play-queue__load")[1].trigger("click");

        expect(usePlayerQueue().current.value?.name).toBe("Bones");
    });

    it("drops the row whose remove button is pressed", async () => {
        const wrapper = await panel([track("a", "Airbag"), track("b", "Bones")]);

        await wrapper.findAll(".play-queue__remove")[0].trigger("click");

        expect(usePlayerQueue().tracks.value.map(entry => entry.name)).toStrictEqual(["Bones"]);
    });

    it("empties the queue from the menu, and disappears with it", async () => {
        // Clearing sits behind the popover rather than on a bare trash icon in the
        // header — it is destructive, and one stray click in a 240px strip is too
        // cheap a way to lose the queue. The dialog's contents are in the DOM whether
        // it is open or not, so the test clicks the entry directly. Matched by the
        // `--caution` variant rather than by position: repeat sits above it now, and a
        // bare `.popover-list-item` would silently start toggling that instead.
        const wrapper = await panel([track("a", "Airbag")]);

        await wrapper.find(".popover-list-item--caution").trigger("click");
        await nextTick();

        expect(wrapper.find("aside").exists()).toBe(false);
    });

    it("flips repeat from the menu, and shows which way it is set", async () => {
        const wrapper = await panel([track("a", "Airbag")]);
        const toggle = () => wrapper.find(".popover-list-item:not(.popover-list-item--caution)");

        expect(toggle().attributes("aria-pressed")).toBe("false");

        await toggle().trigger("click");

        expect(usePlayerQueue().repeat.value).toBe(true);
        expect(toggle().attributes("aria-pressed")).toBe("true");
        // The fill is how it reads at a glance; aria-pressed is how it reads aloud.
        expect(toggle().classes()).toContain("popover-list-item--selected");
    });

    it("summarises the queue's length and running time", async () => {
        const wrapper = await panel([track("a", "Airbag"), track("b", "Bones")]);

        // 2 x 120s. The count and the clock share a line because a 240px panel has no
        // room for either beside the title.
        expect(wrapper.find(".play-queue__summary").text()).toContain("4:00");
    });

    it("keeps each row a link to the track's own page", async () => {
        // The row's click is "play this", so it cannot also be "show me this" — the
        // title stays a real link, which is also the keyboard path to the song.
        const wrapper = await panel([track("a", "Airbag")]);

        expect(wrapper.find(".play-queue__name").attributes("href")).toBe("/music/songs/a");
    });

    it("leaves out the artist line for a track whose file carried no artist", async () => {
        const wrapper = await panel([track("a", "Airbag", null)]);

        expect(wrapper.find(".play-queue__artist").exists()).toBe(false);
    });

    it("labels the panel for assistive tech", async () => {
        const wrapper = await panel([track("a", "Airbag")]);

        expect(wrapper.find("aside").attributes("aria-label")).toBe(translate("player.queue.label"));
    });
});
