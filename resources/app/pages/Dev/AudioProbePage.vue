<script setup lang="ts">
/******************************************************************************
 * AudioProbePage (dev)
 * ONE QUESTION: does audio keep playing when the screen goes off, with the <audio> element
 * routed through an AudioContext? Reached directly at /dev/audio-probe (see the dev section
 * in routes/web.php and AudioProbeController for why it exists at all).
 *
 * HOW TO USE IT, because the measurement only works if the steps are followed:
 *   1. pick ROUTED, press start, hear sound;
 *   2. lock the phone (or switch apps) and wait a minute or two — longer than any throttling
 *      the browser might apply, and long enough that a stall is unmistakable;
 *   3. unlock, come back, and read the verdict.
 * Then do the same with DIRECT as a control. Direct playback is known to survive, so if direct
 * also fails here the phone or the network is the problem and the routed result says nothing.
 *
 * THE VERDICT IS WALL CLOCK AGAINST AUDIO CLOCK, which is the only measurement that survives
 * the page being invisible. Nothing on screen can be watched while the screen is off, and
 * `requestAnimationFrame` stops dead — so the page records `Date.now()` and `currentTime` as
 * it goes away, reads both again on return, and compares. Away for 124 seconds with 123.8
 * seconds of audio behind it is playing through; away for 124 with 3.1 is a stall three
 * seconds in, and the journal says what happened at that moment.
 *
 * `Date.now()` rather than `performance.now()` deliberately: this is a question about how much
 * REAL time passed while the page was not running, and a wall clock is the thing that cannot
 * be argued with.
 *
 * IT SHARES NOTHING WITH THE REAL PLAYER — its own element, its own context, no
 * usePlayerAudio, no queue — so whatever it proves is a fact about the browser rather than
 * about this app's wiring. It does READ the queue, only to warn when one is loaded: the real
 * PlayerBar would then have a second <audio> on the same page, and two elements make every
 * reading here ambiguous.
 *
 * ENGLISH-ONLY, like the icon gallery: a dev page has nothing in the catalogs to translate it
 * with, and this one is meant to be deleted once the answer is written down.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, ref, useTemplateRef } from "vue";
import Button from "Components/Form/Button.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { formatClock } from "Utils/formatting";

/** How the element's output reaches the speakers — the whole variable under test. */
type ProbeMode = "direct" | "routed";

/** One line of the journal: when it happened, what it was, and any reading worth keeping. */
type JournalEntry = {
    /** Seconds since start was pressed, so the lines read as a timeline. */
    at: number;
    /** What happened — a media event, a context state change, or a visibility change. */
    label: string;
    /** The readings at that moment, already formatted. */
    detail: string;
};

/** What one round trip away from the page measured. */
type Verdict = {
    /** Seconds of wall clock spent with the page hidden. */
    away: number;
    /** Seconds the audio clock advanced over the same stretch. */
    advanced: number;
    /** Whether the audio kept up — the answer this page exists to produce. */
    survived: boolean;
};

defineProps<{
    /**
     * The longest music track in the library, or null when there is none. The controller
     * explains why the longest: a track that ENDS while the screen is off would read as a
     * stall.
     */
    track: { name: string; artist: string | null; duration: number | null; streamUrl: string } | null;
}>();

const { setBreadcrumbs } = useBreadcrumbs();
// A raw label, not an i18n key — this dev-only page is English-only by design.
setBreadcrumbs([{ label: "Web Audio probe", icon: "system" }]);

const { isEmpty } = usePlayerQueue();

/** Which path to test. Chosen BEFORE start, because routing an element cannot be undone. */
const mode = ref<ProbeMode>("routed");

/** Whether a run is in progress — start is a one-way door until stop is pressed. */
const running = ref(false);

/** Everything that happened during this run, newest last. */
const journal = ref<JournalEntry[]>([]);

/** The AudioContext's own reported state, mirrored so it can be read after the fact. */
const contextState = ref<string>("—");

/** Analyser output, 0–1 per band, for the bars. Empty in direct mode, which has no analyser. */
const levels = ref<number[]>([]);

