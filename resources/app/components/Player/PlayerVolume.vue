<script setup lang="ts">
/******************************************************************************
 * PlayerVolume
 * The transport's volume control: an icon button in the player bar, and a popover
 * holding a vertical level slider with a mute button under it.
 *
 * BEHIND A POPOVER rather than a slider sitting in the bar, because the bar is
 * already the tightest row in the app — the title ellipsises there before anything
 * else does — and volume is set once and then left alone, unlike the timeline it
 * would have sat beside. It opens UPWARD for the only reason that matters: the bar
 * is pinned to the bottom of the viewport, so there is nothing below it to open into.
 *
 * TWO BUTTONS, TWO DIFFERENT QUESTIONS, which is why there are two glyph computeds
 * rather than one shared. The trigger answers "can I hear anything?" — `volume`, or
 * `volume_off` once the player is silent by either route (muted, or turned all the way
 * down; the composable keeps those separately undoable, `isSilent` collapses them).
 * The button inside answers "is the mute on?" — `mute` as the action offered,
 * `volume_off` as the state once taken. So at a level of zero the trigger goes quiet
 * while the inner button still reads `mute`, and that is right: nothing has been muted,
 * and pressing it still does something.
 *
 * It reads usePlayerVolume directly rather than taking props or emitting: the
 * composable is a module singleton, so going through PlayerBar would be
 * prop-drilling between two components that can both simply ask. Same call
 * PlayQueueMenu makes. The level is its own singleton rather than part of
 * usePlayerAudio, which owns the element and hands it over — see that module's note.
 *
 * Its own component rather than markup in PlayerBar because the bar is already the
 * longest file in the player and this brings a popover, a slider and two handlers
 * with it — none of which the bar has any reason to know about.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import PopOver from "Components/UI/PopOver.vue";
import { usePlayerVolume } from "Composables/usePlayerVolume";

const { t } = useI18n();
const { volume, isMuted, isSilent, setVolume, toggleMute } = usePlayerVolume();

/**
 * The level as a percentage, for the readout above the slider.
 *
 * Rounded rather than floored so a level a hair under a step still reads as that
 * step — the slider's own resolution is 1%, and showing 79% for a thumb sitting on
 * 80 looks like a bug in the control rather than in the arithmetic.
 */
const percent = computed<number>(() => Math.round(volume.value * 100));

/**
 * The trigger's glyph: what the bar shows at a glance.
 *
 * `isSilent`, not `volume === 0` — so a player muted at an audible level still reads as
 * silenced out here. The alternative leaves the bar claiming sound while the popover
 * says otherwise, and the trigger is the half you can see without opening anything.
 */
const triggerGlyph = computed<string>(() => (isSilent.value ? "volume_off" : "volume"));

/**
 * The mute button's glyph, which is a DIFFERENT question from the trigger's.
 *
 * This one reports the mute FLAG only: `mute` is the action offered, `volume_off` the
 * state once taken. So at a level of zero the trigger goes quiet while this button still
 * offers "mute" — correct, because nothing has been muted and pressing it would still do
 * something (it mutes, and un-muting then restores an audible level).
 */
const muteGlyph = computed<string>(() => (isMuted.value ? "volume_off" : "mute"));

/**
 * Handle the slider.
 *
 * `input`, not `change` — unlike the timeline, where every intermediate value would
 * be a Range request, following the drag here is free and a volume that only moves
 * on release is unusable for finding a level by ear.
 */
const onInput = (event: Event): void => {
    setVolume(Number((event.target as HTMLInputElement).value));
};
</script>

