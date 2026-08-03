import { beforeEach, describe, expect, it, vi } from "vitest";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetPlayQueuePanelForTests, usePlayQueuePanel } from "Composables/usePlayQueuePanel";
import { resetInertia } from "Testing/inertia";
import { iconNames, mountApp, translate } from "Testing/mount";
import PlayQueueToggle from "./PlayQueueToggle.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The header's narrow-screen control for the queue panel. Which WIDTH it appears at is a
 * media query and belongs to Playwright; what is left here is the part CSS cannot express
 * — that the button is absent entirely when there is no queue to show, and that its glyph
 * and its announced state stay in step with the panel.
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
        usePlayerQueue().enqueue(track("a"));

        expect(mountApp(PlayQueueToggle).find("button").exists()).toBe(true);
    });

    it("offers the queue glyph while the panel is shut", () => {
        usePlayerQueue().enqueue(track("a"));
        const wrapper = mountApp(PlayQueueToggle);

        expect(iconNames(wrapper)).toStrictEqual(["playlist"]);
        expect(wrapper.find("button").attributes("aria-expanded")).toBe("false");
        expect(wrapper.find("button").attributes("aria-label")).toBe(translate("player.queue.show"));
    });

    it("becomes a close button once the panel is open", async () => {
        usePlayerQueue().enqueue(track("a"));
        const wrapper = mountApp(PlayQueueToggle);

        await wrapper.find("button").trigger("click");

        expect(usePlayQueuePanel().isOpen.value).toBe(true);
        expect(iconNames(wrapper)).toStrictEqual(["close"]);
        expect(wrapper.find("button").attributes("aria-expanded")).toBe("true");
        expect(wrapper.find("button").attributes("aria-label")).toBe(translate("player.queue.hide"));
    });

    it("shuts the panel again on a second press", async () => {
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
