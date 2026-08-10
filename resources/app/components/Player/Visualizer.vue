<script setup lang="ts">
/******************************************************************************
 * Visualizer
 * The EQ on the Now Playing page: a short row of bars driven by the frequency content of what is
 * actually playing, painted bottom-up through the retro ramp.
 *
 * IT IS THE ONLY THING IN THE APP THAT ASKS FOR AN ANALYSER, and asking is what wires one:
 * useAudioAnalyser routes the player's element through an AudioContext on the first activation
 * and never before, so a listener who never opens this page — or who opens it and never presses
 * play — is never routed. This asks only while something is PLAYING, which is what keeps that
 * second half true now that the row itself is permanent (see the watcher). That laziness is the
 * safety model, and it is argued in full in that composable — including the measurement behind
 * it (`/dev/audio-probe`, screen locked, away 215s, audio advanced 215s).
 *
 * REDUCED MOTION IS HONOURED BY NOT ASKING AT ALL. A bar chart repainting sixty times a second is
 * motion in the plainest sense, and unlike the loading spinner — which keeps turning because a
 * frozen spinner reads as broken — a frozen EQ reads as nothing at all. So under
 * `prefers-reduced-motion` this renders its idle baseline and never activates the analyser, which
 * has the happy side effect that such a reader's audio is never routed either.
 *
 * The preference is read with `matchMedia` rather than left to CSS, because the animation here is
 * not a transition or a keyframe — it is JavaScript writing a height per frame, and no media
 * query can stop that.
 *
 * THE RESTING ROW IS DELIBERATE, AND IT HAS TO BE VISIBLE. With nothing playing, or before the graph
 * is live, every bar stands at `RESTING_HEIGHT` rather than collapsing: an EQ of no height is an
 * invisible gap in the page, which reads as something that failed to load rather than as silence.
 * This is what the row shows while the player is merely PAUSED — the page keeps it mounted either way
 * (see NowPlayingPage's row 2) — and it is why `live` reads the PLAYER and not only the analyser: a
 * paused row must look like no signal, not like very quiet music.
 *
 * THE BAR COUNT STEPS DOWN WITH THE WIDTH, and the row asks CSS for it rather than deciding: bars
 * get thinner rather than fewer as the row narrows, and at 48 across a phone each one was a couple
 * of pixels — a smear rather than a spectrum. See `readBarCount` for where the number comes from.
 *****************************************************************************/
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { ANALYSER_DEFAULT_BANDS, setAnalyserBands, useAudioAnalyser } from "Composables/useAudioAnalyser";
import { usePlayerAudio } from "Composables/usePlayerAudio";

const { levels, isAnalysing, activate, deactivate } = useAudioAnalyser();
const { isPlaying } = usePlayerAudio();

/** The row itself, held only to read the bar count CSS declares on it. */
const root = ref<HTMLElement | null>(null);

/** How many bars to draw at this width — and therefore how many bands to ask the analyser for. */
const bars = ref(ANALYSER_DEFAULT_BANDS);

/**
 * Whether this browser is allowed to animate at all.
 *
 * Written positively (`no-preference`), like every motion decision in this repo, so motion is off
 * where the preference is unknown or unsupported rather than on.
 */
const mayAnimate = ref(false);

/**
 * True when the bars are showing a real reading rather than the baseline — which is what paints them
 * with the ramp instead of the flat "no signal" grey, and what `heights` trusts the levels on.
 *
 * IT ASKS THE PLAYER AS WELL AS THE ANALYSER, which the watcher below makes belt-and-braces rather
 * than strictly necessary: that watcher stops the readings on a pause, so `isAnalysing` already goes
 * false. The player is named anyway because it is what this row actually means — a lit EQ says
 * "you are hearing this" — and because `isAnalysing` is MODULE state: anything else that ever asks
 * the analyser for readings would otherwise light a paused row here, and a paused row wearing the
 * ramp at its resting height is a row of magenta stubs, which is precisely the "very quiet music" the
 * idle grey exists to be distinguishable from.
 */
const live = computed(() => mayAnimate.value && isAnalysing.value && isPlaying.value);

