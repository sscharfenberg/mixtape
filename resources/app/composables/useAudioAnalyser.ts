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
 * THE RISK IS MEASURED, NOT ARGUED. `/dev/audio-probe` plays a routed element on a real Android
 * phone with the screen locked: away 215s, audio advanced 215s. The context is not suspended
 * while the page is hidden, so a routed queue keeps playing. Had it stalled, the failure would
 * be the worst kind — the element still reporting playing, the timeline still advancing, the
 * lock screen still saying playing, and no sound. docs/now-playing.md carries the numbers.
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
 * How many bands the levels are reduced to until a consumer asks for a different count — and
 * therefore how many bars are drawn where nothing has asked.
 *
 * THE TOP OF A LADDER, not a fixed number: the widest row draws this many and a
 * phone draws half, because the bars get thinner rather than fewer as the row narrows and 48 across
 * a phone is a smear rather than a spectrum. {@link setAnalyserBands} is how the drawer says which
 * rung it is on.
 *
 * 48 itself is the compromise found by looking: the analyser's own resolution is `fftSize / 2` =
 * 128 bins, which is more shape than the eye reads and more than a phone should re-render sixty
 * times a second, while 24 gave 50px-wide blocks on a desktop — a bar chart rather than an EQ.
 *
 * EXPORTED so the component has a count to draw before any stylesheet has answered, and so the two
 * cannot disagree by being written twice.
 */
export const ANALYSER_DEFAULT_BANDS = 48;

/**
 * How much of the spectrum the bands are spread over, as a fraction of the bins.
 *
 * THE TOP QUARTER IS DROPPED ON PURPOSE — it is the part of an FFT that is near-silent on almost
 * all music, which as bars means a stretch that never moves. It is a constant rather than a
 * consequence of the band count (which would make it fall out of `floor(128 / 48)` = 2 bins) because
 * the drawn spectrum must stay THE SAME at every count: 24 bars are a coarser reading of the same
 * 96 bins, not a wider one, so a phone does not grow a dead tail by asking for fewer.
 */
const USED_SPECTRUM = 0.75;

/** How many bands to produce. Set by whoever is drawing them — see {@link setAnalyserBands}. */
let bands = ANALYSER_DEFAULT_BANDS;

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

/**
 * The routing already under way, so a second caller joins it rather than starting its own.
 *
 * `routed` alone cannot do this, because it is only set AFTER `context.resume()` is awaited —
 * and two calls that overlap that await both pass its guard and both reach
 * `createMediaElementSource`, which throws on the second. Both callers are real: Visualizer
 * activates on mount for a page entered mid-track and again when playback starts, with a
 * suspended context in between on a fresh load where nothing has been clicked yet.
 */
let routing: Promise<void> | null = null;

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
function route(): Promise<void> {
    if (element === null || routed === element) return Promise.resolve();
    if (routing !== null) return routing;

    routing = routeOnce();

    return routing.finally(() => {
        routing = null;
    });
}

/** The body of {@link route}, entered by one caller at a time — see `routing` on why. */
async function routeOnce(): Promise<void> {
    if (element === null) return;

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
 * AVERAGED rather than sampled, so a band that happens to straddle a quiet bin does not flicker
 * against its neighbours. The group boundaries are computed per band from the current count rather
 * than from a cached group size, which is what lets {@link setAnalyserBands} take effect on the next
 * frame with nothing to restart — and what keeps the groups even when the count does not divide the
 * used bins exactly.
 *
 * `requestAnimationFrame` stops dead while the page is hidden, which is exactly right: nobody is
 * looking at the bars, and the audio does not depend on them being read.
 */
function read(): void {
    if (analyser === null) return;

    const bins = new Uint8Array(analyser.frequencyBinCount);
    analyser.getByteFrequencyData(bins);

    const used = Math.floor(bins.length * USED_SPECTRUM);
    const next: number[] = [];

    for (let band = 0; band < bands; band++) {
        const from = Math.floor((band * used) / bands);
        // At least one bin, for the pathological case of more bands than there are bins to spread
        // over — a band with an empty range would read as a permanently dead bar.
        const to = Math.max(from + 1, Math.floor(((band + 1) * used) / bands));

        let total = 0;
        for (let bin = from; bin < to; bin++) total += bins[bin] ?? 0;
        next.push(total / (to - from) / 255);
    }

    levels.value = next;
    frame = requestAnimationFrame(read);
}

/**
 * Say how many bands to average the spectrum into — one per bar the consumer means to draw.
 *
 * THE DRAWER DECIDES, because the count is a question about WIDTH and only the component can see how
 * much width it has: the visualiser reads it off a CSS custom property so the breakpoints it steps
 * at stay in the stylesheet with every other one. Keeping the number here instead would mean two
 * constants that had to agree, and the symptom of their drifting would be a row of dead bars at one
 * end.
 *
 * Floored at one band, since zero would produce an empty reading and draw nothing at all.
 */
export function setAnalyserBands(count: number): void {
    bands = Math.max(1, Math.floor(count));
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
    bands = ANALYSER_DEFAULT_BANDS;
    element = null;
    routed = null;
    routing = null;
    analyser = null;
    void context?.close();
    context = null;
    levels.value = [];
}
