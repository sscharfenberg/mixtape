import { describe, expect, it } from "vitest";
import { MIN_BEAT_INTERVAL, SPECTRUM_BANDS, bandLayout, createAudioAnalysis } from "./audioFrame";

/*
 * The analysis every party-mode visualiser draws from. Pure numbers in, numbers out — so this is
 * the layer that can pin it, with synthetic readings standing in for what an AnalyserNode fills.
 *
 * What would go wrong unnoticed in a browser, where the music is never the same twice:
 *
 *   - THE BANDS ARE LOG-SPACED. Linear bands look plausible and put half the picture above
 *     10 kHz; only a test of the edges catches it.
 *   - BASS IS BASS. A reading with energy only under 160 Hz must raise `bass` and leave `treble`
 *     alone, or "the bass drives the speed" drives it from the hi-hats.
 *   - THE BEAT IS RATE-LIMITED. A visualiser brightens on it, so more than three a second fails
 *     WCAG 2.3.1 — and a pulsing test signal is exactly what would show it, where real music
 *     rarely does.
 *   - SILENCE FALLS, IT DOES NOT SNAP. Pausing should let the picture settle, not cut it.
 */

/** The window the party taps with: 2,048 samples → 1,024 bins. */
const BINS = 1024;
/** A typical device rate. */
const RATE = 48_000;
/** Hz per bin at that window and rate. */
const BIN_HZ = RATE / 2 / BINS;

/** A frequency reading with `level` (0–255) on every bin between `fromHz` and `toHz`, silence elsewhere. */
const reading = (fromHz: number, toHz: number, level = 230): Uint8Array => {
    const bins = new Uint8Array(BINS);
    for (let bin = 0; bin < BINS; bin++) {
        const hz = bin * BIN_HZ;
        if (hz >= fromHz && hz <= toHz) bins[bin] = level;
    }

    return bins;
};

/** A silent waveform — the byte methods centre on 128. */
const flat = (): Uint8Array => new Uint8Array(2048).fill(128);

describe("bandLayout", () => {
    it("spaces the bands logarithmically, so every octave gets about the same share", () => {
        const { edges } = bandLayout(BINS, RATE);

        // Equal RATIOS between consecutive edges is what log-spacing means; linear would have
        // equal differences instead, and a ratio that falls towards 1 across the range.
        const first = edges[1]! / edges[0]!;
        const last = edges[SPECTRUM_BANDS]! / edges[SPECTRUM_BANDS - 1]!;
        expect(first).toBeCloseTo(last, 5);
        expect(edges[0]! * BIN_HZ).toBeCloseTo(40, 3);
        expect(edges[SPECTRUM_BANDS]! * BIN_HZ).toBeCloseTo(16_000, 0);
    });

    it("splits bass, mids and treble where the bands cross 160 Hz and 2.5 kHz", () => {
        const { edges, midStart, trebleStart } = bandLayout(BINS, RATE);

        expect(edges[midStart - 1]! * BIN_HZ).toBeLessThan(160);
        expect(edges[midStart]! * BIN_HZ).toBeGreaterThanOrEqual(160);
        expect(edges[trebleStart - 1]! * BIN_HZ).toBeLessThan(2500);
        expect(edges[trebleStart]! * BIN_HZ).toBeGreaterThanOrEqual(2500);
    });

    it("never reaches past the stream's own Nyquist", () => {
        // A 22.05 kHz stream has nothing above 11 kHz; bands up there would be dead forever.
        const { edges } = bandLayout(BINS, 22_050);

        expect(edges[SPECTRUM_BANDS]).toBeCloseTo(BINS, 3);
    });
});

