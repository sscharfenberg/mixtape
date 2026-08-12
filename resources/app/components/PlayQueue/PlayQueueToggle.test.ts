import { beforeEach, describe, expect, it, vi } from "vitest";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import {
    notePlayQueuePanel,
    resetPlayQueuePanelForTests,
    usePlayQueuePanel
} from "Composables/usePlayQueuePanel";
import { resetInertia } from "Testing/inertia";
import { iconNames, mountApp, translate } from "Testing/mount";
import PlayQueueToggle from "./PlayQueueToggle.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The header's narrow-screen control for the queue panel. Which WIDTH it appears at is a
 * media query and belongs to Playwright; what is left here is the part CSS cannot express
 * — that the button is absent entirely when there is nothing for it to open, and that its
 * glyph and its announced state stay in step with the panel.
 *
 * TWO CONDITIONS HAVE TO HOLD, and the second one is why most of these tests declare a panel
 * before mounting: there must be a queue, AND there must be a panel on the page to show it in
 * (`notePlayQueuePanel`, registered by PlayQueue when it mounts). The share space has the first
 * without the second, and no panel there means no button.
 */

/** A queue track, shaped just enough to be enqueued. */
const track = (id: string): QueueTrack => ({
    id,
    name: `Track ${id}`,
    artist: null,
    album: null,
    coverUrl: null,
    duration: 60,
    href: `/music/songs/${id}`,
    streamUrl: `/music/songs/${id}/stream`
});

describe("PlayQueueToggle", () => {
    beforeEach(() => {
        resetInertia();
        resetPlayerQueueForTests();
        resetPlayQueuePanelForTests();
        window.localStorage.clear();
    });

    it("renders nothing while the queue is empty", () => {
        // It would open a panel that draws nothing — a control that looks live and is not.
        const wrapper = mountApp(PlayQueueToggle);

        expect(wrapper.find("button").exists()).toBe(false);
    });

    it("appears once there is a queue to show", () => {
        notePlayQueuePanel(true);
        usePlayerQueue().enqueue(track("a"));

        expect(mountApp(PlayQueueToggle).find("button").exists()).toBe(true);
    });

    it("renders nothing where the layout mounts no panel, however full the queue is", () => {
        // The guest share space: the queue is on the PAGE there, and the panel is a signed-in
        // reader's affordance. A button offering to open what is not rendered is worse than no
        // button, and this is the condition that keeps it away without the header having to
        // know which layout it is in.
        usePlayerQueue().enqueue(track("a"));

        expect(mountApp(PlayQueueToggle).find("button").exists()).toBe(false);
    });

    it("goes away again when the panel unmounts under it", async () => {
        notePlayQueuePanel(true);
        usePlayerQueue().enqueue(track("a"));
        const wrapper = mountApp(PlayQueueToggle);

        // Navigating out of the app and into a share link, which is a layout swap: the old
        // panel unmounts and says so, and the header follows without being told separately.
        notePlayQueuePanel(false);
        await wrapper.vm.$nextTick();

        expect(wrapper.find("button").exists()).toBe(false);
    });

    it("offers the queue glyph while the panel is shut", () => {
        notePlayQueuePanel(true);
        usePlayerQueue().enqueue(track("a"));
        const wrapper = mountApp(PlayQueueToggle);

        expect(iconNames(wrapper)).toStrictEqual(["play_queue"]);
        expect(wrapper.find("button").attributes("aria-expanded")).toBe("false");
        expect(wrapper.find("button").attributes("aria-label")).toBe(translate("player.queue.show"));
    });

    it("becomes a close button once the panel is open", async () => {
        notePlayQueuePanel(true);
        usePlayerQueue().enqueue(track("a"));
        const wrapper = mountApp(PlayQueueToggle);

        await wrapper.find("button").trigger("click");

        expect(usePlayQueuePanel().isOpen.value).toBe(true);
        expect(iconNames(wrapper)).toStrictEqual(["close"]);
        expect(wrapper.find("button").attributes("aria-expanded")).toBe("true");
        expect(wrapper.find("button").attributes("aria-label")).toBe(translate("player.queue.hide"));
    });

    it("shuts the panel again on a second press", async () => {
        notePlayQueuePanel(true);
        usePlayerQueue().enqueue(track("a"));
        const wrapper = mountApp(PlayQueueToggle);

        await wrapper.find("button").trigger("click");
        await wrapper.find("button").trigger("click");

        expect(usePlayQueuePanel().isOpen.value).toBe(false);
    });

    it("does not let a consumer assign the flag, only ask it to change", () => {
        /*
         * `isOpen` is a computed on purpose: a panel that could be flipped by assignment
         * from anywhere is a panel whose state is hard to account for. Vue WARNS rather
         * than throws on a readonly computed, so what is asserted is that the write does
         * not land — the warning alone would leave a silently-ignored assignment passing.
         */
        const panel = usePlayQueuePanel();
        const warn = vi.spyOn(console, "warn").mockImplementation(() => {});

        (panel.isOpen as unknown as { value: boolean }).value = true;

        expect(panel.isOpen.value).toBe(false);
        expect(warn).toHaveBeenCalled();
        warn.mockRestore();
    });
});
