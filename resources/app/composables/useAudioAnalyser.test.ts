import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
    bindAnalyserElement,
    resetAudioAnalyserForTests,
    useAudioAnalyser
} from "Composables/useAudioAnalyser";

/*
 * The analyser owns the one IRREVERSIBLE call in the app: `createMediaElementSource` may be
 * made once per element, and a second one throws rather than being ignored — after which the
 * element's audio has already been re-routed, so there is no recovering by retrying.
 *
 * That makes the concurrency the thing worth pinning here. Everything else about this module is
 * either a browser fact happy-dom cannot supply (real frequency data) or already covered by
 * Visualizer's own spec, which deliberately runs with NO AudioContext to prove the graceful
 * path. This file supplies a fake one, so the branch behind that guard can be reached at all.
 */

/** The `AudioContext` a browser would supply, resuming on a tick rather than instantly. */
class FakeAudioContext {
    public state: "suspended" | "running" = "suspended";

    public readonly destination = {};

    public constructor(public readonly created: { sources: number }) {}

    /**
     * Resume on a later microtask, which is what makes the race reproducible: the real thing
     * is async too, and it is the window between entering `route()` and this resolving that
     * two callers can both be inside.
     */
    public async resume(): Promise<void> {
        await Promise.resolve();
        this.state = "running";
    }

    /** Counted, because being called twice for one element is the defect under test. */
    public createMediaElementSource(): { connect: () => void } {
        this.created.sources += 1;

        return { connect: () => undefined };
    }

    public createAnalyser(): Record<string, unknown> {
        return {
            fftSize: 0,
            frequencyBinCount: 32,
            connect: () => undefined,
            getByteFrequencyData: () => undefined
        };
    }

    public async close(): Promise<void> {
        // Nothing to release.
    }
}

/** How many source nodes the fake context has been asked for since the last reset. */
let created: { sources: number };

describe("useAudioAnalyser", () => {
    beforeEach(() => {
        created = { sources: 0 };
        vi.stubGlobal(
            "AudioContext",
            class extends FakeAudioContext {
                public constructor() {
                    super(created);
                }
            }
        );
        vi.stubGlobal("requestAnimationFrame", () => 1);
        vi.stubGlobal("cancelAnimationFrame", () => undefined);
    });

    afterEach(() => {
        resetAudioAnalyserForTests();
        vi.unstubAllGlobals();
    });

    it("routes one element exactly once, however many callers ask at the same time", async () => {
        /*
         * BOTH CALLERS ARE REAL: Visualizer activates on mount for a page entered mid-track and
         * again the moment playback starts, and on a fresh load nothing has been clicked yet, so
         * the context is suspended and the resume is genuinely awaited in between. Guarding on
         * "which element is routed" alone cannot catch this — that is only recorded AFTER the
         * await, so both callers pass the guard on the way in.
         */
        const element = document.createElement("audio");
        bindAnalyserElement(element);

        const first = useAudioAnalyser();
        const second = useAudioAnalyser();
        first.activate();
        second.activate();

        await vi.waitFor(() => expect(created.sources).toBeGreaterThan(0));

        expect(created.sources).toBe(1);
    });

    it("does not route the same element again once it already is", async () => {
        const element = document.createElement("audio");
        bindAnalyserElement(element);

        const analyser = useAudioAnalyser();
        analyser.activate();
        await vi.waitFor(() => expect(created.sources).toBe(1));

        analyser.deactivate();
        analyser.activate();
        await Promise.resolve();

        expect(created.sources).toBe(1);
    });

    it("routes a NEW element, because a graph cannot be moved between them", async () => {
        const first = document.createElement("audio");
        bindAnalyserElement(first);
        useAudioAnalyser().activate();
        await vi.waitFor(() => expect(created.sources).toBe(1));

        // A fresh <audio> needs a fresh source node; the old analyser goes with the old element.
        bindAnalyserElement(document.createElement("audio"));

        await vi.waitFor(() => expect(created.sources).toBe(2));
    });
});
