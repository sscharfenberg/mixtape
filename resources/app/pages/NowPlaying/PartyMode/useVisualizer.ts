/******************************************************************************
 * useVisualizer
 * Party mode's render loop: open an audio tap, analyse each frame, and hand it to whichever
 * visualiser plugin is on screen — plus the canvas sizing and the plugin's whole lifecycle.
 *
 * IT IS THE HOST HALF OF THE PLUGIN CONTRACT (visualizers.ts). A plugin draws; everything that
 * would be the same for every plugin — when to read the audio, how many pixels to draw, what to do
 * when a plugin cannot run — is decided here, once.
 *
 * THE TAP IS OPENED HERE, AND ONLY WHEN MOTION IS ALLOWED. Opening one routes the player's element
 * if nothing has (useAudioAnalyser), which is safe — measured, see docs/now-playing.md — but is
 * still a change a reader who asked for reduced motion never needs: their party is a still frame,
 * so their audio is never routed, the same promise the Now Playing bars make.
 *
 * THE PICTURE IS DRAWN BELOW THE SCREEN'S RESOLUTION, deliberately. A full-screen fragment shader
 * costs per pixel, and a phone's 3× display or a 4K monitor is millions of pixels of glow that
 * nobody can tell from a third as many. `drawingBufferSize` caps it, and the loop lowers it ONCE if
 * frames arrive very late — never twice and never back up, because a resolution that oscillates is
 * worse to look at than one that is slightly soft. "Very late" sits below 30 fps on purpose: a TV
 * at 30 Hz or a browser saving power caps the frame rate with the GPU idle, and must not be read as
 * a picture too heavy to draw.
 *
 * WHICH PICTURE: the reader's choice — RANDOM (the default) or one visualiser by name — kept in
 * this browser's storage, so the next party opens the way the last one was left. Random mode moves
 * on every RANDOM_INTERVAL_SECONDS of the party's own clock, which stops while the tab is hidden,
 * so nobody comes back to a picture that changed behind their back. Storage is a convenience: where
 * it is unavailable (a private window, blocked site data) the party simply opens on random.
 *****************************************************************************/
import type { Ref } from "vue";
import { nextTick, ref } from "vue";
import type { AudioTap } from "Composables/useAudioAnalyser";
import { openAudioTap } from "Composables/useAudioAnalyser";
import { createAudioAnalysis } from "./audioFrame";
import type { Rgb, VisualizerChoice, VisualizerPalette, VisualizerPlugin, VisualizerRenderer } from "./visualizers";
import { RANDOM, RANDOM_INTERVAL_SECONDS, VISUALIZERS, pickRandom } from "./visualizers";

/**
 * Where the loop stands — published on the overlay as `data-visualizer`, which is what lets a
 * browser test prove the shader compiled in a real engine (`running`) without reading a pixel.
 */
export type VisualizerStatus = "loading" | "running" | "still" | "unsupported";

/** What {@link useVisualizer} needs from the component. */
export type UseVisualizerOptions = {
    /** The canvas to draw on, once mounted. Re-keyed per plugin, so each gets a fresh context. */
    canvas: Ref<HTMLCanvasElement | null>;
    /** The overlay root, whose stylesheet publishes the palette as custom properties. */
    root: Ref<HTMLElement | null>;
    /** Whether the player is playing — a paused player is drawn as silence, not as its last reading. */
    isPlaying: Ref<boolean>;
};

/** What {@link useVisualizer} hands back. */
export type UseVisualizerReturn = {
    /** See {@link VisualizerStatus}. */
    status: Ref<VisualizerStatus>;
    /** The index into VISUALIZERS of the plugin on screen, or -1 before the first has loaded. */
    active: Ref<number>;
    /** What the picker holds: RANDOM, or the id of the visualiser chosen by name. */
    choice: Ref<VisualizerChoice>;
    /** Load the first plugin and start drawing. Call once the canvas is in the DOM. */
    start: () => Promise<void>;
    /** Take a new choice from the picker, show it, and remember it in this browser. */
    select: (choice: VisualizerChoice) => Promise<void>;
    /** Stop drawing and release the tap and the plugin. Safe to call more than once. */
    stop: () => void;
};

