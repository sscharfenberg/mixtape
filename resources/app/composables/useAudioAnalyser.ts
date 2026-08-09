/******************************************************************************
 * useAudioAnalyser
 * Frequency levels off the playing element, for the Now Playing page's visualiser — and the one
 * piece of this app that touches the Web Audio API at all.
 *
 * IT IS LAZY, AND THAT IS THE WHOLE SAFETY MODEL. Routing an element through an AudioContext is
 * PERMANENT: `createMediaElementSource()` redirects its output into the graph, `disconnect()`
 * yields silence rather than ordinary playback, and a second call on the same element throws. So
 * nothing here happens until something actually asks — a listener who never opens Now Playing
 * never has their audio routed, and the app's most important behaviour (screen-off playback) is
 * untouched for them.
 *
 * THE RISK WAS MEASURED, NOT ARGUED. `/dev/audio-probe` played a routed element on the owner's
 * Android with the screen locked: away 215s, audio advanced 215s. The context is not suspended
 * while the page is hidden, so a routed queue keeps playing. Had it stalled, the failure would
 * have been the worst kind — the element still reporting playing, the timeline still advancing,
 * the lock screen still saying playing, and no sound. docs/now-playing.md carries the numbers.
 *
 * IT NEVER ROUTES INTO A DEAD CONTEXT, which is the one ordering that matters here. An
 * AudioContext starts SUSPENDED, and an element routed into a suspended graph is silent — so the
 * context is resumed FIRST and the element is only routed once it is actually running. When the
 * browser refuses the resume (no user gesture yet in this document), nothing is routed at all and
 * the next `play` tries again. The cost of getting that backwards is silence the listener cannot
 * explain.
 *
 * It takes the element the same way usePlayerVolume and usePlayerSpeed do — handed over by
 * usePlayerAudio, which owns it — rather than reaching for the DOM itself. There is one element
 * making sound, and exactly one module that decides what it is.
 *
 * The graph OUTLIVES deactivation on purpose. Leaving the page stops the animation frames; it
 * does not tear down the routing, because there is no way to un-route without throwing the
 * element away, and pulling the graph out from under a playing track to save a few objects would
 * be trading silence for nothing.
 *****************************************************************************/
import type { Ref } from "vue";
import { ref } from "vue";

/** What {@link useAudioAnalyser} hands back. */
export type UseAudioAnalyserReturn = {
    /**
     * The current levels, one per band, each 0–1. Empty until something activates it, and frozen
     * at whatever it last read once everything deactivates.
     */
    levels: Ref<number[]>;
    /**
     * Whether the graph is live and producing readings — false while the context is suspended, or
     * before anything has asked. What a consumer draws its "no signal" state from.
     */
    isAnalysing: Ref<boolean>;
    /** Start reading, routing the element on the first call. Balanced by {@link deactivate}. */
    activate: () => void;
    /** Stop reading. The routing stays — see the module note. */
    deactivate: () => void;
};

/**
 * How many bands the levels are reduced to — and therefore how many bars are drawn.
 *
 * EXPORTED so the component draws exactly what this produces: two constants that had to agree
 * would eventually not, and the symptom would be a row of dead bars at one end.
 *
 * The analyser's own resolution is `fftSize / 2` = 128 bins, which is more than a phone should
 * re-render sixty times a second and more shape than the eye reads. 48 is the compromise found by
 * looking: 24 gave 50px-wide blocks on a desktop, which reads as a bar chart rather than as an EQ.
 *
 * It also drops the top of the spectrum, and that is a feature rather than a rounding error.
 * `floor(128 / 48)` is 2 bins per band, so the bands cover 96 of the 128 — and the missing top
 * quarter is the part of an FFT that is near-silent on almost all music, which as bars means a
 * stretch that never moves.
 */
export const ANALYSER_BANDS = 48;

/**
 * The FFT window.
 *
 * 256 is the smallest size that still resolves bass from mid, and small windows are what make a
 * visualiser feel attached to the music: a larger one averages over more time and the bars lag
 * behind what you hear.
 */
const FFT_SIZE = 256;

// Module-level, like every other player singleton here: one element, one graph, one set of
// readings, however many components ask.
const levels = ref<number[]>([]);
const isAnalysing = ref(false);

/** How many consumers currently want readings. The loop runs while this is above zero. */
let consumers = 0;

/** The element usePlayerAudio has handed over, or null before PlayerBar has mounted. */
let element: HTMLAudioElement | null = null;

/** The element that has actually been routed — routing one twice throws. */
let routed: HTMLAudioElement | null = null;

let context: AudioContext | null = null;
let analyser: AnalyserNode | null = null;
let frame: number | null = null;

/** Removes the `play` listener that retries a refused resume, or null when none is bound. */
let unbindRetry: (() => void) | null = null;

/**
 * Build the graph over the current element, once the context is actually running.
 *
 * Returns without routing anything when the browser refuses to resume — see the module note on
 * why the order matters — and binds a one-shot retry to the element's next `play`, which is as
 * close to a user gesture as this module can get.
 */