/**
 * The loudest band seen this run.
 *
 * Printed as a number because the BARS ARE AMBIGUOUS: flat bars mean either "silence" or "the
 * graph is not receiving audio at all", and on a phone there is nothing to inspect to tell the
 * two apart. A peak of 0.00 after a minute of audible music says the analyser is wired to
 * nothing; a peak of 0.7 says the graph is live and the bars were just caught in a quiet
 * moment.
 */
const peak = ref(0);

/** The result of the last round trip away from the page. */
const verdict = ref<Verdict | null>(null);

/** The element under test. Keyed on `mode`, so switching modes builds a fresh one — see `start`. */
const audioRef = useTemplateRef<HTMLAudioElement>("audio");

/** Wall-clock instant start was pressed, the origin every journal line is measured from. */
let startedAt = 0;

/** Readings taken as the page went away, or null while it is visible. */
let leftAt: { wall: number; audio: number } | null = null;

/** The graph, alive only in routed mode. */
let context: AudioContext | null = null;
let analyser: AnalyserNode | null = null;
let frame: number | null = null;

/** Everything `start()` bound, so `stop()` needs no list of its own. */
let teardown: Array<() => void> = [];

/** Whether the reader still has a queue loaded — see the banner for why that spoils a reading. */
const queueWarning = computed(() => !isEmpty.value);

/**
 * Append a line to the journal.
 *
 * Timed from `startedAt` rather than from the page load, so the numbers line up with the
 * verdict above them and with the moment the reader pressed start.
 */
function log(label: string, detail = ""): void {
    journal.value.push({
        at: startedAt === 0 ? 0 : (Date.now() - startedAt) / 1000,
        label,
        detail
    });
}

/** The element's clock, or 0 before there is one — every reading goes through here. */
function audioClock(): number {
    return audioRef.value?.currentTime ?? 0;
}

/**
 * Read the analyser into `levels` and schedule the next read.
 *
 * `requestAnimationFrame` stops entirely while the page is hidden, which is exactly right: the
 * bars are the only part of this page that is worthless when nobody is looking, and the
 * measurement that matters does not depend on them.
 */
function draw(): void {
    if (analyser === null) return;

    const bins = new Uint8Array(analyser.frequencyBinCount);
    analyser.getByteFrequencyData(bins);

    // Sixteen bands is enough to see that the graph is live, and few enough to read at a
    // glance. Averaged over the bins each band covers rather than sampled, so a quiet band
    // does not flicker on one loud bin.
    const perBand = Math.floor(bins.length / 16);
    levels.value = Array.from({ length: 16 }, (_, band) => {
        let total = 0;
        for (let bin = 0; bin < perBand; bin++) total += bins[band * perBand + bin];

        return total / perBand / 255;
    });

    peak.value = Math.max(peak.value, ...levels.value);
    frame = requestAnimationFrame(draw);
}

/**
 * Write one sample to the journal every 15 seconds, so the record shows WHEN a stall began
 * rather than only that one happened.
 *
 * A timer, knowing it will be throttled to roughly once a minute while the page is hidden —
 * which is not a flaw here but the useful case: two throttled lines across a two-minute lock
 * are exactly the evidence that says whether the audio clock was still moving at the one-minute
 * mark. The media events do the fine-grained work; this catches a freeze that fires no event at
 * all, which is precisely what a suspended graph looks like from the element's side.
 */
function sample(): void {
    log("sample", `audio at ${audioClock().toFixed(1)}s, context ${contextState.value}, peak ${peak.value.toFixed(2)}`);
}

/**
 * Record what the page saw as it went away and what it found on return — the measurement.
 *
 * Both halves are needed and only the second one can compute anything: while hidden, nothing
 * here runs. So going away stores two numbers and coming back subtracts them.
 */
