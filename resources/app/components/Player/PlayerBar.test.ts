import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { resetPlayerAudioForTests, usePlayerAudio } from "Composables/usePlayerAudio";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { resetInertia, setPage } from "Testing/inertia";
import { iconNames, mountApp, translate } from "Testing/mount";
import PlayerBar from "./PlayerBar.vue";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The bar is a thin view over two composables that have their own specs, so what is
 * left here is the part neither of them can see: what the transport SAYS.
 *
 * The play button is the one that matters. It is a single button whose glyph and
 * announced label both flip with the play state — one button rather than two swapped
 * in and out, so it cannot move under a finger about to press it again — and a
 * mismatch between the two is invisible to a sighted developer and total for anyone
 * using a screen reader.
 *
 * Skip-forward is the other: it is disabled at the end of the queue, EXCEPT with
 * repeat on, where the last track's "next" is the first one. Get that wrong and the
 * queue wraps by itself at a track boundary while the button sits there greyed out.
 *
 * What is NOT here: the bar's two layouts (a grid that moves the timeline into the row
 * at `landscape`) and the height it publishes as `--app-player-height`. Both are CSS
 * and box metrics, which happy-dom does not have — they live in the Playwright specs.
 */

/** A queue track with just enough shape to be identifiable in the DOM. */
const track = (id: string, artist: string | null = "Radiohead"): QueueTrack => ({
    id,
    name: `Track ${id}`,
    artist,
    album: "OK Computer",
    coverUrl: null,
    duration: 200,
    href: `/music/songs/${id}`,
    streamUrl: `/music/songs/${id}/stream`
});

/** Fill the queue, then mount the bar over it. */
const bar = async (tracks: QueueTrack[]) => {
    if (tracks.length) usePlayerQueue().enqueue(tracks);
    const wrapper = mountApp(PlayerBar);
    await nextTick();

    return wrapper;
};

/** The transport's three controls, in DOM order: previous, play/pause, next. */
const controls = (wrapper: Awaited<ReturnType<typeof bar>>) => wrapper.findAll(".player-bar__control");

describe("PlayerBar", () => {
    beforeEach(() => {
        resetInertia();
        setPage({ props: { auth: { user: { id: "user-1", name: "Ash", email: "a@b.c" } } } });
        resetPlayerAudioForTests();
        resetPlayerQueueForTests();
        window.localStorage.clear();
    });

    it("renders nothing while the queue has no loaded track", async () => {
        const wrapper = await bar([]);

        expect(wrapper.find(".player-bar").exists()).toBe(false);
    });

    it("owns the one element in the app that makes sound", async () => {
        // In the template rather than a bare `new Audio()`: a real DOM element is what
        // iOS treats as a first-class media element, and it is mounted here — once, in
        // the layout — so an Inertia page swap cannot stop the music.
        const wrapper = await bar([track("a")]);

        expect(wrapper.findAll("audio")).toHaveLength(1);
        expect(wrapper.find("audio").attributes("src")).toBe("/music/songs/a/stream");
    });

    it("shows what is playing, and links it to its own page", async () => {
        const wrapper = await bar([track("a")]);

        expect(wrapper.find(".player-bar__name").text()).toBe("Track a");
        expect(wrapper.find(".player-bar__name").attributes("href")).toBe("/music/songs/a");
        expect(wrapper.find(".player-bar__artist").text()).toBe("Radiohead");
    });

    it("leaves out the artist line for a track whose file carried none", async () => {
        const wrapper = await bar([track("a", null)]);

        expect(wrapper.find(".player-bar__artist").exists()).toBe(false);
    });

    describe("the play button", () => {
        it("offers to play, in words and in glyph, while paused", async () => {
            const wrapper = await bar([track("a")]);
            const play = controls(wrapper)[1];

            expect(play.attributes("aria-label")).toBe(translate("player.bar.play"));
            expect(iconNames(play)).toStrictEqual(["play"]);
        });

        it("offers to pause once it is playing", async () => {
            const wrapper = await bar([track("a")]);

            await controls(wrapper)[1].trigger("click");

            const play = controls(wrapper)[1];
            expect(usePlayerAudio().isPlaying.value).toBe(true);
            // Both halves, together: a glyph that flipped without the label is a button
            // that lies to a screen reader.
            expect(play.attributes("aria-label")).toBe(translate("player.bar.pause"));
            expect(iconNames(play)).toStrictEqual(["pause"]);
        });

        it("goes back to offering play when pressed again", async () => {
            const wrapper = await bar([track("a")]);

            await controls(wrapper)[1].trigger("click");
            await controls(wrapper)[1].trigger("click");

            expect(usePlayerAudio().isPlaying.value).toBe(false);
            expect(iconNames(controls(wrapper)[1])).toStrictEqual(["play"]);
        });

        it("is never disabled, unlike the two skip controls", async () => {
            // A one-track queue can go neither forward nor back, but it can always play.
            const wrapper = await bar([track("a")]);

            expect(controls(wrapper)[1].attributes("disabled")).toBeUndefined();
        });
    });

    describe("stepping through the queue", () => {
        it("cannot step back from the first track", async () => {
            const wrapper = await bar([track("a"), track("b")]);

            expect(controls(wrapper)[0].attributes("disabled")).toBeDefined();
        });

        it("steps back once there is something behind it", async () => {
            const wrapper = await bar([track("a"), track("b")]);
            usePlayerQueue().jumpTo(1);
            await nextTick();

            await controls(wrapper)[0].trigger("click");

            expect(usePlayerQueue().current.value?.id).toBe("a");
        });

        it("cannot step forward from the last track", async () => {
            const wrapper = await bar([track("a"), track("b")]);
            usePlayerQueue().jumpTo(1);
            await nextTick();

            expect(controls(wrapper)[2].attributes("disabled")).toBeDefined();
        });

        it("can always step forward with repeat on", async () => {
            // The last track's "next" is the first one. A disabled control here would
            // contradict what the queue does by itself at the end of the track.
            const wrapper = await bar([track("a"), track("b")]);
            usePlayerQueue().jumpTo(1);
            usePlayerQueue().toggleRepeat();
            await nextTick();

            expect(controls(wrapper)[2].attributes("disabled")).toBeUndefined();

            await controls(wrapper)[2].trigger("click");

            expect(usePlayerQueue().current.value?.id).toBe("a");
        });
    });

    describe("the timeline", () => {
        it("draws the total from the queue's own duration, before any audio loads", async () => {
            // getID3 measured every file at scan time, so the total is right from the
            // first frame — an element would report Infinity for a VBR file until it is
            // fully downloaded.
            const wrapper = await bar([track("a")]);

            expect(wrapper.findAll(".player-timeline__time")[1].text()).toBe("3:20");
        });

        it("seeks the element when the rail is scrubbed", async () => {
            const wrapper = await bar([track("a")]);
            const input = wrapper.find(".player-timeline__input");

            (input.element as HTMLInputElement).value = "50";
            await input.trigger("input");
            await input.trigger("change");

            expect(usePlayerAudio().currentTime.value).toBe(50);
            expect((wrapper.find("audio").element as HTMLAudioElement).currentTime).toBe(50);
        });
    });
});
