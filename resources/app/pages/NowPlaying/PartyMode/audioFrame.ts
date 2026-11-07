/******************************************************************************
 * audioFrame
 * Turns one raw analyser reading into what a party-mode visualiser draws from: a log-spaced
 * spectrum, the waveform, three energies, an overall level and a beat envelope.
 *
 * THE HOST ANALYSES, THE PLUGIN ONLY DRAWS. Every visualiser wants the same handful of numbers,
 * and the hard parts — which bins are bass, how fast a level may fall, when a kick counts as a
 * beat — are decisions that should be made once, tested once and felt the same way whichever
 * picture is on screen. A plugin that wants the raw bytes can still read `spectrum` and
 * `waveform`; one that wants "pulse on the kick" reads `beat` and stops there.
 *
 * LOG-SPACED BANDS, because that is how pitch is heard: an FFT's bins are evenly spaced in Hz,
 * so linear bands put half the picture above 10 kHz and the whole bass line in the first few
 * percent. 64 bands from 40 Hz to 16 kHz give an octave about seven bands wide everywhere.
 *
 * THE BEAT IS RATE-LIMITED FOR A REASON THAT IS NOT TASTE. Anything flashing more than three
 * times a second fails WCAG 2.3.1 (photosensitive seizures), and a beat is exactly what a
 * visualiser brightens on. `MIN_BEAT_INTERVAL` caps it below that whatever the music does, so no
 * plugin has to remember to.
 *
 * IT ALLOCATES ONCE. The frame object and its two arrays are created with the analysis and
 * rewritten in place every call, because this runs sixty times a second for as long as a party
 * lasts. A caller that wants to keep a reading must copy it.
 *****************************************************************************/

/** How many bands the spectrum is reduced to. */
export const SPECTRUM_BANDS = 64;

/** How many points the waveform is resampled to — enough for a line across a 4K screen. */
export const WAVEFORM_POINTS = 256;

/** The lowest pitch a band starts at, in Hz. Below this is rumble a party speaker cannot play. */
const LOWEST_HZ = 40;

/** The highest band's upper edge, in Hz — capped at the stream's own Nyquist when that is lower. */
const HIGHEST_HZ = 16_000;

/** Where bass ends and the mids begin, in Hz: kick drum and bass guitar below it. */
const BASS_CEILING_HZ = 160;

/** Where the mids end and the treble begins, in Hz: hi-hats, cymbals and sibilance above it. */
const TREBLE_FLOOR_HZ = 2_500;

/**
 * The shortest gap between two beats, in seconds — 0.35s is at most 2.86 a second, under the
 * three-flash threshold of WCAG 2.3.1 (see the module note), and still every beat of a 170 BPM
 * track.
 */
export const MIN_BEAT_INTERVAL = 0.35;

/** How fast a rising level catches up, in seconds: quick, so a hit lands with the sound. */
const RISE = 0.03;

/** How fast a falling level lets go, in seconds: slower, so the picture breathes rather than blinks. */
const FALL = 0.25;

/** How long the beat envelope takes to fade by a factor of e, in seconds. */
const BEAT_DECAY = 0.2;

/** The averaging window the beat is measured against, in seconds: about two bars of music. */
const BASS_MEMORY = 1.0;

/** The longest frame gap that is still treated as one frame — see `elapsed`. */
const MAX_STEP = 0.1;

/** One reading, ready to draw. Rewritten in place on every call — copy what you keep. */
export type AudioFrame = {
    /** {@link SPECTRUM_BANDS} levels, 0–1, low pitch first, log-spaced 40 Hz – 16 kHz. */
    spectrum: Float32Array;
    /** {@link WAVEFORM_POINTS} samples of the signal, −1 to 1. */
    waveform: Float32Array;
    /** Energy below 160 Hz, 0–1, smoothed. */
    bass: number;
    /** Energy from 160 Hz to 2.5 kHz, 0–1, smoothed. */
    mid: number;
    /** Energy above 2.5 kHz, 0–1, smoothed. */
    treble: number;
    /** Overall loudness from the waveform's RMS, 0–1, smoothed. */
    level: number;
    /** 1 on the frame a beat lands, decaying towards 0 — never re-lit within {@link MIN_BEAT_INTERVAL}. */
    beat: number;
    /** Whether this frame came from real audio, rather than from {@link AudioAnalysis.idle}. */
    live: boolean;
};

