<script setup lang="ts">
/******************************************************************************
 * OptionBubbles
 * A small icon-only picker: one glyph per option, with a single pill sliding behind
 * whichever is chosen. Two or three options, no visible text — the shape the header's
 * light / dark / system switch established, generalised so the player's settings can
 * use it twice.
 *
 * RADIOS, not buttons, and that is the whole reason this is not a pair of toggles.
 * A native radiogroup gives arrow-key navigation between the options for free, moving
 * focus AND selection the way a keyboard user expects; buttons would need every one of
 * those keys re-implemented, and would announce as two unrelated controls rather than
 * one choice with two answers. The inputs stay in the DOM and are hidden by clipping
 * rather than `display: none`, which would take them out of the tab order with them.
 *
 * The pill is ONE element behind the row rather than a background on the chosen option,
 * so it can travel: a background swap cannot animate from one element to another, and
 * the movement is what makes the control read as one choice rather than two lamps. Its
 * width and offset come from `--count` / `--selected` because the count is a prop.
 *
 * Icon-only controls have to say what they are twice: `aria-label` on each input for
 * assistive tech, and a tooltip for everyone else — the same pairing WidgetModeToggle
 * uses, for the same reason (a glyph is not a name).
 *
 * ThemeSwitch predates this and still carries its own copy of the pattern. It was left
 * alone deliberately: it has no tests of its own, its three-option pill is driven by
 * `:has(input:nth-of-type(n):checked)` rather than a prop, and rewriting the colour
 * scheme picker was not part of adding a player setting. It is the obvious next
 * adopter, not a silent one.
 *****************************************************************************/
import { computed } from "vue";
import Icon from "Components/UI/Icon.vue";

/** One selectable option: the value it stands for, the glyph that draws it, the name it carries. */
export type BubbleOption = {
    /** The value reported when this option is chosen. */
    value: string;
    /** Icon name from the sprite — the option's only visible content. */
    icon: string;
    /** Human name, used for both the tooltip and the accessible label. */
    label: string;
};

const props = defineProps<{
    /** The currently selected value. Anything not among the options leaves the pill on the first. */
    modelValue: string;
    /** The options, left to right. */
    options: BubbleOption[];
    /**
     * Radio `name`, and the prefix of each input's id — so it has to be unique on the
     * page. Two groups sharing a name would form ONE group, and choosing in the second
     * would silently clear the first.
     */
    name: string;
    /** Accessible name for the group as a whole (its `aria-label`). */
    label: string;
}>();

const emit = defineEmits<{
    /** The chosen value changed — `v-model` on this component. */
    "update:modelValue": [value: string];
}>();

/**
 * Where the pill sits, as an option index.
 *
 * Falls back to 0 rather than -1 for an unknown value: the pill has to be somewhere,
 * and parking it off the left edge looks like a rendering fault rather than a state.
 */
const selectedIndex = computed<number>(() => {
    const index = props.options.findIndex(option => option.value === props.modelValue);

    return index === -1 ? 0 : index;
});

/** The input id for an option — `name` is already unique per group, so this is too. */
function optionId(value: string): string {
    return `${props.name}-${value}`;
}
</script>

<template>
    <div
        class="option-bubbles"
        role="radiogroup"
        :aria-label="label"
        :style="{ '--count': options.length, '--selected': selectedIndex }"
    >
        <span class="option-bubbles__pill" aria-hidden="true" />
        <template v-for="option in options" :key="option.value">
            <input
                :id="optionId(option.value)"
                type="radio"
                :name="name"
                :value="option.value"
                :checked="option.value === modelValue"
                :aria-label="option.label"
                @change="emit('update:modelValue', option.value)"
            />
            <label
                v-tooltip="option.label"
                :for="optionId(option.value)"
                class="option-bubbles__item"
            >
                <icon :name="option.icon" :size="1" />
            </label>
        </template>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

.option-bubbles {
    display: flex;
    position: relative;
    justify-content: space-between;

    /* The travelling pill. Behind the row (`z-index` "background") rather than inside the
       chosen option, so one element can move between them — see the component banner. Its
       geometry is entirely `--count` / `--selected`: no per-count CSS to keep in step with
       however many options a caller passes. */
    &__pill {
        display: block;
        position: absolute;
        top: 0;
        left: calc(var(--selected) * 100% / var(--count));
        z-index: map.get(z.$index, "background");

        width: calc(100% / var(--count));
        height: 100%;

        background-color: map.get(c.$c-option-bubbles, "background-selected");
        border-radius: map.get(s.$c-option-bubbles, "radius");

        @media (prefers-reduced-motion: no-preference) {
            transition: left ti.$c-option-bubbles linear;
        }
    }

    /* Clipped rather than `display: none`: the inputs are what carry focus, arrow-key
       navigation and the group semantics, and none of that survives being removed from
       the layout. This is the standard visually-hidden recipe. */
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
        display: flex;
        align-items: center;
        justify-content: center;
        flex-grow: 1;

        padding: map.get(s.$c-option-bubbles, "padding");

        color: map.get(c.$c-option-bubbles, "surface");

        line-height: 1;

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition: color ti.$c-option-bubbles linear;
        }

        /* The ring goes on the LABEL, because the input it belongs to is clipped to a
           pixel — an outline there would be invisible. `:focus-visible` keeps it to
           keyboard use. */
        input:focus-visible + & {
            outline: 2px solid map.get(c.$c-option-bubbles, "surface-selected");
            outline-offset: -2px;
        }

        input:checked + & {
            color: map.get(c.$c-option-bubbles, "surface-selected");
        }
    }
}
</style>
