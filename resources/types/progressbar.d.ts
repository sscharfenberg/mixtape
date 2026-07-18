/**
 * Ambient types for @sscharfenberg/progressbar.
 *
 * The package is a hand-rolled ESM module that ships no `.d.ts` (and no
 * `types`/`exports` field), so `vue-tsc` sees the import as `any`. This shim
 * declares the four functions we consume (from the `progressbar.js` entry) and
 * the options object accepted by `startProgress`, so the router wiring in
 * main.ts is fully typed. Mirror the package's exports if it gains more.
 */
declare module "@sscharfenberg/progressbar/progressbar.js" {
    /** Options for {@link startProgress}; all optional (the package fills defaults). */
    export interface ProgressBarOptions {
        /** Log lifecycle messages to the console. */
        debug?: boolean;
        /** Creep the bar forward on a timer to suggest ongoing work. */
        trickle?: boolean;
        /** Milliseconds between trickle steps. */
        trickleSpeed?: number;
        /** Max random increment per trickle step. */
        trickleRate?: number;
        /** Value the bar starts at (0–1). */
        startingValue?: number;
        /** `querySelector` for the node the bar is appended to. */
        parent?: string;
        /** `querySelector` for the visual bar within the template. */
        barSelector?: string;
        /** Accessible label for the progressbar element. */
        ariaLabel?: string;
        /** Lower clamp for the value (0–1). */
        minValue?: number;
        /** Upper clamp for the value (0–1). */
        maxValue?: number;
        /** HTML template string for the progressbar markup. */
        template?: string;
    }

    /** Set the bar to an explicit value (0–1); ≥1 finishes it. No-op if no bar exists. */
    export function setProgress(value: number): void;
    /** Create and start the bar (with optional overrides). No-op if one already exists. */
    export function startProgress(options?: ProgressBarOptions): void;
    /** Whether a progressbar element is currently in the DOM. */
    export function doesProgressBarExist(): boolean;
    /** Fill to 100% and remove the bar shortly after. */
    export function finishProgress(): void;
}
