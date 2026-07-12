<script setup lang="ts">
/******************************************************************************
 * Checkbox
 * Ported from cantrip.me's Form/Checkbox (the checkbox, not the Switch) and
 * restyled to the retro / synthwave palette. A visually-hidden native
 * <input type="checkbox"> paired with a <label> that IS the box: the tick is
 * the label's ::after (two borders rotated 45° into a checkmark), and the
 * indeterminate state swaps it for a horizontal bar. Checking the box lights it
 * up in the neon cyan (the same AA-tuned hue as the neon Button) with a soft
 * glow. Two-root component on purpose — the <input> and <label> are adjacent
 * siblings so the `input:checked + label` styling works.
 *
 * Adapted for mixtape: cantrip's `checkedInitially` prop + `@change` emit is
 * replaced by `v-model` (defineModel), matching FormInput. The visible text
 * label is expected to come from a wrapping <form-row> (whose `for-id` must
 * equal `refId`), which also lines the box up in the input column with the
 * fields and the submit button. The `label` prop is only an off-screen
 * accessible name for standalone use — leave it empty when a form-row names it,
 * so the control isn't labelled twice.
 *****************************************************************************/
import { useId, useTemplateRef, watch } from "vue";

const props = withDefaults(
    defineProps<{
        /** id + name for the input; a wrapping form-row's `for-id` must match it. */
        refId?: string;
        /** Disabled — dims the box and blocks toggling. */
        disabled?: boolean;
        /** Indeterminate ("some selected") — a native DOM property, so set via ref. */
        indeterminate?: boolean;
        /** Off-screen accessible name; leave empty when a form-row already labels it. */
        label?: string;
    }>(),
    {
        disabled: false,
        indeterminate: false,
        label: ""
    }
);

/** Checked state, two-way bound with the parent. */
const model = defineModel<boolean>();

/** Stable id for the input ⇄ label pairing; falls back to a generated one. */
const inputId = props.refId || useId();

// `indeterminate` has no HTML attribute (it's a DOM property only), so mirror
// the prop onto the element whenever it changes.
const inputRef = useTemplateRef<HTMLInputElement>("inputRef");
watch(
    () => props.indeterminate,
    value => {
        if (inputRef.value) inputRef.value.indeterminate = value;
    },
    { flush: "post", immediate: true }
);
</script>

<template>
    <input
        :id="inputId"
        ref="inputRef"
        v-model="model"
        type="checkbox"
        :name="inputId"
        :disabled="disabled"
    />
    <label :for="inputId">{{ label }}</label>
</template>

<style scoped lang="scss">
/**
 * Colours / sizes / timing come from the contextual Abstracts tokens
 * (c.$c-checkbox / s.$c-checkbox / ti.$c-checkbox). Wrapped in `@layer
 * components` like FormInput so it sits in the components cascade layer.
 */
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

@layer components {
    // the native input is visually hidden; the adjacent label is the box.
    input {
        position: absolute;

        opacity: 0;

        width: 0;
        height: 0;

        // lit state (checked or indeterminate): neon fill, matching border, glow
        &:checked + label,
        &:indeterminate + label {
            background-color: map.get(c.$c-checkbox, "background-checked");
            border-color: map.get(c.$c-checkbox, "border-checked");

            // a tight edge glow plus a wider soft halo — the same layered neon
            // spread as the glowing-border and the neon Button, scaled to the box.
            box-shadow:
                0 0 0.4em 0 map.get(c.$c-checkbox, "background-checked"),
                0 0 1.25em 0.15em map.get(c.$c-checkbox, "background-checked");

            &::after {
                opacity: 1;
            }
        }

        &:checked + label::after {
            transform: rotate(45deg) scale(1);
        }

        // indeterminate: a horizontal bar instead of the tick
        &:indeterminate + label::after {
            width: 50%;
            height: 0;
            border-right: 0;
            border-bottom: map.get(s.$c-checkbox, "border") solid map.get(c.$c-checkbox, "checkmark");
            margin-bottom: 0;

            transform: none;
        }

        // the real input is invisible, so surface keyboard focus on the box.
        &:focus-visible + label {
            outline: map.get(s.$c-checkbox, "border") solid map.get(c.$c-checkbox, "border-checked");
            outline-offset: 2px;
        }

        &:disabled + label {
            opacity: 0.5;

            cursor: not-allowed;
        }
    }

    label {
        display: flex;
        position: relative;
        align-items: center;
        justify-content: center;

        width: map.get(s.$c-checkbox, "size");
        height: map.get(s.$c-checkbox, "size");
        border: map.get(s.$c-checkbox, "border") solid map.get(c.$c-checkbox, "border");

        background-color: map.get(c.$c-checkbox, "background");
        border-radius: map.get(s.$c-checkbox, "radius");

        text-indent: -9999px;

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                background-color ti.$c-checkbox ease,
                border-color ti.$c-checkbox ease,
                box-shadow ti.$c-checkbox ease;
        }

        // the tick: two borders of a box, rotated 45° into a checkmark.
        &::after {
            position: absolute;

            opacity: 0;

            width: 30%;
            height: 55%;
            border-right: map.get(s.$c-checkbox, "border") solid map.get(c.$c-checkbox, "checkmark");
            border-bottom: map.get(s.$c-checkbox, "border") solid map.get(c.$c-checkbox, "checkmark");
            margin-bottom: 10%;

            transform: rotate(45deg) scale(0.5);

            content: "";

            @media (prefers-reduced-motion: no-preference) {
                transition:
                    opacity ti.$c-checkbox ease,
                    transform ti.$c-checkbox ease;
            }
        }
    }
}
</style>
