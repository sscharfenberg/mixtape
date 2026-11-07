import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
    bindAnalyserElement,
    openAudioTap,
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

/** A node in the fake graph, recording what it was connected to and disconnected from. */
type FakeNode = {
    connected: unknown[];
    disconnected: unknown[];
    connect: (to: unknown) => void;
    disconnect: (from?: unknown) => void;
};

/** An analyser in the fake graph: a node with settings, filling arrays with marker bytes. */
type FakeAnalyser = FakeNode & {
    fftSize: number;
    smoothingTimeConstant: number;
    minDecibels: number;
    maxDecibels: number;
    frequencyBinCount: number;
    getByteFrequencyData: (array: Uint8Array) => void;
    getByteTimeDomainData: (array: Uint8Array) => void;
};

/** What the fake context has built since the last reset — counted, and kept for inspection. */
type Created = { sources: number; sourceNodes: FakeNode[]; analysers: FakeAnalyser[] };

/** A fresh node that records its own connections. */
const fakeNode = (): FakeNode => {
    const node: FakeNode = {
        connected: [],
        disconnected: [],
        connect: to => {
            node.connected.push(to);
        },
        disconnect: from => {
            node.disconnected.push(from);
        }
    };

    return node;
};

/** The `AudioContext` a browser would supply, resuming on a tick rather than instantly. */
class FakeAudioContext {
    public state: "suspended" | "running" = "suspended";

    public readonly destination = {};

    public readonly sampleRate = 48_000;

    public constructor(public readonly created: Created) {}

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
    public createMediaElementSource(): FakeNode {
        this.created.sources += 1;
        const source = fakeNode();
        this.created.sourceNodes.push(source);

        return source;
    }

    /** An analyser that fills a frequency read with 7s and a waveform read with 9s. */
    public createAnalyser(): FakeAnalyser {
        const analyser: FakeAnalyser = {
            ...fakeNode(),
            fftSize: 0,
            smoothingTimeConstant: 0.8,
            minDecibels: -100,
            maxDecibels: -30,
            frequencyBinCount: 32,
            getByteFrequencyData: array => array.fill(7),
            getByteTimeDomainData: array => array.fill(9)
        };
        // The spread copied the closures of a DIFFERENT node; give this one its own records.
        analyser.connect = to => {
            analyser.connected.push(to);
        };
        analyser.disconnect = from => {
            analyser.disconnected.push(from);
        };
        this.created.analysers.push(analyser);

        return analyser;
    }

    public async close(): Promise<void> {
        // Nothing to release.
    }
}

/** What the fake context has built since the last reset. */
let created: Created;

describe("useAudioAnalyser", () => {
    beforeEach(() => {
        created = { sources: 0, sourceNodes: [], analysers: [] };
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

describe("openAudioTap", () => {
    /*
     * PARTY MODE'S READER. It wants a far longer window than the bars (to tell a kick from a bass
     * line) and the raw waveform, and it must get them WITHOUT a second routing — which would
     * throw — and without touching the bars' analyser or the path to the speakers.
     */
    const options = { fftSize: 2048, smoothing: 0.55, minDecibels: -90, maxDecibels: -25 };

    beforeEach(() => {
        created = { sources: 0, sourceNodes: [], analysers: [] };
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

    /** Read once into fresh arrays of the tap's own sizes. */
    const readOnce = (tap: ReturnType<typeof openAudioTap>) => {
        const frequency = new Uint8Array(tap.bins);
        const waveform = new Uint8Array(options.fftSize);

        return { ok: tap.read(frequency, waveform), frequency, waveform };
    };

    it("routes the element itself when nothing has, and shares that one routing with the bars", async () => {
        bindAnalyserElement(document.createElement("audio"));

        openAudioTap(options);
        useAudioAnalyser().activate();

        await vi.waitFor(() => expect(created.sources).toBe(1));
        await Promise.resolve();
        expect(created.sources).toBe(1);
    });

    it("reads nothing before there is a graph, then fills both arrays from a node of its own", async () => {
        bindAnalyserElement(document.createElement("audio"));
        const tap = openAudioTap(options);

        // The resume is still in flight: no source exists yet, so there is nothing to read.
        expect(readOnce(tap).ok).toBe(false);

        await vi.waitFor(() => expect(readOnce(tap).ok).toBe(true));
        const { frequency, waveform } = readOnce(tap);
        expect(frequency.every(byte => byte === 7)).toBe(true);
        expect(waveform.every(byte => byte === 9)).toBe(true);
        expect(tap.sampleRate()).toBe(48_000);
        expect(tap.bins).toBe(1024);

        // Its OWN analyser, at its own resolution, hung off the one source — and connected
        // nowhere onwards, because joining the destination would play the music twice.
        const [bars, own] = created.analysers;
        expect(own?.fftSize).toBe(2048);
        expect(own?.smoothingTimeConstant).toBe(0.55);
        expect(own?.minDecibels).toBe(-90);
        expect(own?.maxDecibels).toBe(-25);
        expect(created.sourceNodes[0]?.connected).toEqual([bars, own]);
        expect(own?.connected).toEqual([]);
    });

    it("cuts only its own connection on release, leaving the bars and the speakers wired", async () => {
        bindAnalyserElement(document.createElement("audio"));
        const tap = openAudioTap(options);
        await vi.waitFor(() => expect(readOnce(tap).ok).toBe(true));

        tap.release();

        // A bare `disconnect()` on the source would silence the element; it must name the tap.
        const own = created.analysers[1];
        expect(created.sourceNodes[0]?.disconnected).toEqual([own]);
        expect(readOnce(tap).ok).toBe(false);
    });

    it("follows the player onto a new element, building a node on the new source", async () => {
        bindAnalyserElement(document.createElement("audio"));
        const tap = openAudioTap(options);
        await vi.waitFor(() => expect(readOnce(tap).ok).toBe(true));

        // The queue emptied and refilled: PlayerBar mounted a new <audio>. An open tap is a reason
        // to route it at once, as an active bar row is.
        bindAnalyserElement(document.createElement("audio"));
        await vi.waitFor(() => expect(created.sources).toBe(2));
        await vi.waitFor(() => expect(readOnce(tap).ok).toBe(true));

        const newest = created.analysers[created.analysers.length - 1];
        expect(created.sourceNodes[1]?.connected).toContain(newest);
    });
});
