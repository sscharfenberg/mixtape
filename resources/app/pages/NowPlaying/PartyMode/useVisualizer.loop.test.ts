import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ref } from "vue";
import { resetAudioAnalyserForTests } from "Composables/useAudioAnalyser";
import { useVisualizer } from "./useVisualizer";
import type { VisualizerEntry, VisualizerPlugin } from "./visualizers";

/*
 * The host loop against stand-in plugins — what happens BETWEEN plugins, which no single plugin
 * can show and a browser test would need two real visualisers to reach:
 *
 *   - THE CLOCK RUNS ON ACROSS A SWITCH. The analysis remembers the last time it was called; a clock
 *     that restarted at zero for the next plugin froze every level and the beat for as long as the
 *     party had already run.
 *   - A PLUGIN THAT FAILS TO LOAD leaves a party without a picture, not one stuck on "loading".
 *
 * The registry is replaced per test (`entries`), the animation frame is driven by hand, and there
 * is no audio element, so every frame is an idle one — which is all the clock needs.
 */

const entries = vi.hoisted(() => [] as VisualizerEntry[]);
vi.mock("./visualizers", () => ({ VISUALIZERS: entries }));

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
        entries.push(
            { id: "one", load: () => Promise.resolve({ default: recording(first) }) },
            { id: "two", load: () => Promise.resolve({ default: recording(second) }) }
        );

        const { start, cycle, status, stop } = visualizer();
        await start();
        expect(status.value).toBe("running");

        // Ten minutes into the party.
        frame(1000 + 600_000);
        expect(first[first.length - 1]).toBeCloseTo(600, 3);

        now = 1000 + 600_010;
        await cycle();
        frame(1000 + 600_016);

        expect(second[0]).toBeGreaterThan(600);
        stop();
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