<template>
    <div class="player-volume">
        <pop-over
            :icon="triggerGlyph"
            reference="playerVolume"
            class-string="popover-button--rounded popover-button--subtle player-volume__trigger"
            :aria-label="t('player.bar.volume')"
            width="auto"
        >
            <div class="player-volume__panel">
                <p class="player-volume__readout">{{ percent }}%</p>

                <!-- The rail is DRAWN and the input laid transparently over it, exactly as
                     PlayerTimeline does — and for a reason beyond consistency: Chromium has
                     no equivalent of Firefox's `::-moz-range-progress`, so a level fill
                     asked of the native track renders in Firefox and nowhere else. Drawing
                     it makes the fill the same in every engine.

                     The input stays a real <input type="range">, for the reason the timeline
                     gives: a div with pointer handlers means re-implementing keyboard
                     support, drag capture, touch and the ARIA slider contract. `orient` is
                     the Firefox spelling of vertical and is ignored elsewhere; the CSS
                     `writing-mode` does that work in Chromium. -->
                <div class="player-volume__rail">
                    <span class="player-volume__level" :style="{ height: `${percent}%` }" />
                    <!-- `step` IS THE KEYBOARD STEP, which is why it is 5% and not the 1% it
                         was until 2026-08-07. usePlayerShortcuts stands aside for a focused
                         range input — correctly, since the arrows belong to the control the
                         reader is on — so this attribute is what ↑/↓ do while the slider has
                         focus, and it has to be the same figure as that composable's
                         VOLUME_STEP or the same key moves the level by different amounts
                         depending on what happens to be focused. That mismatch is what the
                         owner reported.
                         It costs the DRAG its fine resolution, since a native range has one
                         step for both: dragging now lands on multiples of 5%. That is the
                         cheaper half — twenty positions is more than most hardware offers,
                         and a level nobody can name is not worth a pixel of precision. -->
                    <input
                        type="range"
                        class="player-volume__input"
                        min="0"
                        max="1"
                        step="0.05"
                        orient="vertical"
                        :value="volume"
                        :aria-label="t('player.bar.volumeLevel')"
                        :aria-valuetext="`${percent}%`"
                        @input="onInput"
                    />
                </div>

                <button
                    type="button"
                    class="player-volume__mute"
                    :aria-label="isMuted ? t('player.bar.unmute') : t('player.bar.mute')"
                    :aria-pressed="isMuted"
                    @click="toggleMute"
                >
                    <icon :name="muteGlyph" :size="1" />
                </button>
            </div>
        </pop-over>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

$rail: map.get(s.$c-player-volume, "rail");
$thumb: map.get(s.$c-player-volume, "thumb");

.player-volume {
    display: inline-flex;
    align-items: center;

    /* Sizes the trigger's glyph DOWN to match prev/next. PopOver takes no icon-size prop
       and Icon defaults to step 2 ("medium"), which is the step the play/pause button uses
       — so out of the box this button rendered as large as the primary control and, now
       that every control wears a pill, visibly bigger than its neighbours. The transport's
       secondary buttons pass `:size="1"`, so this reads the same token that class resolves
       to rather than restating a length. `--icon-size` is Icon's own hook, which is how
       `popover-button--tiny` does the same thing. */
    :deep(.popover-button .icon) {
        --icon-size: #{map.get(s.$c-icon, "small")};
    }

    /* THE POPOVER OPENS UPWARD, and this override is the whole reason the wrapper element
       exists. PopOver's shared style pins the dialog UNDER its trigger
       (`inset: anchor(bottom) anchor(right) auto auto`), which is right everywhere else in
       the app and wrong for a bar fixed to the bottom of the viewport.

       Without it the panel is not catastrophically misplaced — `position-try-fallbacks`
       does flip it above — but it lands about 2px INTO the button, because the fallback
       flips the anchoring while the margin stays on the edge it was written for. Measured,
       not guessed: 854.97 against a trigger top of 852. Anchoring the bottom edge to the
       trigger's top explicitly makes both the side and the gap deterministic, and lets the
       panel grow upward as its contents get taller. The E2E spec fails on that 2px.

       `:deep()` because the dialog carries PopOver's scope attribute, not this component's,
       so a plain descendant selector would compile to one that matches nothing. */
    :deep(.popover-content) {
        inset: auto anchor(right) anchor(top) auto;

        margin-block: 0 map.get(s.$c-player-volume, "gap");
    }
}