async function route(): Promise<void> {
    if (element === null || routed === element) return;

    /*
     * NO WEB AUDIO, NO VISUALISER — and nothing else changes. The bars fall back to their idle
     * baseline, the element is never routed, and playback is exactly what it was. Worth a guard
     * rather than an assumption: this is the one API in the app that a browser can plausibly not
     * have, and the alternative is a page that throws on mount. It is also what lets the
     * component be unit-tested, since happy-dom has no AudioContext.
     */
    if (typeof AudioContext === "undefined") return;

    context ??= new AudioContext();

    if (context.state !== "running") {
        try {
            await context.resume();
        } catch {
            // Refused, which is ordinary before the document has been interacted with. Fall
            // through to the retry below rather than treating it as an error.
        }
    }

    if (context.state !== "running") {
        retryOnPlay();

        return;
    }

    analyser ??= context.createAnalyser();
    analyser.fftSize = FFT_SIZE;

    // The one irreversible call in this file. Guarded by `routed` above, because a second one on
    // the same element throws rather than being ignored.
    context.createMediaElementSource(element).connect(analyser);
    analyser.connect(context.destination);
    routed = element;

    isAnalysing.value = consumers > 0;
    if (consumers > 0) read();
}

/** Try the routing again the next time the element plays, which is where the gesture is. */
function retryOnPlay(): void {
    if (element === null || unbindRetry !== null) return;

    const target = element;
    const onPlay = (): void => {
        unbindRetry?.();
        unbindRetry = null;
        void route();
    };

    target.addEventListener("play", onPlay, { once: true });
    unbindRetry = () => target.removeEventListener("play", onPlay);
}

/**
 * Read one frame of the spectrum and schedule the next.
 *
 * Averaged into {@link ANALYSER_BANDS} rather than sampled, so a band that happens to straddle a quiet bin
 * does not flicker against its neighbours. `requestAnimationFrame` stops dead while the page is
 * hidden, which is exactly right: nobody is looking at the bars, and the audio does not depend on
 * them being read.
 */
function read(): void {
    if (analyser === null) return;

    const bins = new Uint8Array(analyser.frequencyBinCount);
    analyser.getByteFrequencyData(bins);

    const perBand = Math.max(1, Math.floor(bins.length / ANALYSER_BANDS));
    const next: number[] = [];

    for (let band = 0; band < ANALYSER_BANDS; band++) {
        let total = 0;
        for (let bin = 0; bin < perBand; bin++) total += bins[band * perBand + bin] ?? 0;
        next.push(total / perBand / 255);
    }

    levels.value = next;
    frame = requestAnimationFrame(read);
}

/**
 * Take the element usePlayerAudio owns — the same hand-over `bindVolumeElement` and
 * `bindSpeedElement` receive, and for the same reason: one module decides what the element is.
 *
 * A NEW element is routed only if something is already asking for readings; otherwise it is
 * merely remembered, and the first `activate()` will route it. Null on detach, which leaves the
 * context standing: it costs nothing idle, and the next element will want it.
 */
export function bindAnalyserElement(audio: HTMLAudioElement | null): void {
    unbindRetry?.();
    unbindRetry = null;

    element = audio;

    if (audio === null) {
        stopReading();
        routed = null;
        analyser = null;

        return;
    }

    // The graph belonged to the previous element and cannot be moved; a fresh element needs a
    // fresh source node, so the old analyser goes with it.
    if (routed !== audio) {
        routed = null;
        analyser = null;
    }

    if (consumers > 0) void route();
}

/** Cancel the animation loop and say the readings have stopped. Leaves the graph alone. */
function stopReading(): void {
    if (frame !== null) cancelAnimationFrame(frame);
    frame = null;
    isAnalysing.value = false;
}

/**
 * Frequency levels from the playing element.
 *
 * Every caller shares one graph and one set of readings — see the module note for why that is a
 * singleton rather than per-component.
 */
export function useAudioAnalyser(): UseAudioAnalyserReturn {
    /** Ask for readings, routing the element on the first call that finds it unrouted. */
    function activate(): void {
        consumers += 1;
        if (routed === null) {
            void route();

            return;
        }

        isAnalysing.value = true;
        if (frame === null) read();
    }

    /**
     * Stop asking. The graph stays wired — see the module note — so this is only the animation
     * loop being let go, which is the whole cost of leaving the page.
     */
    function deactivate(): void {
        consumers = Math.max(0, consumers - 1);
        if (consumers === 0) stopReading();
    }

    return { levels, isAnalysing, activate, deactivate };
}

/** Reset the singleton — tests only, since module state outlives a test file. */
export function resetAudioAnalyserForTests(): void {
    stopReading();
    unbindRetry?.();
    unbindRetry = null;
    consumers = 0;
    element = null;
    routed = null;
    analyser = null;
    void context?.close();
    context = null;
    levels.value = [];
}