/**
 * How tall a bar stands with nothing to show, as a percentage of the row.
 *
 * IT WAS 2%, AND THAT WAS THE OWNER'S SECOND COMPLAINT (2026-08-10): 2% of a 56px strip is one pixel,
 * so "always shown" delivered an empty-looking box with a grey hairline in it — present in the DOM
 * and absent to the eye, which is the whole thing the baseline exists to avoid. A sixth of the row is
 * unmistakably a row of bars at rest, and still obviously not a reading: nothing music does holds
 * every band at exactly the same height.
 *
 * A constant here rather than a token because the value is a PERCENTAGE OF THE ROW written per frame
 * into an inline style — the same reason the analyser's heights are. Its floor in absolute pixels is
 * the `idle-bar` size token, which is what keeps a bar visible if the row is ever short.
 */
const RESTING_HEIGHT = 16;

/**
 * The heights to draw, as percentages — always `bars` long, so the row keeps its shape whether or
 * not there is anything to show.
 *
 * A floor of {@link RESTING_HEIGHT} rather than zero: see the banner on why an EQ must never
 * collapse. The readings are already 0–1 per band (useAudioAnalyser averages the bins), so this
 * only scales and clamps them. A band the analyser has not produced yet reads as the baseline,
 * which is what covers the single frame after the count changes.
 *
 * IT DRAWS READINGS ONLY WHILE `live`, which matters now that the row survives a pause: the loop
 * stops on one and leaves `levels` frozen wherever it got to, so trusting them would show a paused
 * player a grey spectrum held mid-note. Falling back to the resting height makes "stopped" one shape
 * rather than whatever the last frame happened to be.
 */
const heights = computed<number[]>(() =>
    Array.from({ length: bars.value }, (_, bar) => {
        const level = live.value ? (levels.value[bar] ?? 0) : 0;

        return Math.max(RESTING_HEIGHT, Math.round(level * 100));
    })
);

/**
 * Ask CSS how many bars this width wants, and tell the analyser to produce exactly that many bands.
 *
 * THE COUNT IS A CSS DECISION, read back rather than made here, because the widths it changes at are
 * the SCSS breakpoints and JavaScript cannot see them: writing 768 and 1440 into TypeScript would be
 * a second copy of a token to keep in step. So `sizes/components/_visualizer.scss` holds the three
 * counts, the stylesheet below publishes the one for this width as `--visualizer-bars`, and this
 * reads the computed value. The owner's brief — the widest row keeps 48, landscape 65%, portrait 50%
 * — therefore lives entirely in the token.
 *
 * WHERE NO STYLESHEET HAS BEEN APPLIED the default count draws instead of nothing, which is exactly
 * the unit-test case: happy-dom does not evaluate the scoped styles, so the property is empty and
 * `parseInt` gives NaN. It sets both the ref and the analyser on every call, and does not
 * short-circuit on an unchanged number, because the analyser's count is module state that outlives
 * this component — a second mount reading the same width still has to restate it.
 */
function readBarCount(): void {
    if (root.value === null) return;

    const declared = Number.parseInt(getComputedStyle(root.value).getPropertyValue("--visualizer-bars"), 10);

    bars.value = Number.isFinite(declared) && declared >= 1 ? declared : ANALYSER_DEFAULT_BANDS;
    setAnalyserBands(bars.value);
}

/*
 * THE ANALYSER FOLLOWS PLAYBACK, NOT THE MOUNT, and that is a measurement rather than a preference.
 * When the row was mounted only while playing, "mount" and "playing" were the same event; making it
 * permanent (2026-08-10) silently moved the first `createMediaElementSource` to the moment the page
 * OPENED — which, with no gesture yet in the document, means the resume is refused and the routing
 * is deferred onto the element's next `play`, i.e. into the very press that starts the music. That
 * cost showed up as a real flake: `now-playing.spec.ts`'s "says PAUSED at the last track" went from
 * 6/6 to 5/6 because playback had not begun by the time the pause arrived. Reading only while
 * something plays puts the routing back where it was, and costs nothing to look at — the readings
 * are all zeros while paused, so a paused row draws its baseline either way.
 *
 * It also restores the safety model in full: a listener who opens this page and never presses play
 * is never routed at all.
 */
watch(isPlaying, playing => {
    if (!mayAnimate.value) return;
    if (playing) activate();
    else deactivate();
});

onMounted(() => {
    readBarCount();
    // Browsers coalesce `resize` to once an animation frame, and the read is a computed-style lookup
    // that changes nothing unless the value did — so there is nothing here to throttle by hand.
    window.addEventListener("resize", readBarCount);

    mayAnimate.value = window.matchMedia("(prefers-reduced-motion: no-preference)").matches;
    // Arriving on the page mid-track: the watcher above only sees CHANGES, and `isPlaying` was
    // already true before this component existed.
    if (mayAnimate.value && isPlaying.value) activate();
});