describe("createAudioAnalysis", () => {
    it("reads energy under 160 Hz as bass, and not as treble", () => {
        const analysis = createAudioAnalysis();

        let frame = analysis.update(reading(40, 150), flat(), RATE, 0);
        for (let step = 1; step <= 30; step++) frame = analysis.update(reading(40, 150), flat(), RATE, step / 60);

        expect(frame.bass).toBeGreaterThan(0.5);
        expect(frame.treble).toBe(0);
        expect(frame.live).toBe(true);
    });

    it("puts a treble reading at the top of the spectrum, not the bottom", () => {
        const analysis = createAudioAnalysis();
        const frame = analysis.update(reading(5000, 12_000), flat(), RATE, 0);

        expect(frame.spectrum[0]).toBe(0);
        expect(frame.spectrum[SPECTRUM_BANDS - 5]).toBeGreaterThan(0.8);
    });

    it("beats on a kick, and never twice inside the minimum interval however hard it pulses", () => {
        const analysis = createAudioAnalysis();
        const beats: number[] = [];
        let previousBeat = 0;

        // Ten seconds of a kick drum every 100ms — four times faster than any beat the analysis
        // may report — alternating with silence so every kick is a genuine onset.
        for (let step = 0; step < 600; step++) {
            const seconds = step / 60;
            const kicking = Math.floor(seconds * 10) % 2 === 0;
            const frame = analysis.update(kicking ? reading(40, 150) : new Uint8Array(BINS), flat(), RATE, seconds);
            if (frame.beat === 1 && previousBeat !== 1) beats.push(seconds);
            previousBeat = frame.beat;
        }

        expect(beats.length).toBeGreaterThan(5);
        for (let index = 1; index < beats.length; index++) {
            expect(beats[index]! - beats[index - 1]!).toBeGreaterThanOrEqual(MIN_BEAT_INTERVAL - 1e-9);
        }
        // Under three a second, which is the line WCAG 2.3.1 draws.
        expect(beats.length / 10).toBeLessThan(3);
    });

    it("does not beat on a bass line that simply holds", () => {
        const analysis = createAudioAnalysis();
        let beats = 0;

        // Settle on a steady bass first: an onset is a jump clear of the recent average, and a
        // held note stops being one.
        for (let step = 0; step < 240; step++) analysis.update(reading(40, 150), flat(), RATE, step / 60);
        for (let step = 240; step < 480; step++) {
            if (analysis.update(reading(40, 150), flat(), RATE, step / 60).beat === 1) beats += 1;
        }

        expect(beats).toBe(0);
    });

    it("lets everything fall away when the reading stops, rather than snapping to zero", () => {
        const analysis = createAudioAnalysis();
        for (let step = 0; step < 30; step++) analysis.update(reading(40, 12_000), flat(), RATE, step / 60);

        const settled = analysis.idle(30 / 60);
        expect(settled.live).toBe(false);
        expect(settled.bass).toBeGreaterThan(0);

        let frame = settled;
        for (let step = 31; step < 31 + 180; step++) frame = analysis.idle(step / 60);
        expect(frame.bass).toBeLessThan(0.01);
        expect(frame.spectrum[10]).toBeLessThan(0.01);
    });

    it("treats a long gap as one frame, so a tab back from the background does not lurch", () => {
        const analysis = createAudioAnalysis();
        for (let step = 0; step < 30; step++) analysis.update(reading(40, 150), flat(), RATE, step / 60);
        const before = analysis.update(reading(40, 150), flat(), RATE, 0.5).bass;

        // A minute away; one idle frame should decay by at most one clamped step.
        const after = analysis.idle(60.5).bass;

        expect(after).toBeGreaterThan(before * 0.5);
    });

    it("reads the level off the waveform", () => {
        const analysis = createAudioAnalysis();
        const loud = new Uint8Array(2048).map((_, sample) => (sample % 2 === 0 ? 228 : 28));

        let frame = analysis.update(new Uint8Array(BINS), loud, RATE, 0);
        for (let step = 1; step < 30; step++) frame = analysis.update(new Uint8Array(BINS), loud, RATE, step / 60);

        expect(frame.level).toBeGreaterThan(0.9);
        expect(frame.waveform[0]).toBeCloseTo(100 / 128, 5);
    });
});