/** What {@link createAudioAnalysis} hands back. */
export type AudioAnalysis = {
    /**
     * Analyse one reading: `frequency` and `waveform` exactly as AnalyserNode's byte methods fill
     * them, the graph's sample rate, and the time in seconds (any monotonic clock).
     */
    update: (
        frequency: Uint8Array,
        waveform: Uint8Array,
        sampleRate: number,
        seconds: number
    ) => AudioFrame;
    /** A frame with no reading: everything falls away at its usual pace rather than snapping to zero. */
    idle: (seconds: number) => AudioFrame;
};

/** Where each band starts and ends, in fractional bins, and which bands make up the three energies. */
type BandLayout = {
    /** `SPECTRUM_BANDS + 1` edges, in bins (fractional). */
    edges: Float32Array;
    /** The first band above the bass ceiling. */
    midStart: number;
    /** The first band above the treble floor. */
    trebleStart: number;
};

/**
 * Lay the bands out over a spectrum of `bins` bins at `sampleRate`.
 *
 * Exported for the tests, which pin that the edges are log-spaced and that the three energies
 * split where the constants say. Recomputed only when the sample rate or window changes, which
 * in practice is once.
 */
export function bandLayout(bins: number, sampleRate: number): BandLayout {
    const nyquist = sampleRate / 2;
    const binHz = nyquist / bins;
    const top = Math.min(HIGHEST_HZ, nyquist);
    const ratio = top / LOWEST_HZ;
    const edges = new Float32Array(SPECTRUM_BANDS + 1);

    let midStart = SPECTRUM_BANDS;
    let trebleStart = SPECTRUM_BANDS;

    for (let edge = 0; edge <= SPECTRUM_BANDS; edge++) {
        const hz = LOWEST_HZ * ratio ** (edge / SPECTRUM_BANDS);
        edges[edge] = hz / binHz;

        // A band belongs to the range its LOWER edge sits in.
        if (edge < SPECTRUM_BANDS && hz >= BASS_CEILING_HZ && midStart === SPECTRUM_BANDS) midStart = edge;
        if (edge < SPECTRUM_BANDS && hz >= TREBLE_FLOOR_HZ && trebleStart === SPECTRUM_BANDS) trebleStart = edge;
    }

    return { edges, midStart, trebleStart };
}

/**
 * One band's level from the raw bytes, 0–1.
 *
 * A NARROW band — narrower than a bin, which is every band under ~250 Hz at a 2,048 window —
 * interpolates the spectrum at its centre, so neighbouring bass bands step smoothly instead of
 * repeating one bin as a staircase. A WIDE band takes its loudest bin, because averaging a
 * treble band of forty bins with one cymbal in it would hide the cymbal.
 */
function bandLevel(frequency: Uint8Array, from: number, to: number): number {
    const last = frequency.length - 1;

    if (to - from < 1) {
        const centre = Math.min((from + to) / 2, last);
        const low = Math.floor(centre);
        const high = Math.min(low + 1, last);
        const t = centre - low;

        return ((frequency[low] ?? 0) * (1 - t) + (frequency[high] ?? 0) * t) / 255;
    }

    let loudest = 0;
    for (let bin = Math.floor(from); bin < Math.min(Math.ceil(to), last + 1); bin++) {
        loudest = Math.max(loudest, frequency[bin] ?? 0);
    }

    return loudest / 255;
}

/**
 * Move `current` towards `target` over `step` seconds — quickly up, slowly down.
 *
 * Time-based rather than per frame, so a 120 Hz display and a phone throttled to 30 frames a
 * second feel the same: an exponential approach whose time constant is RISE or FALL.
 */
function approach(current: number, target: number, step: number): number {
    const constant = target > current ? RISE : FALL;

    return current + (target - current) * (1 - Math.exp(-step / constant));
}

/** The mean of `values` from `from` (inclusive) to `to` (exclusive), or 0 for an empty range. */
function mean(values: Float32Array, from: number, to: number): number {
    if (to <= from) return 0;

    let total = 0;
    for (let index = from; index < to; index++) total += values[index] ?? 0;

    return total / (to - from);
}

/**
 * Start an analysis: one frame object, reused, plus the little memory a beat detector needs.
 *
 * A factory rather than a module singleton, because the memory belongs to one party: closing
 * party mode and opening it again should not start with the last party's running average.
 */