// Only the reading stops. The routing stays — there is no way to un-route an element, and tearing
// the graph down under a playing track to save a few objects would trade silence for nothing.
// Balanced against whichever of the two `activate()` calls above ran: the watcher has already
// deactivated a paused player, so unmounting one must not deactivate twice.
onBeforeUnmount(() => {
    window.removeEventListener("resize", readBarCount);
    if (mayAnimate.value && isPlaying.value) deactivate();
});
</script>

<template>
    <!-- `aria-hidden`, and not reluctantly: this says nothing a listener cannot hear, and every
         reading it shows is already available as the track's own facts above it. Announcing a few
         dozen changing numbers would be noise in the most literal sense. -->
    <div ref="root" class="visualizer" :class="{ 'visualizer--live': live }" aria-hidden="true">
        <span v-for="(height, bar) in heights" :key="bar" class="visualizer__bar" :style="{ height: `${height}%` }" />
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;

/* A strip, not a panel — see the height token for why it stays short. The bars grow from the
   bottom edge, so the row is a flex line aligned to its end.

   IT ALSO DECLARES HOW MANY BARS THERE ARE, per breakpoint, and the component reads that back to
   build them (`readBarCount`). The count belongs on this side because what it steps at are these
   breakpoints: a `matchMedia` in TypeScript would be a second copy of `$breakpoints` to keep in
   step, where a custom property is the token doing the deciding and JavaScript merely obeying. */
.visualizer {
    --visualizer-bars: #{map.get(s.$c-visualizer, "bars", "base")};

    display: flex;
    align-items: flex-end;

    overflow: hidden;

    width: 100%;
    height: map.get(s.$c-visualizer, "height", "base");

    gap: map.get(s.$c-visualizer, "gap");

    @include m.mq("landscape") {
        --visualizer-bars: #{map.get(s.$c-visualizer, "bars", "landscape")};

        height: map.get(s.$c-visualizer, "height", "landscape");
    }

    @include m.mq("full") {
        --visualizer-bars: #{map.get(s.$c-visualizer, "bars", "full")};
    }
}

/* Each bar carries its OWN ramp, which is what makes the colour mean the same thing as the
   height: a short bar shows only the warm bottom of it, and only a loud one reaches the cool top.
   A single gradient across the row would be decoration; this is a reading.

   Rounded at the top only — a bar grows from a baseline, and a rounded foot makes it look like it
   is floating above one.

   NO TRANSITION ON `height`, deliberately, and this is the one place in the repo where that is
   not an oversight: the height is rewritten every animation frame from the analyser, so easing it
   would smooth the data rather than the motion — the bars would lag behind what you are hearing,
   which is the only thing this component is for. The motion decision is made in JavaScript
   instead, by not asking for readings at all under reduced motion (see the banner). */
.visualizer__bar {
    min-height: map.get(s.$c-visualizer, "idle-bar");
    flex: 1 1 0;

    background: linear-gradient(
        to top,
        map.get(c.$c-visualizer, "ramp-low"),
        map.get(c.$c-visualizer, "ramp-mid"),
        map.get(c.$c-visualizer, "ramp-high")
    );

    border-radius: map.get(s.$c-visualizer, "bar-radius") map.get(s.$c-visualizer, "bar-radius") 0 0;
}

/* No signal: a flat grey baseline rather than a dimmed ramp, because "nothing is playing" and
   "very quiet music" are different facts and should not look alike. This is now the row's RESTING
   state rather than a rarity — it is what a paused player looks like, the page having stopped
   unmounting the row (see `live`, which is why "paused" reaches this rule at all). Also what a
   reader who asked for reduced motion sees, permanently. */
.visualizer:not(.visualizer--live) .visualizer__bar {
    background: map.get(c.$c-visualizer, "idle");
}

/* NO GLOW UNDER THE ROW, removed 2026-08-10 at the owner's call. There was one — a single
   `drop-shadow` over the finished row rather than per-bar shadows, which was the right way to draw
   it — and it still read as a haze the bars sat in rather than as light coming off them. The ramp
   already says everything about level that the row has to say. Recorded rather than left silent so
   the next person to reach for a halo here knows it was tried. */
</style>
