<script setup lang="ts">
/******************************************************************************
 * RadioButton
 * Ported from cantrip.me's Form/Radio/RadioButton. A single radio option: a
 * native <input type="radio"> paired with a <label> that draws the box + dot
 * and (optionally) an icon + text label. Always rendered inside a
 * RadioButtonGroup, which owns the shared `name` and forwards this
 * component's `change` event.
 *****************************************************************************/
import Icon from "Components/UI/Icon.vue";

defineProps<{
    /** Form value submitted when this option is selected. */
    value: string;
    /** Shared `name` attribute for the radio group this option belongs to. */
    name: string;
    /** Visible label text. When empty, only the box (+ icon) is rendered. */
    label?: string;
    /** Whether this option is currently selected. */
    checked: boolean;
    /** Optional icon name rendered alongside the label. */
    icon?: string;
}>();

const emit = defineEmits<{
    change: [event: Event];
}>();

/** Forward the native change event to the parent RadioButtonGroup. */
function onChange(event: Event): void {
    emit("change", event);
}
</script>

<template>
    <label :for="`${name}_${value}`" class="form-radio">
        <input
            :id="`${name}_${value}`"
            type="radio"
            :name="name"
            :value="value"
            :checked="checked ?? null"
            @change="onChange"
        />
        <span class="form-radio__button" />
        <span v-if="label?.length" class="form-radio__label">
            <icon v-if="icon?.length" :name="icon" />
            {{ label }}
        </span>
    </label>
</template>

<style scoped lang="scss">
/**
 * Box / colour come from the contextual Abstracts tokens: the box itself
 * reuses c.$c-input / s.$c-input (a radio option IS an input-family control),
 * the checked dot uses the radio-specific c.$c-radio. Wrapped in `@layer
 * components` like FormInput / Checkbox.
 */
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

@layer components {
    .form-radio {
        display: flex;

        border: map.get(s.$c-input, "border") solid map.get(c.$c-input, "border");

        background-color: map.get(c.$c-input, "background");
        color: map.get(c.$c-input, "surface");
        border-radius: map.get(s.$c-input, "radius");

        line-height: map.get(s.$c-input, "line-height");

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                background-color ti.$c-input linear,
                color ti.$c-input linear,
                border-color ti.$c-input linear;
        }

        &__button {
            display: flex;
            position: relative;
            align-items: center;

            padding: 0.75ex 1ch 0.75ex 1.5ch;
            border-right: map.get(s.$c-input, "border") solid map.get(c.$c-input, "border");

            &::before {
                display: block;

                width: 24px;
                height: 24px;
                border: map.get(s.$c-input, "border") solid map.get(c.$c-input, "border");

                background: transparent;

                border-radius: map.get(s.$c-input, "radius");

                content: "";
            }
        }

        &__label {
            display: flex;
            align-items: center;

            padding: 0.75ex 1.5ch;
            gap: 1ch;
        }

        input[type="radio"] {
            $inset: #{map.get(s.$c-input, "border") * 2};
            visibility: hidden;

            width: 0;
            height: 0;

            + .form-radio__button::after {
                display: block;
                position: absolute;

                top: 50%;
                left: calc(1.5ch + $inset);

                width: 0;
                height: 0;
                transform: translate(0, -50%);

                background: radial-gradient(circle at 6px 6px, map.get(c.$c-radio, "inner"), map.get(c.$c-radio, "outer"));
                border-radius: 100dvw;

                content: "";

                @media (prefers-reduced-motion: no-preference) {
                    transition:
                        width ti.$c-input ease,
                        height ti.$c-input ease;
                }
            }

            &:checked + .form-radio__button::after {
                width: calc(24px - $inset * 2);
                height: calc(24px - $inset * 2);
            }
        }

        &:hover {
            background-color: map.get(c.$c-input, "background-focus");
            color: map.get(c.$c-input, "surface-focus");
        }

        &:has(input[type="radio"]:checked) {
            border-color: map.get(c.$c-input, "border-focus");

            .form-radio__button {
                border-right-color: map.get(c.$c-input, "border-focus");
            }

            .form-radio__label {
                color: map.get(c.$c-radio, "surface-checked");
            }
        }
    }
}
</style>
