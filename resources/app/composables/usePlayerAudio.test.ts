import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { setupI18n } from "@/i18n";
import de from "@/lang/de.json";
import { resetPlayerAudioForTests, usePlayerAudio } from "Composables/usePlayerAudio";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { resetPlayerQueueForTests, usePlayerQueue } from "Composables/usePlayerQueue";
import { useToast } from "Composables/useToast";
import { resetInertia, setPage } from "Testing/inertia";

vi.mock("@inertiajs/vue3", () => import("Testing/inertia"));

/*
 * The player's STATE MACHINE — which is all a unit test can honestly claim to cover, and
 * the half where the bugs live.
 *
 * Whether sound comes out of the speakers needs a real browser and belongs to the
 * Playwright spec (tests/e2e/app/player.spec.ts). What is decided here, in plain
 * JavaScript, is what the element is asked to DO — and each of these decisions has a
 * failure mode that is invisible until somebody listens to a whole album:
 *
 *   - **The track boundary.** Browsers fire `pause` immediately before `ended`, so a
 *     play-state flag derived from `element.paused` reads "paused" exactly when the
 *     `ended` handler needs to know the listener still wants music — and playback stops
 *     dead after one track. The intent/state split is what these tests pin down.
 *   - **Repeat on a one-track queue.** `next()` reports success without moving the
 *     pointer, so nothing changes for the queue watcher to fire on, yet the track is
 *     meant to play again.
 *   - **The pointer moving for reasons that are not playback.** Removing a queue entry
 *     above the one playing shifts the index while the same song keeps playing; a
 *     watcher on the index rather than the track would restart it mid-listen.
 *   - **Duration from the queue, not the element.** A VBR MP3 with no Xing header
 *     reports `Infinity` until it is fully downloaded, and dividing a timeline by that
 *     draws nothing.
 *
 * happy-dom's <audio> is real enough for this: `play()` / `pause()` flip `paused` and
 * fire their events, `currentTime` is writable, and the media events can be dispatched
 * by hand. It has no decoder, so `buffered` is stubbed where a test is about the buffer
 * indicator — a real download is Playwright's business.
 */

/** A queue track with just enough shape to be identifiable in an assertion. */
const track = (id: string, duration: number | null = 100): QueueTrack => ({
    id,
    name: `Track ${id}`,
    artist: "Radiohead",
    album: "OK Computer",
    coverUrl: null,
    duration,
    href: `/music/songs/${id}`,
    streamUrl: `/music/songs/${id}/stream`
});

/** A fresh element attached to the singleton, as PlayerBar does on mount. */
const attachElement = (): HTMLAudioElement => {
    const element = document.createElement("audio");
    document.body.appendChild(element);
    usePlayerAudio().attach(element);

    return element;
};

/** What the element was actually pointed at — `src` reads back absolute, the attribute does not. */
const loadedUrl = (element: HTMLAudioElement): string | null => element.getAttribute("src");

/**
 * Pretend the browser has downloaded `ranges` of the track.
 *
 * happy-dom has no decoder, so `buffered` is always empty; overriding it on the
 * instance is the smallest possible stand-in for a real download, and it is only ever
 * used to prove the ranges are READ and reshaped correctly.
 */
const stubBuffered = (element: HTMLAudioElement, ranges: Array<[number, number]>): void => {
    Object.defineProperty(element, "buffered", {
        configurable: true,
        value: {
            length: ranges.length,
            start: (index: number) => ranges[index][0],
            end: (index: number) => ranges[index][1]
        }
    });
};

/**
 * Play a track to its end, the way a browser does it: `pause` first, then `ended`.
 *
 * The ORDER is the point. Dispatching `ended` alone would let a broken implementation
 * pass, because the trap being guarded is the `pause` that arrives just before it.
 */
const finishTrack = (element: HTMLAudioElement): void => {
    Object.defineProperty(element, "ended", { configurable: true, value: true });
    element.dispatchEvent(new Event("pause"));
    element.dispatchEvent(new Event("ended"));
    Object.defineProperty(element, "ended", { configurable: true, value: false });
};

/** The toasts on screen — a failed stream is announced through the singleton. */
const toasts = () => useToast().activeToasts.value;

