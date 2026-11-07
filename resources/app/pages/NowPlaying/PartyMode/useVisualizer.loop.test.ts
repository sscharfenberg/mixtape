import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ref } from "vue";
import { resetAudioAnalyserForTests } from "Composables/useAudioAnalyser";
import { useVisualizer } from "./useVisualizer";
import type * as Visualizers from "./visualizers";
import type { VisualizerEntry, VisualizerPlugin } from "./visualizers";
import { RANDOM, RANDOM_INTERVAL_SECONDS } from "./visualizers";

/*
 * The host loop against stand-in plugins — what happens BETWEEN plugins, which no single plugin
 * can show and a browser test would need several real visualisers and minutes of waiting to reach:
 *
 *   - THE CLOCK RUNS ON ACROSS A SWITCH. The analysis remembers the last time it was called; a clock
 *     that restarted at zero for the next plugin froze every level and the beat for as long as the
 *     party had already run.
 *   - RANDOM MODE MOVES ON when its interval is up, and not before; a NAMED CHOICE HOLDS however
 *     long the party runs.
 *   - THE CHOICE IS REMEMBERED, so the next party opens the way this one was left.
 *   - A PLUGIN THAT FAILS TO LOAD leaves a party without a picture, not one stuck on "loading".
 *
 * The registry's list is replaced per test (`entries`); everything else in the module is the real
 * one. The animation frame is driven by hand, and there is no audio element, so every frame is an
 * idle one — which is all the clock needs.
 */

const entries = vi.hoisted(() => [] as VisualizerEntry[]);
vi.mock("./visualizers", async importOriginal => ({
    ...(await importOriginal<typeof Visualizers>()),
    VISUALIZERS: entries
}));

/** Where useVisualizer remembers the choice. */
const STORAGE_KEY = "mixtape.party.visualizer";

/** A plugin whose renderer records every `seconds` it is drawn at into `log`. */
const recording = (log: number[]): VisualizerPlugin => ({
    create: () => ({
        resize: () => undefined,
        render: (_frame, seconds) => {
            log.push(seconds);
        },
        dispose: () => undefined
    })
});

/** A registry entry around a recording plugin. */
const entry = (id: string, log: number[] = []): VisualizerEntry => ({
    id,
    load: () => Promise.resolve({ default: recording(log) })
});

describe("useVisualizer's loop", () => {
    let frames: FrameRequestCallback[];
    let now: number;

    /** Run the most recently requested animation frame at `at` ms. */
    const frame = (at: number): void => {
        now = at;
        const callback = frames.pop();
        frames = [];
        callback?.(at);
    };

    beforeEach(() => {
        entries.length = 0;
        frames = [];
        now = 1000;
        window.localStorage.clear();
        vi.spyOn(performance, "now").mockImplementation(() => now);
        vi.stubGlobal("requestAnimationFrame", (callback: FrameRequestCallback) => frames.push(callback));
        vi.stubGlobal("cancelAnimationFrame", () => undefined);
        vi.stubGlobal("matchMedia", (query: string) => ({ matches: query.includes("no-preference") }));
    });

    afterEach(() => {
        resetAudioAnalyserForTests();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    /** A visualiser over a fresh canvas and root, playing nothing. */
    const visualizer = () =>
        useVisualizer({
            canvas: ref(document.createElement("canvas")),
            root: ref(document.createElement("div")),
            isPlaying: ref(false)
        });

    it("keeps one clock for the whole party, so the next plugin starts where the last one was", async () => {
        const first: number[] = [];
        const second: number[] = [];
        entries.push(entry("one", first), entry("two", second));
        window.localStorage.setItem(STORAGE_KEY, "one");

        const { start, select, status, stop } = visualizer();
        await start();
        expect(status.value).toBe("running");

        // Ten minutes into the party.
        frame(1000 + 600_000);
        expect(first[first.length - 1]).toBeCloseTo(600, 3);

        now = 1000 + 600_010;
        await select("two");
        frame(1000 + 600_016);

        expect(second[0]).toBeGreaterThan(600);
        stop();
    });

    it("opens on random, and moves to ANOTHER visualiser once the interval is up — not before", async () => {
        entries.push(entry("one"), entry("two"), entry("three"));

        const { start, choice, active, stop } = visualizer();
        await start();
        expect(choice.value).toBe(RANDOM);
        const first = active.value;

        frame(1000 + (RANDOM_INTERVAL_SECONDS - 1) * 1000);
        expect(active.value).toBe(first);

        frame(1000 + (RANDOM_INTERVAL_SECONDS + 1) * 1000);
        await vi.waitFor(() => expect(active.value).not.toBe(first));
        stop();
    });

    it("holds a visualiser chosen by name, however long the party runs", async () => {
        entries.push(entry("one"), entry("two"), entry("three"));

        const { start, select, active, stop } = visualizer();
        await start();
        await select("three");
        expect(active.value).toBe(2);

        frame(1000 + RANDOM_INTERVAL_SECONDS * 3 * 1000);
        await Promise.resolve();
        expect(active.value).toBe(2);
        stop();
    });

    it("remembers the choice, so the next party opens on it", async () => {
        entries.push(entry("one"), entry("two"));

        const first = visualizer();
        await first.start();
        await first.select("two");
        first.stop();

        const next = visualizer();
        expect(next.choice.value).toBe("two");
        await next.start();
        expect(next.active.value).toBe(1);
        next.stop();
    });

    it("opens on random when the remembered visualiser no longer exists", () => {
        entries.push(entry("one"));
        window.localStorage.setItem(STORAGE_KEY, "retired");

        expect(visualizer().choice.value).toBe(RANDOM);
    });

    it("runs a party without a picture when the plugin's chunk will not load", async () => {
        vi.spyOn(console, "error").mockImplementation(() => undefined);
        entries.push({ id: "gone", load: () => Promise.reject(new Error("chunk missing")) });

        const { start, status, stop } = visualizer();
        await start();

        expect(status.value).toBe("unsupported");
        stop();
    });
});
