<script setup lang="ts">
/******************************************************************************
 * PlayerVolumeHud
 * The level, big, in the middle of the screen, for a couple of seconds after it
 * changes — a head-up display rather than a control: it shows a number and takes no
 * input at all.
 *
 * IT EXISTS FOR THE KEYBOARD. ↑/↓ change the volume from anywhere on the page
 * (`usePlayerShortcuts`), and until now the only thing that moved was a slider inside a
 * closed popover — so the one gesture with no visible control attached to it was also
 * the one with no feedback. A drag on that slider gets the box too, which costs
 * nothing: the popover's own readout is there, but the pointer is usually on top of it.
 *
 * TELEPORTED TO <body>, AND THAT IS NOT TIDINESS. `PlayerBar` — the obvious parent, and
 * where this is written — carries the shared `backdrop-filter` blur to frost itself, and a
 * filtered element becomes the CONTAINING BLOCK for its `position: fixed` descendants.
 * Left in the bar, "the middle of the viewport" would resolve to the middle of a 60px
 * strip along the bottom edge. The same trap catches anything else fixed that the bar
 * might one day render.
 *
 * IT WATCHES THE PERCENTAGE, not the level, which is what makes MUTING show up here
 * too: `M` is a volume gesture with even less on screen to see than the arrows, and it
 * moves the figure from 79% to 0 and back. The one gesture that shows nothing is a key
 * pressed at a ceiling — ↑ at 100% clamps to the level it already had, so nothing
 * changed and nothing is announced. That is honest, and by then the box is on screen
 * anyway from the press before it.
 *
 * NO ARIA. The box is `aria-hidden`, because a screen reader that announced every step
 * of a five-press climb would be reading noise — and the slider it mirrors already
 * carries `aria-valuetext`, which is what answers "how loud is it?" for anyone
 * navigating the control rather than the page.
 *****************************************************************************/
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { usePlayerVolume } from "Composables/usePlayerVolume";

/**
 * How long the box stays after the last change.
 *
 * Long enough to read after a single press, short enough that it is gone before it
 * becomes furniture — and comfortably longer than the gap between presses when someone
 * is walking the level up, so a climb reads as one box counting rather than a box
 * flickering per key.
 */
const VISIBLE_MS = 2000;

const { volume, isMuted, changes } = usePlayerVolume();

/** Whether the box is on screen right now — driven only by the watcher below. */
const isVisible = ref<boolean>(false);

/** The pending hide, kept so a second change can restart it rather than stack a second one. */
let hideTimer: ReturnType<typeof setTimeout> | undefined;

/**
 * What the box says.
 *
 * Muted reads 0 because that is what is audible, which is the question this answers —
 * the composable keeps the mute flag and a level of zero separately undoable, and
 * nothing about that distinction belongs on a one-number readout.
 *
 * Rounded rather than floored, the same way the popover's readout is: the arrow keys
 * step 5% off a stored float, so a level lands on 0.7999999 as readily as on 0.8, and
 * showing 79% for it looks like a bug in the control rather than in the arithmetic.
 */
const percent = computed<number>(() => (isMuted.value ? 0 : Math.round(volume.value * 100)));

/**
 * Show the box, and restart the clock.
 *
 * IT WATCHES THE GESTURE COUNTER, NOT THE PERCENTAGE, and that distinction is the whole of
 * a bug this shipped with: the level is also written when the stored one is RESTORED, on
 * the first bind — which happens in PlayerBar's `onMounted`, after this component's setup,
 * so the watcher was already listening and every page load opened with a volume box.
 * `usePlayerVolume.changes` ticks only where somebody asked for a change, which is exactly
 * what this readout is about.
 */
watch(changes, () => {
    isVisible.value = true;

    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => {
        isVisible.value = false;
    }, VISIBLE_MS);
});

// The bar unmounts whenever the queue is emptied, and a timer left running would write
// to a ref nobody is rendering any more.
onBeforeUnmount(() => clearTimeout(hideTimer));
</script>

<template>
    <Teleport to="body">
        <Transition name="player-volume-hud">
            <p v-if="isVisible" class="player-volume-hud" aria-hidden="true">{{ percent }}%</p>
        </Transition>
    </Teleport>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

/* Centred by translating half its own size back, rather than by a flex parent: this is
   one element teleported to <body>, so giving it a full-viewport parent to centre it in
   would mean a second element covering the page — which is exactly the thing
   `pointer-events` below has to keep harmless.

   CLICK-THROUGH is the owner's requirement and the right default for a readout: it
   appears unbidden, over the middle of whatever the reader is doing, and a box that
   swallowed a click on the row behind it would be a bug that only ever bites during the
   two seconds it is up. */
.player-volume-hud {
    position: fixed;
    top: 50%;
    left: 50%;
    z-index: z.$c-player-volume-hud;

    min-width: map.get(s.$c-player-volume-hud, "min-width");
    padding: map.get(s.$c-player-volume-hud, "padding");
    margin: 0;

    background-color: map.get(c.$c-player-volume-hud, "background");
    color: map.get(c.$c-player-volume-hud, "surface");

    border-radius: map.get(s.$c-player-volume-hud, "radius");

    font-size: map.get(s.$c-player-volume-hud, "font-size");
    font-variant-numeric: tabular-nums;
    text-align: center;

    pointer-events: none;
    translate: -50% -50%;
}

/* Only the LEAVE is animated, and only under `no-preference`. Arriving is feedback for
   a key that has just been pressed, so it has to be there instantly — an ease-in would
   still be arriving when the next press lands. Going is the box's own doing, and a fade
   is what says "this timed out" rather than "this blinked". With reduced motion asked
   for, there is no transition at all and Vue removes it on the next frame. */
@media (prefers-reduced-motion: no-preference) {
    .player-volume-hud-leave-active {
        transition: opacity ti.$c-player-volume-hud ease-out;
    }

    .player-volume-hud-leave-to {
        opacity: 0;
    }
}
</style>