/** Empty the toast singleton, which is module state and outlives a test. */
const drainToasts = (): void => {
    const { activeToasts, removeToast } = useToast();
    while (activeToasts.value.length > 0) activeToasts.value.forEach(toast => removeToast(toast.id));
};

describe("usePlayerAudio", () => {
    beforeEach(() => {
        resetInertia();
        // A failed stream is announced through the i18n SINGLETON (the reporter runs inside a
        // media event handler, where useI18n() is unavailable), so it has to exist here.
        setupI18n({ legacy: false, locale: "de", messages: { de } });
        setPage({ props: { auth: { user: { id: "user-1", name: "Ash", email: "a@b.c" } } } });
        resetPlayerAudioForTests();
        resetPlayerQueueForTests();
        drainToasts();
        window.localStorage.clear();
    });

    describe("loading a track", () => {
        it("points the element at the loaded track's stream", async () => {
            usePlayerQueue().enqueue(track("a"));
            const element = attachElement();

            expect(loadedUrl(element)).toBe("/music/songs/a/stream");
        });

        it("does not start playing a queue restored from storage", () => {
            // Page load is not a user gesture. Loading the src is what makes the first
            // press instant; playing it would be a browser-blocked autoplay at best.
            usePlayerQueue().enqueue(track("a"));
            const element = attachElement();

            expect(usePlayerAudio().isPlaying.value).toBe(false);
            expect(element.paused).toBe(true);
        });

        it("plays a track that was queued and pressed BEFORE the element existed", async () => {
            /*
             * The bug behind "Play this artist" filling the queue and then sitting paused
             * (2026-08-06). The bar renders the element and only exists while the queue holds
             * a track, so a press that FILLS an empty queue calls `play()` a tick before there
             * is anything to play on: `playNow()` is synchronous, mounting is not.
             *
             * `play()` therefore keeps the INTENT when it finds no element, and `attach()`
             * honours it — the alternative (which shipped) was dropping the request silently
             * and leaving the reader looking at a full queue and a play glyph.
             */
            usePlayerQueue().playNow([track("a")]);
            usePlayerAudio().play();

            // Still nothing to play on — the element arrives with the bar, a tick later.
            expect(usePlayerAudio().isPlaying.value).toBe(true);

            const element = attachElement();
            await nextTick();

            expect(loadedUrl(element)).toBe("/music/songs/a/stream");
            expect(element.paused).toBe(false);
        });

        it("follows the queue onto a track chosen in the panel", async () => {
            usePlayerQueue().enqueue([track("a"), track("b")]);
            const element = attachElement();

            usePlayerQueue().jumpTo(1);
            await nextTick();

            expect(loadedUrl(element)).toBe("/music/songs/b/stream");
        });

        it("keeps playing across a track change once it was playing", async () => {
            usePlayerQueue().enqueue([track("a"), track("b")]);
            const element = attachElement();
            usePlayerAudio().play();

            usePlayerQueue().jumpTo(1);
            await nextTick();

            expect(loadedUrl(element)).toBe("/music/songs/b/stream");
            expect(usePlayerAudio().isPlaying.value).toBe(true);
        });

        it("stays paused across a track change when it was paused", async () => {
            usePlayerQueue().enqueue([track("a"), track("b")]);
            attachElement();

            usePlayerQueue().jumpTo(1);
            await nextTick();

            expect(usePlayerAudio().isPlaying.value).toBe(false);
        });

        it("does not restart the song when a queue entry above it is removed", async () => {
            // The reason the watcher keys on the TRACK and not the index: removing
            // something earlier in the queue shifts the pointer while the same song
            // keeps playing, and a reload here would be audible as a skip back to 0:00.
            usePlayerQueue().enqueue([track("a"), track("b"), track("c")]);
            const element = attachElement();
            usePlayerQueue().jumpTo(2);
            await nextTick();

            const player = usePlayerAudio();
            player.play();
            element.currentTime = 42;
            element.dispatchEvent(new Event("timeupdate"));

            usePlayerQueue().remove(0);
            await nextTick();

            expect(usePlayerQueue().currentIndex.value).toBe(1);
            expect(element.currentTime).toBe(42);
            expect(player.currentTime.value).toBe(42);
        });

        it("goes silent and lets go of the file when the queue is emptied", async () => {
            usePlayerQueue().enqueue(track("a"));
            const element = attachElement();
            usePlayerAudio().play();

            usePlayerQueue().clear();
            await nextTick();

            // An empty `src` would resolve against the document and set the browser
            // downloading the PAGE as audio, so the attribute goes away entirely.
            expect(loadedUrl(element)).toBeNull();
            expect(element.paused).toBe(true);
            expect(usePlayerAudio().isPlaying.value).toBe(false);
        });

        it("loads on the first press when the bar mounted before the queue had anything", () => {
            const element = attachElement();
            usePlayerQueue().enqueue(track("a"));

            usePlayerAudio().play();

            expect(loadedUrl(element)).toBe("/music/songs/a/stream");
            expect(usePlayerAudio().isPlaying.value).toBe(true);
        });
    });

    describe("play, pause and the intent behind them", () => {
        it("toggles both ways", () => {
            usePlayerQueue().enqueue(track("a"));
            const element = attachElement();
            const player = usePlayerAudio();

            player.toggle();
            expect(player.isPlaying.value).toBe(true);
            expect(element.paused).toBe(false);

            player.toggle();
            expect(player.isPlaying.value).toBe(false);
            expect(element.paused).toBe(true);
        });

        it("gives up the intent when the browser refuses to play", async () => {
            // Autoplay policy, or an element with no usable source: `play()` rejects.
            // Without this the button would offer to pause a silent element.
            usePlayerQueue().enqueue(track("a"));
            const element = attachElement();
            vi.spyOn(element, "play").mockRejectedValue(new DOMException("blocked"));

            usePlayerAudio().play();
            await Promise.resolve();
            await Promise.resolve();

            expect(usePlayerAudio().isPlaying.value).toBe(false);
        });

        it("shows a pause that came from outside the app", () => {
            // The OS transport, or headphones being unplugged. A real pause, and the
            // glyph has to say so.
            usePlayerQueue().enqueue(track("a"));
            const element = attachElement();
            const player = usePlayerAudio();
            player.play();

            element.dispatchEvent(new Event("pause"));

            expect(player.isPlaying.value).toBe(false);
        });

        it("stops when the stream fails, rather than sitting on pause over silence", () => {
            usePlayerQueue().enqueue(track("a"));
            const element = attachElement();
            const player = usePlayerAudio();
            player.play();

            element.dispatchEvent(new Event("error"));

            expect(player.isPlaying.value).toBe(false);
        });

        it("tells the listener which track failed, because a stopped player looks paused", () => {
            // The wiring, not the wording: that the element's error and the track it was
            // loading both reach the reporter. What the message says, and the say-it-once
            // rule, are Utils/playbackError's own spec.
            usePlayerQueue().enqueue(track("a"));
            const element = attachElement();
            usePlayerAudio().play();

            Object.defineProperty(element, "error", { configurable: true, value: { code: 4 } });
            element.dispatchEvent(new Event("error"));

            expect(toasts()).toHaveLength(1);
            expect(toasts()[0]).toMatchObject({ type: "error" });
            expect(toasts()[0].message).toContain("Track a");
        });

        it("answers a press on a dead track, instead of failing silently a second time", async () => {
            /*
             * The element keeps ONE MediaError while a dead source is loaded, so the second
             * press produces no `error` event at all — only a rejected `play()`. Without the
             * announcement on that path, pressing play on a broken track would do exactly
             * nothing visible, which is the failure this feature exists to remove.
             */
            usePlayerQueue().enqueue(track("a"));
            const element = attachElement();
            const failure = { code: 4 };
            Object.defineProperty(element, "error", { configurable: true, value: failure });
            element.dispatchEvent(new Event("error"));
            drainToasts();

            // happy-dom's play() resolves, so the refusal a dead source produces is stubbed.
            element.play = () => Promise.reject(new Error("NotSupportedError"));
            usePlayerAudio().play();
            await nextTick();

            expect(toasts()).toHaveLength(1);
        });

        it("says nothing when the browser refuses playback for its own reasons", async () => {
            // An autoplay-policy refusal leaves no MediaError behind, and nothing is broken:
            // the next press works. A toast about it would be noise on an ordinary page load.
            usePlayerQueue().enqueue(track("a"));
            const element = attachElement();
            element.play = () => Promise.reject(new Error("NotAllowedError"));

            usePlayerAudio().play();
            await nextTick();

            expect(toasts()).toHaveLength(0);
        });
    });

    describe("advancing at the end of a track", () => {
        it("moves to the next track and keeps playing", () => {
            usePlayerQueue().enqueue([track("a"), track("b")]);
            const element = attachElement();
            const player = usePlayerAudio();
            player.play();

            finishTrack(element);

            expect(usePlayerQueue().current.value?.id).toBe("b");
            // The trap: the `pause` that arrives just before `ended` must NOT be read as
            // the listener wanting silence, or the queue stops after one track.
            expect(player.isPlaying.value).toBe(true);
        });

        it("stops at the end of the queue with repeat off", () => {
            usePlayerQueue().enqueue([track("a")]);
            const element = attachElement();
            const player = usePlayerAudio();
            player.play();

            finishTrack(element);

            expect(player.isPlaying.value).toBe(false);
            expect(usePlayerQueue().currentIndex.value).toBe(0);
        });

        it("wraps to the first track with repeat on", async () => {
            usePlayerQueue().enqueue([track("a"), track("b")]);
            const element = attachElement();
            const player = usePlayerAudio();
            usePlayerQueue().jumpTo(1);
            usePlayerQueue().toggleRepeat();
            await nextTick();
            player.play();

            finishTrack(element);
            await nextTick();

            expect(usePlayerQueue().currentIndex.value).toBe(0);
            expect(loadedUrl(element)).toBe("/music/songs/a/stream");
            expect(player.isPlaying.value).toBe(true);
        });

        it("replays a one-track queue on repeat, which no watcher would notice", () => {
            // `next()` reports success without the pointer moving, so `current` never
            // changes and the queue watcher never fires. The player has to spot that the
            // index stood still and reload the track itself.
            usePlayerQueue().enqueue([track("a")]);
            const element = attachElement();
            const player = usePlayerAudio();
            usePlayerQueue().toggleRepeat();
            player.play();
            element.currentTime = 99;

            finishTrack(element);

            expect(player.isPlaying.value).toBe(true);
            expect(element.currentTime).toBe(0);
        });

        it("advances only once per boundary, however often the bar remounts", () => {
            // Two attaches would leave two sets of listeners on one element and skip a
            // track at every boundary; `attach()` detaches first for exactly this.
            usePlayerQueue().enqueue([track("a"), track("b"), track("c")]);
            const element = attachElement();
            usePlayerAudio().attach(element);
            usePlayerAudio().play();

            finishTrack(element);

            expect(usePlayerQueue().current.value?.id).toBe("b");
        });
    });

    describe("the readings the timeline draws", () => {
        it("takes the duration from the queue track, not the element", () => {
            // The element would report Infinity for a VBR file with no Xing header until
            // the whole thing is downloaded; getID3 measured it at scan time instead.
            usePlayerQueue().enqueue(track("a", 383));
            const element = attachElement();
            Object.defineProperty(element, "duration", { configurable: true, value: Infinity });
            element.dispatchEvent(new Event("durationchange"));

            expect(usePlayerAudio().duration.value).toBe(383);
        });

        it("falls back to the element for a file that carried no duration", () => {
            usePlayerQueue().enqueue(track("a", null));
            const element = attachElement();
            Object.defineProperty(element, "duration", { configurable: true, value: 61.5 });
            element.dispatchEvent(new Event("durationchange"));

            expect(usePlayerAudio().duration.value).toBe(61.5);
        });

        it("reports 0 rather than NaN when neither knows the duration", () => {
            // It is a divisor: a NaN here makes every width in the timeline "NaN%".
            usePlayerQueue().enqueue(track("a", null));
            const element = attachElement();
            Object.defineProperty(element, "duration", { configurable: true, value: NaN });
            element.dispatchEvent(new Event("durationchange"));

            expect(usePlayerAudio().duration.value).toBe(0);
        });

        it("reads the cursor off the element rather than counting it up", () => {
            usePlayerQueue().enqueue(track("a"));
            const element = attachElement();

            element.currentTime = 12.5;
            element.dispatchEvent(new Event("timeupdate"));

            expect(usePlayerAudio().currentTime.value).toBe(12.5);
        });

        it("re-syncs the cursor when the tab comes back into view", () => {
            // `timeupdate` is throttled in a hidden tab, so the last reading can be a
            // minute stale — the audio kept playing, the number did not keep up.
            usePlayerQueue().enqueue(track("a"));
            const element = attachElement();
            element.currentTime = 5;
            element.dispatchEvent(new Event("timeupdate"));

            element.currentTime = 65;
            document.dispatchEvent(new Event("visibilitychange"));

            expect(usePlayerAudio().currentTime.value).toBe(65);
        });

        it("resets the cursor at a track change, so the bar never shows the last track's position", async () => {
            usePlayerQueue().enqueue([track("a"), track("b")]);
            const element = attachElement();
            element.currentTime = 90;
            element.dispatchEvent(new Event("timeupdate"));

            usePlayerQueue().jumpTo(1);
            await nextTick();

            expect(usePlayerAudio().currentTime.value).toBe(0);
        });

        it("reshapes the browser's buffered ranges into what the indicator draws", () => {
            usePlayerQueue().enqueue(track("a"));
            const element = attachElement();
            // Two ranges is the shape after a seek past the end of the buffer: what was
            // already downloaded, plus what is arriving at the new position.
            stubBuffered(element, [
                [0, 30],
                [120, 155]
            ]);

            element.dispatchEvent(new Event("progress"));

            expect(usePlayerAudio().buffered.value).toStrictEqual([
                { start: 0, end: 30 },
                { start: 120, end: 155 }
            ]);
        });

        it("keeps filling the buffer while paused, which is when scrubbing is decided", () => {
            usePlayerQueue().enqueue(track("a"));
            const element = attachElement();
            stubBuffered(element, [[0, 44]]);

            element.dispatchEvent(new Event("progress"));

            expect(usePlayerAudio().buffered.value).toStrictEqual([{ start: 0, end: 44 }]);
        });

        it("credits a new track with none of the last one's buffer", async () => {
            usePlayerQueue().enqueue([track("a"), track("b")]);
            const element = attachElement();
            stubBuffered(element, [[0, 100]]);
            element.dispatchEvent(new Event("progress"));

            usePlayerQueue().jumpTo(1);
            await nextTick();

            expect(usePlayerAudio().buffered.value).toStrictEqual([]);
        });
    });

    describe("seeking", () => {
        it("writes the position onto the element and shows it at once", () => {
            // Both halves: waiting for `seeked` would snap the timeline back to where the
            // drag started for a frame or two, which reads as a seek that failed.
            usePlayerQueue().enqueue(track("a", 200));
            const element = attachElement();

            usePlayerAudio().seek(75);

            expect(element.currentTime).toBe(75);
            expect(usePlayerAudio().currentTime.value).toBe(75);
        });

        it("clamps a seek past the end of the track", () => {
            // A click on the very last pixel of the timeline rounds past the duration, and
            // assigning that throws in some engines.
            usePlayerQueue().enqueue(track("a", 200));
            const element = attachElement();

            usePlayerAudio().seek(500);

            expect(element.currentTime).toBe(200);
        });

        it("clamps a negative seek", () => {
            usePlayerQueue().enqueue(track("a", 200));
            const element = attachElement();

            usePlayerAudio().seek(-30);

            expect(element.currentTime).toBe(0);
        });
    });

    describe("letting go of the element", () => {
        it("stops reacting to it once detached", () => {
            usePlayerQueue().enqueue([track("a"), track("b")]);
            const element = attachElement();
            const player = usePlayerAudio();
            player.play();

            player.detach();
            finishTrack(element);

            // The queue must not walk on after the bar has gone.
            expect(usePlayerQueue().current.value?.id).toBe("a");
            expect(player.isPlaying.value).toBe(false);
        });
    });
});