function onVisibilityChange(): void {
    if (document.visibilityState === "hidden") {
        leftAt = { wall: Date.now(), audio: audioClock() };
        log("page hidden", `audio at ${audioClock().toFixed(1)}s`);

        return;
    }

    log("page visible", `audio at ${audioClock().toFixed(1)}s, context ${contextState.value}`);

    if (leftAt === null) return;

    const away = (Date.now() - leftAt.wall) / 1000;
    const advanced = audioClock() - leftAt.audio;
    // A tenth of slack for the seconds either side of the transition that nobody can account
    // for — the question is "did it keep up", not "did it keep up to the millisecond".
    verdict.value = { away, advanced, survived: advanced >= away * 0.9 };
    leftAt = null;
}

/**
 * Wire the element up and start playing.
 *
 * IN ROUTED MODE THIS IS THE ONE-WAY DOOR, and the reason the mode is a choice made before
 * pressing rather than a toggle: `createMediaElementSource()` redirects the element's output
 * into the graph permanently. Disconnecting afterwards produces silence rather than ordinary
 * playback, and calling it twice on the same element throws — so the element is keyed on
 * `mode` in the template and Vue builds a fresh one whenever the mode changes.
 *
 * The context is created and resumed inside this handler because that is where the user
 * gesture is: an AudioContext starts suspended, and resuming it anywhere else is refused.
 */
async function start(): Promise<void> {
    const element = audioRef.value;
    if (!element || running.value) return;

    startedAt = Date.now();
    journal.value = [];
    verdict.value = null;
    peak.value = 0;
    running.value = true;

    log("start pressed", `mode: ${mode.value}`);

    const on = <K extends keyof HTMLMediaElementEventMap>(event: K, handler: () => void): void => {
        element.addEventListener(event, handler);
        teardown.push(() => element.removeEventListener(event, handler));
    };

    // Media events keep firing in a hidden tab where timers do not, so these are what will
    // have recorded the moment of a stall by the time anybody reads the journal.
    for (const event of ["play", "playing", "pause", "waiting", "stalled", "suspend", "ended"] as const) {
        on(event, () => log(event, `audio at ${audioClock().toFixed(1)}s`));
    }
    on("error", () => log("error", element.error?.message ?? "unknown"));

    document.addEventListener("visibilitychange", onVisibilityChange);
    teardown.push(() => document.removeEventListener("visibilitychange", onVisibilityChange));

    const sampler = setInterval(sample, 15_000);
    teardown.push(() => clearInterval(sampler));

    if (mode.value === "routed") {
        context = new AudioContext();
        contextState.value = context.state;
        // THE SMOKING GUN, if there is one: a context that goes `suspended` while the page is
        // hidden and `running` again on return is the whole failure, recorded with its timing.
        const onStateChange = (): void => {
            contextState.value = context?.state ?? "—";
            log("context state", contextState.value);
        };
        context.addEventListener("statechange", onStateChange);
        teardown.push(() => context?.removeEventListener("statechange", onStateChange));

        analyser = context.createAnalyser();
        analyser.fftSize = 256;
        context.createMediaElementSource(element).connect(analyser);
        analyser.connect(context.destination);

        await context.resume();
        contextState.value = context.state;
        log("context resumed", context.state);
    }

    try {
        await element.play();
        log("playback started");
    } catch (error) {
        log("play refused", error instanceof Error ? error.message : String(error));
    }

    if (mode.value === "routed") draw();
}

/**
 * Stop everything and let go of the element.
 *
 * The context is CLOSED rather than suspended: a suspended context still owns the element's
 * output, so a second run in direct mode would be silent for a reason that has nothing to do
 * with the thing being measured.
 */
function stop(): void {
    if (frame !== null) cancelAnimationFrame(frame);
    frame = null;

    audioRef.value?.pause();
    for (const undo of teardown) undo();
    teardown = [];

    void context?.close();
    context = null;
    analyser = null;
    levels.value = [];
    peak.value = 0;
    contextState.value = "—";
    leftAt = null;
    running.value = false;

    log("stopped");
}

onBeforeUnmount(stop);
</script>

