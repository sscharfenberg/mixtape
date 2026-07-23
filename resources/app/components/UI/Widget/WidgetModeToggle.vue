<script setup lang="ts">
/******************************************************************************
 * WidgetModeToggle
 * The latest / random switch in a music Widget's title strip — one segmented
 * pill split into two icon options (recent on the left, shuffle on the right).
 * The native radios are visually hidden but stay focusable, so native arrow
 * keys still move selection; each label is a clickable segment. Colours track
 * the SiteMenuLinks desktop links so the two controls align: an unselected
 * segment is the normal link, the checked one the current-link highlight, and
 * hover swaps fill/icon. Two-way binds the active mode via v-model; `name`
 * groups the pair, so every toggle on the page needs a unique one.
 *****************************************************************************/
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import type { WidgetMode } from "Types/music";

const { t } = useI18n();

defineProps<{
    /** Unique radio-group name — distinct per widget so the page's toggles don't collide. */
    name: string;
}>();

const mode = defineModel<WidgetMode>({ required: true });
</script>

<template>
    <span class="widget-mode-toggle" role="radiogroup" :aria-label="t('music.mode.label')">
        <input :id="name + '-latest'" v-model="mode" type="radio" :name="name" value="latest" />
        <label :for="name + '-latest'" class="widget-mode-toggle__item" :aria-label="t('music.mode.latest')">
            <icon name="recent" :size="2" />
        </label>
        <input :id="name + '-random'" v-model="mode" type="radio" :name="name" value="random" />
        <label :for="name + '-random'" class="widget-mode-toggle__item" :aria-label="t('music.mode.random')">
            <icon name="shuffle" :size="2" />
        </label>
    </span>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.widget-mode-toggle {
    display: inline-flex;

    // one pill: a single frame with the two segments flush inside it. overflow
    // clips each segment's fill to the frame's rounded corners; the frame border
    // is the SiteMenuLinks normal-link border (`surface`); the frame fill is the
    // unselected/normal background, which the left (unselected) segment shows
    // through. Sits at the trailing edge of the widget title flex row.
    overflow: hidden;
    border: map.get(s.$c-widget-mode-toggle, "border") solid map.get(c.$c-widget-mode-toggle, "surface");
    margin-inline-start: auto;

    background: map.get(c.$c-widget-mode-toggle, "background");
    border-radius: map.get(s.$c-widget-mode-toggle, "radius");

    font-size: 0.75em;
    font-weight: 400;

    // radios stay in the DOM but visually hidden — still focusable/tabbable for
    // keyboard + screen-reader users (unlike display:none). Native arrow keys move
    // focus AND selection within the group, which is what drives the mode change.
    input {
        position: absolute;

        overflow: hidden;

        width: 1px;
        height: 1px;
        padding: 0;
        border: 0;
        margin: -1px;
        clip-path: inset(50%);

        white-space: nowrap;
    }

    &__item {
        display: inline-flex;
        align-items: center;

        padding: map.get(s.$c-widget-mode-toggle, "padding");

        color: map.get(c.$c-widget-mode-toggle, "surface");

        line-height: 1;

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                color ti.$c-widget-mode-toggle linear,
                background-color ti.$c-widget-mode-toggle linear;
        }

        // hover on the unselected segment — swap fill and icon (accent fill, base icon).
        &:hover {
            background-color: map.get(c.$c-widget-mode-toggle, "surface");
            color: map.get(c.$c-widget-mode-toggle, "background");
        }

        // visible keyboard focus ring (:focus-visible → keyboard only, not mouse
        // clicks); inset so the frame's overflow:hidden doesn't clip it.
        // currentcolor = the segment's own accent, so it reads on either state.
        input:focus-visible + & {
            outline: 2px solid currentcolor;
            outline-offset: -2px;
        }

        // checked segment — the SiteMenuLinks "current link" highlight; same swap on hover.
        input:checked + & {
            background-color: map.get(c.$c-widget-mode-toggle, "background-selected");
            color: map.get(c.$c-widget-mode-toggle, "surface-selected");
        }

        input:checked + &:hover {
            background-color: map.get(c.$c-widget-mode-toggle, "surface-selected");
            color: map.get(c.$c-widget-mode-toggle, "background-selected");
        }
    }
}
</style>