/** Where this browser remembers the picker's choice. */
const STORAGE_KEY = "mixtape.party.visualizer";

/** The remembered choice, or RANDOM when there is none, it is no longer a visualiser, or storage is unavailable. */
function storedChoice(): VisualizerChoice {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);
        if (stored !== null && VISUALIZERS.some(entry => entry.id === stored)) return stored;
    } catch {
        // Storage refused (a private window, blocked site data): random it is.
    }

    return RANDOM;
}

/** Remember the choice, quietly doing without where storage is unavailable. */
function storeChoice(choice: VisualizerChoice): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, choice);
    } catch {
        // A convenience, not a promise — the party works the same without it.
    }
}

/**
 * The most pixels a frame is drawn at — roughly 1600 × 880. Past this the picture does not get
 * sharper to the eye (it is glow and soft lines), only slower and hotter.
 */
const PIXEL_BUDGET = 1_400_000;

/** The quality the one adaptive step goes to, as a fraction of the budgeted size per side (half the pixels). */
const REDUCED_QUALITY = 0.7;

/**
 * An average frame interval, in ms, that counts as late — under 25 frames a second. Above the
 * 33ms a 30 Hz refresh cap produces, so a capped display never trips it (see the banner).
 */
const LATE_FRAME_MS = 40;

/** How many frames are averaged before deciding the picture is too heavy. */
const QUALITY_WINDOW = 90;

/** The analyser settings party mode reads with — see `openAudioTap` for why it is not the bars' node. */
const TAP_OPTIONS = {
    // 1,024 bins of ~23 Hz: fine enough to tell a kick from a bass line.
    fftSize: 2048,
    // Less smoothing than the browser's 0.8, so a hit lands as a hit; audioFrame smooths the rest.
    smoothing: 0.55,
    // A range that sits ordinary mastered music in the middle of the byte scale rather than at its top.
    minDecibels: -90,
    maxDecibels: -25
};

/**
 * The drawing-buffer size for a canvas laid out at `cssWidth` × `cssHeight`.
 *
 * Device pixels are capped at one per CSS pixel first (a 3× phone draws a third of its native
 * resolution and the browser scales it up), then the whole thing is scaled into PIXEL_BUDGET, then
 * by `quality`. Never below one pixel, because a zero-sized drawing buffer is an error in WebGL.
 */
export function drawingBufferSize(
    cssWidth: number,
    cssHeight: number,
    devicePixelRatio: number,
    quality: number
): { width: number; height: number } {
    const density = Math.min(devicePixelRatio || 1, 1);
    const width = cssWidth * density;
    const height = cssHeight * density;
    const budget = Math.min(1, Math.sqrt(PIXEL_BUDGET / Math.max(width * height, 1)));

    return {
        width: Math.max(1, Math.round(width * budget * quality)),
        height: Math.max(1, Math.round(height * budget * quality))
    };
}

/**
 * Parse a computed colour (`rgb(…)` / `rgba(…)`, which is what a browser returns for any colour
 * however it was written) into 0–1 channels, or null when it is not one.
 */
export function parseComputedColour(value: string): Rgb | null {
    const match = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/u.exec(value.trim());
    if (match === null) return null;

    return [Number(match[1]) / 255, Number(match[2]) / 255, Number(match[3]) / 255];
}

/** The palette entries, and the custom property each is published under on the overlay. */
const PALETTE_PROPERTIES: Record<keyof VisualizerPalette, string> = {
    space: "--party-space",
    grid: "--party-grid",
    low: "--party-ramp-low",
    mid: "--party-ramp-mid",
    high: "--party-ramp-high",
    glow: "--party-glow"
};

