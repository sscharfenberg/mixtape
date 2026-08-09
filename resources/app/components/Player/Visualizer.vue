<script setup lang="ts">
/******************************************************************************
 * Visualizer
 * The EQ on the Now Playing page: a short row of bars driven by the frequency content of what is
 * actually playing, painted bottom-up through the retro ramp.
 *
 * IT IS THE ONLY THING IN THE APP THAT ASKS FOR AN ANALYSER, and asking is what wires one:
 * useAudioAnalyser routes the player's element through an AudioContext on the first activation
 * and never before, so a listener who never opens this page is never routed. That laziness is the
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
 * THE BASELINE IS DELIBERATE. With nothing playing, or before the graph is live, every bar sits
 * at a couple of pixels rather than collapsing to nothing: an EQ of zero height is an invisible
 * gap in the page, which reads as something that failed to load rather than as silence.
 *****************************************************************************/
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { ANALYSER_BANDS, useAudioAnalyser } from "Composables/useAudioAnalyser";

const { levels, isAnalysing, activate, deactivate } = useAudioAnalyser();

/**
 * Whether this browser is allowed to animate at all.
 *
 * Written positively (`no-preference`), like every motion decision in this repo, so motion is off
 * where the preference is unknown or unsupported rather than on.
 */
const mayAnimate = ref(false);

/**
 * The heights to draw, as percentages — always `ANALYSER_BANDS` long, so the row keeps its shape whether or
 * not there is anything to show.
 *
 * A floor of a couple of percent rather than zero: see the banner on why an EQ must never
 * collapse. The readings are already 0–1 per band (useAudioAnalyser averages the bins), so this
 * only scales and clamps them.
 */
const heights = computed<number[]>(() =>
    Array.from({ length: ANALYSER_BANDS }, (_, bar) => {
        const level = mayAnimate.value ? (levels.value[bar] ?? 0) : 0;

        return Math.max(2, Math.round(level * 100));
    })
);

/** True when the bars are showing a real reading rather than the baseline. */
const live = computed(() => mayAnimate.value && isAnalysing.value);

onMounted(() => {
    mayAnimate.value = window.matchMedia("(prefers-reduced-motion: no-preference)").matches;
    if (mayAnimate.value) activate();
});

// Only the reading stops. The routing stays — there is no way to un-route an element, and tearing
// the graph down under a playing track to save a few objects would trade silence for nothing.
onBeforeUnmount(() => {
    if (mayAnimate.value) deactivate();
});
</script>

<template>
    <!-- `aria-hidden`, and not reluctantly: this says nothing a listener cannot hear, and every
         reading it shows is already available as the track's own facts above it. Announcing 48
         changing numbers would be noise in the most literal sense. -->
    <div class="visualizer" :class="{ 'visualizer--live': live }" aria-hidden="true">
        <span v-for="(height, bar) in heights" :key="bar" class="visualizer__bar" :style="{ height: `${height}%` }" />
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;

/* A strip, not a panel — see the height token for why it stays short. The bars grow from the
   bottom edge, so the row is a flex line aligned to its end. */
.visualizer {
    display: flex;
    align-items: flex-end;

    overflow: hidden;

    width: 100%;
    height: map.get(s.$c-visualizer, "height", "base");

    gap: map.get(s.$c-visualizer, "gap");

    @include m.mq("landscape") {
        height: map.get(s.$c-visualizer, "height", "landscape");
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
   "very quiet music" are different facts and should not look alike. Also what a reader who asked
   for reduced motion sees, permanently. */
.visualizer:not(.visualizer--live) .visualizer__bar {
    background: map.get(c.$c-visualizer, "idle");
}

/* The wash under the bars, and the whole of what makes the row read as lit rather than drawn.
   Applied only while live, so the idle baseline stays flat. `filter` rather than a per-bar
   box-shadow: 24 shadows overlap into a hard band where the bars are close together, where one
   blur over the finished row spills the way light does. */
.visualizer--live {
    filter: drop-shadow(0 0 #{map.get(s.$c-visualizer, "glow")} #{map.get(c.$c-visualizer, "glow")});
}
</style>