export function createAudioAnalysis(): AudioAnalysis {
    const frame: AudioFrame = {
        spectrum: new Float32Array(SPECTRUM_BANDS),
        waveform: new Float32Array(WAVEFORM_POINTS),
        bass: 0,
        mid: 0,
        treble: 0,
        level: 0,
        beat: 0,
        live: false
    };

    let layout: BandLayout | null = null;
    let layoutKey = "";
    let previous: number | null = null;
    let bassAverage = 0;
    let lastBeat = Number.NEGATIVE_INFINITY;

    /**
     * Seconds since the last call, clamped to {@link MAX_STEP}: a tab that was hidden for a minute
     * comes back as one ordinary frame, rather than as a minute of decay applied at once.
     */
    const elapsed = (seconds: number): number => {
        const step = previous === null ? 0 : Math.min(Math.max(seconds - previous, 0), MAX_STEP);
        previous = seconds;

        return step;
    };

    /** Let the beat envelope fade by `step` seconds' worth. */
    const fadeBeat = (step: number): void => {
        frame.beat *= Math.exp(-step / BEAT_DECAY);
    };

    return {
        /** Analyse one reading — see {@link AudioAnalysis.update}. */
        update(frequency, waveform, sampleRate, seconds) {
            const step = elapsed(seconds);
            const key = `${frequency.length}@${sampleRate}`;
            if (layout === null || layoutKey !== key) {
                layout = bandLayout(frequency.length, sampleRate);
                layoutKey = key;
            }

            for (let band = 0; band < SPECTRUM_BANDS; band++) {
                const level = bandLevel(frequency, layout.edges[band] ?? 0, layout.edges[band + 1] ?? 0);
                // A gentle tilt towards the top: music carries far less energy up there, and an
                // untilted picture is a bright floor under a ceiling that never lights.
                const tilt = 0.9 + 0.3 * (band / (SPECTRUM_BANDS - 1));
                frame.spectrum[band] = Math.min(1, level * tilt);
            }

            let power = 0;
            const stride = waveform.length / WAVEFORM_POINTS;
            for (let point = 0; point < WAVEFORM_POINTS; point++) {
                frame.waveform[point] = ((waveform[Math.floor(point * stride)] ?? 128) - 128) / 128;
            }
            for (let sample = 0; sample < waveform.length; sample++) {
                const value = ((waveform[sample] ?? 128) - 128) / 128;
                power += value * value;
            }
            const rms = waveform.length === 0 ? 0 : Math.sqrt(power / waveform.length);

            const bass = mean(frame.spectrum, 0, layout.midStart);
            frame.bass = approach(frame.bass, bass, step);
            frame.mid = approach(frame.mid, mean(frame.spectrum, layout.midStart, layout.trebleStart), step);
            frame.treble = approach(frame.treble, mean(frame.spectrum, layout.trebleStart, SPECTRUM_BANDS), step);
            // ×3: an RMS of 0.33 is already loud mastered pop, and the level is a 0–1 dial.
            frame.level = approach(frame.level, Math.min(1, rms * 3), step);

            // An ONSET, not a level: the bass jumping clear of its own recent average. Against a
            // fixed threshold, a bass-heavy track would beat on every frame and a quiet one never.
            fadeBeat(step);
            const onset = bass > 0.2 && bass > bassAverage * 1.25 + 0.04;
            if (onset && seconds - lastBeat >= MIN_BEAT_INTERVAL) {
                frame.beat = 1;
                lastBeat = seconds;
            }
            bassAverage += (bass - bassAverage) * (1 - Math.exp(-step / BASS_MEMORY));

            frame.live = true;

            return frame;
        },

        /** Decay towards silence with no reading — see {@link AudioAnalysis.idle}. */
        idle(seconds) {
            const step = elapsed(seconds);
            const keep = Math.exp(-step / FALL);

            for (let band = 0; band < SPECTRUM_BANDS; band++) frame.spectrum[band] = (frame.spectrum[band] ?? 0) * keep;
            frame.waveform.fill(0);
            frame.bass = approach(frame.bass, 0, step);
            frame.mid = approach(frame.mid, 0, step);
            frame.treble = approach(frame.treble, 0, step);
            frame.level = approach(frame.level, 0, step);
            fadeBeat(step);
            frame.live = false;

            return frame;
        }
    };
}