<template>
    <Head><title>Web Audio probe</title></Head>
    <headline glow>Web Audio probe</headline>
    <container>
        <div class="probe">
            <p class="probe__intro">
                One question: does audio survive the screen going off once the element is routed through an
                <code>AudioContext</code>? Pick a mode, press start, hear sound, then lock the phone for a minute or
                two and come back. <strong>Run DIRECT as a control</strong> — direct playback is known to survive, so
                if that fails too, the phone or the network is the problem and the routed result says nothing.
            </p>

            <p v-if="queueWarning" class="probe__warning">
                The play queue is loaded, so the player bar has an <code>&lt;audio&gt;</code> of its own on this page.
                Clear the queue before measuring — two elements make every reading here ambiguous.
            </p>

            <p v-if="!track" class="probe__warning">No music in the library, so there is nothing to play.</p>

            <template v-else>
                <p class="probe__track">
                    {{ track.name }}<span v-if="track.artist"> — {{ track.artist }}</span>
                    <span v-if="track.duration"> ({{ formatClock(track.duration) }})</span>
                </p>

                <!-- KEYED ON THE MODE, which is what makes switching modes safe: routing an
                     element cannot be undone, so a fresh element is the only honest way back
                     to direct. `preload="none"` for the reason the player bar documents. -->
                <audio :key="mode" ref="audio" :src="track.streamUrl" preload="none" />

                <div class="probe__controls">
                    <label><input v-model="mode" type="radio" value="routed" :disabled="running" /> routed</label>
                    <label><input v-model="mode" type="radio" value="direct" :disabled="running" /> direct</label>
                    <Button variant="primary" type="button" :disabled="running" @click="start">start</Button>
                    <Button variant="default" type="button" :disabled="!running" @click="stop">stop</Button>
                </div>

                <p class="probe__state">
                    context: {{ contextState }} · peak level: {{ peak.toFixed(2) }}
                    <span v-if="running && mode === 'routed' && peak === 0"> — no signal reaching the analyser yet</span>
                </p>

                <!-- Only proof that the graph is live, and only useful while visible. -->
                <div v-if="levels.length" class="probe__bars">
                    <span v-for="(level, band) in levels" :key="band" :style="{ height: `${level * 100}%` }" />
                </div>

                <p v-if="verdict" class="probe__verdict" :class="{ 'probe__verdict--bad': !verdict.survived }">
                    Away {{ verdict.away.toFixed(1) }}s — audio advanced {{ verdict.advanced.toFixed(1) }}s →
                    <strong>{{ verdict.survived ? "PLAYED THROUGH" : "STALLED" }}</strong>
                </p>

                <ol v-if="journal.length" class="probe__journal">
                    <li v-for="(entry, index) in journal" :key="index">
                        <span>{{ entry.at.toFixed(1) }}s</span> {{ entry.label }}
                        <em v-if="entry.detail">{{ entry.detail }}</em>
                    </li>
                </ol>
            </template>
        </div>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

/* A dev page, so it borrows the card's gutter and the app's ink and mints nothing of its own.
   Everything here is diagnostic rather than designed — if this page is still around when it
   stops being throwaway, it needs its own tokens. */
.probe {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}

.probe__intro,
.probe__track,
.probe__state {
    margin: 0;
}

.probe__warning {
    margin: 0;

    color: map.get(c.$c-error, "surface");
}

.probe__controls {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    gap: 1rem;
}

/* Sixteen bands on a fixed baseline. No transition, so no reduced-motion guard is needed:
   the height IS the reading, and easing it would be smoothing the data. */
.probe__bars {
    display: flex;
    align-items: flex-end;

    height: 6rem;
    gap: 2px;

    > span {
        min-height: 1px;
        flex: 1 1 0;

        background-color: map.get(c.$c-add-to-playlist, "intro");
    }
}

.probe__verdict {
    margin: 0;

    font-weight: 700;
}

.probe__verdict--bad {
    color: map.get(c.$c-error, "surface");
}

.probe__journal {
    padding-left: 1.5rem;
    margin: 0;

    font-variant-numeric: tabular-nums;

    > li > span {
        display: inline-block;

        min-width: 5ch;
    }
}
</style>