.player-volume__panel {
    display: flex;
    align-items: center;
    flex-direction: column;

    gap: map.get(s.$c-player-volume, "gap");
}

/* Reserves the width of its LONGEST value rather than its current one, so the popover
   stops resizing as the level crosses 99% → 100%. `tabular-nums` is what makes that
   reservation hold: with proportional figures a 1 is narrower than a 0 and the text would
   still shift inside the box even once the box stopped moving. Centred, because the
   readout is the panel's heading — left-aligned in an over-wide box it would sit off to
   one side of the slider it labels. */
.player-volume__readout {
    min-width: map.get(s.$c-player-volume, "readout-min");
    margin: 0;

    color: map.get(c.$c-player-volume, "readout");

    font-size: 0.85em;
    font-variant-numeric: tabular-nums;
    text-align: center;
}

/* The drawn rail: the whole travel, unfilled. `position: relative` so the level and the
   input can both be laid against it. */
.player-volume__rail {
    position: relative;

    width: $rail;
    height: map.get(s.$c-player-volume, "length");

    background-color: map.get(c.$c-player-volume, "rail");

    border-radius: map.get(s.$c-player-volume, "radius");
}

/* The level, growing UP from the bottom — its height is bound from the component, the
   one number in this control that has to come from JS rather than CSS. */
.player-volume__level {
    position: absolute;
    inset: auto 0 0;

    background-color: map.get(c.$c-player-volume, "level");

    border-radius: inherit;
}

/* The real control, invisible over the drawing. `hit` WIDE rather than the rail's width
   for the reason the timeline's token note gives: 6px is what the eye wants and an
   unhittable target, so the drawing stays thin while the target stays thumb-sized.

   `writing-mode` plus `direction: rtl` is the standard way to turn a range vertical, and
   the pair is required: the writing mode rotates it, and rtl is what puts zero at the
   BOTTOM — without it the slider runs upside down and a listener drags toward silence to
   get louder. `appearance: none` first, per the note in PlayerTimeline: WebKit ignores
   thumb styling until it is set. */
.player-volume__input {
    position: absolute;
    inset: 0 50% auto auto;
    writing-mode: vertical-lr;

    width: map.get(s.$c-player-volume, "hit");
    height: 100%;
    margin: 0;

    appearance: none;

    background: none;

    direction: rtl;

    cursor: pointer;
    translate: 50% 0;

    /* Vendor thumb pseudo-elements CANNOT be combined into one selector — a rule naming
       both is dropped whole by both engines, which is why this reads as duplication. The
       tracks stay transparent: the rail and level above them are the drawing. */

    &::-webkit-slider-thumb {
        width: $thumb;
        height: $thumb;
        border: 0;

        appearance: none;

        background-color: map.get(c.$c-player-volume, "thumb");

        // No centring offset needed: the input is `hit` wide and centred on the rail, so
        // the thumb is already centred within its own track.
        border-radius: 50%;
        box-shadow: 0 0 0.4em 0.1em map.get(c.$c-player-volume, "glow");
    }

    &::-moz-range-thumb {
        width: $thumb;
        height: $thumb;
        border: 0;

        background-color: map.get(c.$c-player-volume, "thumb");

        border-radius: 50%;
        box-shadow: 0 0 0.4em 0.1em map.get(c.$c-player-volume, "glow");
    }
}

/* Deliberately the player bar's control colours rather than tokens of its own: this
   button sits inside the transport's own popover and has to read as one of its
   controls. A second colour set for one look is two things to keep in step. */
.player-volume__mute {
    display: inline-flex;
    align-items: center;

    padding: 0;
    border: 0;

    background: none;
    color: map.get(c.$c-player-bar, "control");

    cursor: pointer;

    @media (prefers-reduced-motion: no-preference) {
        transition: color ti.$c-player-bar linear;
    }

    &:hover,
    &[aria-pressed="true"] {
        color: map.get(c.$c-player-bar, "control-hover");
    }
}
</style>
