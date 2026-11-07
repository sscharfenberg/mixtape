/******************************************************************************
 * visualizers
 * The plugin contract for party mode's picture, and the registry of plugins that fulfil it.
 *
 * THE HOST OWNS EVERYTHING BUT THE DRAWING. PartyMode holds the canvas, the fullscreen, the wake
 * lock, the animation loop, the audio tap and the analysis (audioFrame); a plugin is handed a
 * canvas and, once a frame, an AudioFrame and a clock, and draws. That is the whole of what a new
 * visualiser has to write — it cannot get fullscreen, playback or the analyser wrong, because
 * it is never given them.
 *
 * A REGISTRY, NOT A UNION — the same shape as `App\Enums\SearchKind` and its classes. Adding a
 * visualiser is one module and one line below; nothing in the host branches on which one is
 * running. The array's order is the picker's order, after "random".
 *
 * RANDOM IS THE DEFAULT: the party opens on a visualiser picked at random and moves to another
 * every RANDOM_INTERVAL_SECONDS, never the same one twice in a row. Picking one by name holds it.
 *
 * MOST PLUGINS ARE ONE FRAGMENT SHADER — Shader/shaderPlugin turns that and a little per-frame
 * state into a plugin, and Shader/glsl holds what every shader shares (the ramp, the noise, the
 * bounded beat lift). The two tunnels share WindingTube, the path they wind along.
 *
 * EVERY PLUGIN IS ITS OWN CHUNK. `load` is a dynamic `import()`, so Vite splits each plugin out
 * and the Now Playing page pays for none of them until party mode opens — and then for exactly
 * the one on screen.
 *
 * PLUGINS ARE BUILT INTO THE APP, NEVER LOADED AT RUNTIME. Running a stranger's JavaScript on an
 * authenticated, internet-facing origin would be cross-site scripting by design, and the
 * production CSP (`script-src 'self'`) refuses it anyway. A visualiser others could supply safely
 * would be DATA — a fragment shader against a fixed set of inputs, like most of these are — not
 * code; that is the shape to keep open, and why the contract hands a plugin nothing but a canvas.
 *****************************************************************************/
import type { AudioFrame } from "./audioFrame";

/** A colour as three 0–1 channels, the form a shader uniform takes. */
export type Rgb = readonly [number, number, number];

/**
 * The colours a visualiser paints with, read from party mode's colour tokens by the host.
 *
 * A PLUGIN NEVER WRITES A COLOUR OF ITS OWN — the token rules apply to a shader as much as to a
 * stylesheet. It may blend between these (a gradient is not a new colour), never invent one.
 */
export type VisualizerPalette = {
    /** The empty space behind everything. */
    space: Rgb;
    /** Structure that is always there — gridlines, the frame of the picture. */
    grid: Rgb;
    /** The quiet end of the level ramp. */
    low: Rgb;
    /** The middle of the level ramp. */
    mid: Rgb;
    /** The loud end of the level ramp. */
    high: Rgb;
    /** Light at the centre of attention — what a beat brightens. */
    glow: Rgb;
};

/** What a plugin is given to build itself. */
export type VisualizerSetup = {
    /** The canvas to draw on. Its drawing-buffer size is set by `resize`, never by the plugin. */
    canvas: HTMLCanvasElement;
    /** The colours to draw with. */
    palette: VisualizerPalette;
};

/** A running visualiser, as the host drives it. */
export type VisualizerRenderer = {
    /** The drawing buffer is now `width` × `height` device pixels. Called before the first render. */
    resize: (width: number, height: number) => void;
    /**
     * Draw one frame. `seconds` is a monotonic clock since the party opened — a plugin derives its
     * own step from it — and `frame` is rewritten in place after this returns, so copy what you keep.
     */
    render: (frame: AudioFrame, seconds: number) => void;
    /** Release every GPU or DOM resource. The canvas itself belongs to the host. */
    dispose: () => void;
};

/** A visualiser module's default export. */
export type VisualizerPlugin = {
    /**
     * Build a renderer, or answer null when this browser cannot run it (no WebGL2, say) — the host
     * then shows the party without a picture rather than failing. A THROW means the plugin is
     * broken rather than unsupported (a shader that will not compile), and the host treats it the
     * same way, after reporting it.
     */
    create: (setup: VisualizerSetup) => VisualizerRenderer | null;
};

/** One registry entry: the id (also the i18n key under `nowPlaying.party.visualizers`) and its loader. */
export type VisualizerEntry = {
    /** Stable id: names the plugin in the translation catalogs and in the picker. */
    id: string;
    /** Load the plugin's module — a dynamic import, so each is a chunk of its own. */
    load: () => Promise<{ default: VisualizerPlugin }>;
};

/** Every visualiser, in the order the picker lists them. */
export const VISUALIZERS: readonly VisualizerEntry[] = [
    { id: "eqWarp", load: () => import("./EQWarp/eqWarp") },
    { id: "warpTunnel", load: () => import("./WarpTunnel/warpTunnel") },
    { id: "synthwave", load: () => import("./Synthwave/synthwave") },
    { id: "julia", load: () => import("./Julia/julia") },
    { id: "feedback", load: () => import("./Feedback/feedback") },
    { id: "plasma", load: () => import("./Plasma/plasma") },
    { id: "nebula", load: () => import("./Nebula/nebula") }
];

/** The picker's choice that is not a visualiser: a new one at random, every RANDOM_INTERVAL_SECONDS. */
export const RANDOM = "random";

/** What the picker can hold: RANDOM, or a visualiser's id. */
export type VisualizerChoice = string;

/**
 * How long random mode stays on one visualiser, in seconds — about a song, so the picture changes
 * often enough to stay a surprise and rarely enough not to become the show.
 */
export const RANDOM_INTERVAL_SECONDS = 180;

/**
 * The index of the next visualiser random mode shows: any of `count`, but never `current` again —
 * a "change" to the same picture would read as nothing happening. `current` is -1 when nothing is
 * showing yet, which leaves every index open. `random` is injectable so a test can pin it.
 */
export function pickRandom(count: number, current: number, random: () => number = Math.random): number {
    if (count <= 1) return 0;
    if (current < 0 || current >= count) return Math.floor(random() * count);

    // One of the OTHER count - 1, shifted past the current one.
    const pick = Math.floor(random() * (count - 1));

    return pick >= current ? pick + 1 : pick;
}