/**
 * Read the palette off the overlay's custom properties.
 *
 * THROUGH A PROBE ELEMENT'S `color`, not the property's own text: a custom property computes to
 * whatever was written (a hex, a named colour, whatever the minifier chose), while `color`
 * always computes to `rgb()`. Where no stylesheet applies (unit tests), every entry falls back to
 * black — no colour is decided here, only how to read one.
 */
function readPalette(root: HTMLElement): VisualizerPalette {
    const probe = document.createElement("span");
    probe.hidden = true;
    root.appendChild(probe);

    /** One custom property, as channels — black where it does not resolve. */
    const read = (property: string): Rgb => {
        probe.style.color = `var(${property})`;

        return parseComputedColour(getComputedStyle(probe).color) ?? [0, 0, 0];
    };

    const palette = Object.fromEntries(
        Object.entries(PALETTE_PROPERTIES).map(([key, property]) => [key, read(property)])
    ) as VisualizerPalette;

    probe.remove();

    return palette;
}

/**
 * Drive the visualiser on `options.canvas`.
 *
 * Not a module singleton, unlike the player's composables: there is one party at a time, but its
 * state (the analysis memory, the plugin, the quality) belongs to that party and must not survive
 * into the next one.
 */
export function useVisualizer(options: UseVisualizerOptions): UseVisualizerReturn {
    const status = ref<VisualizerStatus>("loading");
    const active = ref(-1);
    const choice = ref<VisualizerChoice>(storedChoice());

    const analysis = createAudioAnalysis();
    const still = !window.matchMedia("(prefers-reduced-motion: no-preference)").matches;

    let tap: AudioTap | null = null;
    let frequency: Uint8Array<ArrayBuffer> | null = null;
    let waveform: Uint8Array<ArrayBuffer> | null = null;
    let renderer: VisualizerRenderer | null = null;
    let handle: number | null = null;
    let startedAt = 0;
    // The party clock's reading when the picture on screen appeared — what random mode counts from.
    let shownAt = 0;
    let quality = 1;
    let intervals: number[] = [];
    let lastFrame: number | null = null;
    // Bumped by every mount and by stop, so a plugin import that resolves after the party has
    // moved on can tell it is no longer wanted.
    let generation = 0;

    /** Size the drawing buffer to the canvas's laid-out size, and tell the plugin. */
    const resize = (): void => {
        const canvas = options.canvas.value;
        if (canvas === null || renderer === null) return;

        const { width, height } = drawingBufferSize(
            canvas.clientWidth,
            canvas.clientHeight,
            window.devicePixelRatio,
            quality
        );
        if (canvas.width !== width) canvas.width = width;
        if (canvas.height !== height) canvas.height = height;
        renderer.resize(width, height);
        // A still party has no loop to repaint it, and resizing a canvas clears it.
        if (still) renderer.render(analysis.idle(0), 0);
    };

    /** Step the quality down, once per party, if a QUALITY_WINDOW of frames averaged late. */
    const measure = (now: number): void => {
        if (lastFrame !== null) {
            const interval = now - lastFrame;
            // A gap this long is the tab coming back from the background, not a slow frame.
            if (interval < 250) intervals.push(interval);
        }
        lastFrame = now;

        if (intervals.length < QUALITY_WINDOW) return;
        const average = intervals.reduce((total, interval) => total + interval, 0) / intervals.length;
        intervals = [];
        if (average > LATE_FRAME_MS && quality === 1) {
            quality = REDUCED_QUALITY;
            resize();
        }
    };

    /** One animation frame: read, analyse, draw, and ask for the next. */
    const tick = (now: number): void => {
        handle = requestAnimationFrame(tick);
        if (renderer === null) return;

        measure(now);
        const seconds = (now - startedAt) / 1000;
        const reading =
            tap !== null &&
            frequency !== null &&
            waveform !== null &&
            options.isPlaying.value &&
            tap.read(frequency, waveform);

        const frame =
            reading && tap !== null && frequency !== null && waveform !== null
                ? analysis.update(frequency, waveform, tap.sampleRate(), seconds)
                : analysis.idle(seconds);

        renderer.render(frame, seconds);

        // Random mode's turn is up. The switch cancels this frame's successor, so it cannot fire twice.
        if (choice.value === RANDOM && seconds - shownAt >= RANDOM_INTERVAL_SECONDS) {
            void switchTo(pickRandom(VISUALIZERS.length, active.value));
        }
    };

    /** Throw away the running plugin and stop the loop, leaving the tap open. */
    const unmountPlugin = (): void => {
        if (handle !== null) cancelAnimationFrame(handle);
        handle = null;
        renderer?.dispose();
        renderer = null;
    };

    /**
     * Load the plugin at `active` and start it on the current canvas.
     *
     * A plugin that is unsupported (null) or broken (throws) leaves the party running without a
     * picture rather than taking the overlay down with it: the track box is still the point of
     * the screen at a party. A broken one is reported to the console, because it is a bug.
     */
    const mountPlugin = async (): Promise<void> => {
        const mine = ++generation;
        const entry = VISUALIZERS[active.value];
        status.value = "loading";
        if (entry === undefined) {
            status.value = "unsupported";

            return;
        }

        // The import is inside the try too: a chunk that has gone missing (a deploy since the page
        // loaded) is a party without a picture, not one stuck on "loading".
        let plugin: VisualizerPlugin | null = null;
        try {
            plugin = (await entry.load()).default;
        } catch (error) {
            console.error(error);
        }
        await nextTick();
        const canvas = options.canvas.value;
        const root = options.root.value;
        if (mine !== generation || canvas === null || root === null) return;

        try {
            renderer = plugin === null ? null : plugin.create({ canvas, palette: readPalette(root) });
        } catch (error) {
            console.error(error);
            renderer = null;
        }

        if (renderer === null) {
            status.value = "unsupported";

            return;
        }

        resize();
        status.value = still ? "still" : "running";
        shownAt = (performance.now() - startedAt) / 1000;
        if (still) return;

        lastFrame = null;
        intervals = [];
        handle = requestAnimationFrame(tick);
    };

    /**
     * Put the plugin at `index` on screen, unless it already is. The canvas is re-keyed on
     * `active`, so the new plugin starts on a fresh context rather than one the last plugin released.
     */
    const switchTo = async (index: number): Promise<void> => {
        if (index === active.value && renderer !== null) return;
        unmountPlugin();
        active.value = index;
        await mountPlugin();
    };

    /** Open the tap (unless motion is off) and mount the first plugin. */
    const start = async (): Promise<void> => {
        window.addEventListener("resize", resize);
        // ONE clock for the whole party, never restarted per plugin: the analysis remembers the
        // last time it was called (for its step and its beat limiter), and a clock that jumped back
        // to zero on a switch would freeze it for as long as the party had been running.
        startedAt = performance.now();

        if (!still) {
            tap = openAudioTap(TAP_OPTIONS);
            frequency = new Uint8Array(tap.bins);
            waveform = new Uint8Array(TAP_OPTIONS.fftSize);
        }

        const named = VISUALIZERS.findIndex(entry => entry.id === choice.value);
        await switchTo(named >= 0 ? named : pickRandom(VISUALIZERS.length, -1));
    };

    /**
     * Take the picker's new choice. RANDOM moves straight to another picture — so choosing it visibly
     * does something — and then on every interval; a name shows that visualiser and holds it.
     */
    const select = async (next: VisualizerChoice): Promise<void> => {
        choice.value = next;
        storeChoice(next);

        const named = VISUALIZERS.findIndex(entry => entry.id === next);
        await switchTo(named >= 0 ? named : pickRandom(VISUALIZERS.length, active.value));
    };

    /** Stop everything this party started. */
    const stop = (): void => {
        generation += 1;
        window.removeEventListener("resize", resize);
        unmountPlugin();
        tap?.release();
        tap = null;
    };

    return { status, active, choice, start, select, stop };
}
