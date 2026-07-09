<script setup lang="ts">
/******************************************************************************
 * FormRow
 * A labelled field row (ported from cantrip.me's FormGroup): label + optional
 * required marker, an optional left addon (icon or #addon slot), the field
 * itself (default slot), an optional trailing #button slot, valid / validating
 * indicators, an optional #text hint, and the validation error area. All visual
 * styles are global (resources/app/styles/components/form/**).
 *****************************************************************************/
import { useId } from "vue";
import Icon from "Components/UI/Icon.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";

withDefaults(
    defineProps<{
        /** HTML `for` attribute linking the label to its input. */
        forId?: string;
        /** Visible label text. When empty, the label row is still rendered for layout. */
        label?: string;
        /** Validation error message shown below the input when `invalid` is true. */
        error?: string;
        /** When true, renders the error message and marks the field as invalid. */
        invalid?: boolean;
        /** Shows a loading spinner beside the input (e.g. during async validation). */
        validating?: boolean;
        /** Shows a check-mark indicator anchored to the input when validation passed. */
        validated?: boolean;
        /** Icon name rendered as a static addon to the left of the input. */
        addonIcon?: string;
        /** When true, displays the required marker next to the label. */
        required?: boolean;
    }>(),
    {
        error: "",
        invalid: false,
        validating: false,
        validated: false,
        required: false
    }
);

/**
 * Unique CSS anchor name for this instance so each form-row's valid indicator
 * anchors to its own input, not a sibling's. `useId()` may contain colons
 * (SSR), so non-CSS-ident characters are stripped.
 */
const anchorName = `--frf-${useId().replace(/[^a-z0-9_-]/gi, "")}`;
</script>

<template>
    <div class="form-row">
        <label v-if="label?.length" :for="forId">
            <span>{{ label }}:</span>
            <span v-if="required" class="form-row__icon"><icon name="required" /></span>
        </label>
        <span v-else class="label">
            <span v-if="required" class="form-row__icon"><icon name="required" /></span>
        </span>
        <div class="form-row__input">
            <div class="form-row__slot">
                <div v-if="addonIcon?.length" class="form-row__addon" aria-hidden="true">
                    <icon :name="addonIcon" />
                </div>
                <div v-if="!addonIcon && $slots.addon">
                    <slot name="addon" />
                </div>
                <div class="form-row__field" :style="`anchor-name: ${anchorName}`">
                    <slot />
                </div>
                <loading-spinner v-if="validating" class="form-row--validating colored" :size="1.5" />
                <div v-if="$slots.button" class="form-row__button">
                    <slot name="button" />
                </div>
                <div
                    v-if="!validating && validated"
                    class="form-row--valid"
                    :style="`position-anchor: ${anchorName}`"
                    aria-label="This field is valid."
                >
                    <icon name="check" :size="1" />
                </div>
            </div>
            <div v-if="$slots.text" class="form-row__text">
                <slot name="text" />
            </div>
            <div v-if="invalid && error.length" class="form-row__error">
                <icon name="error" />
                {{ error }}
            </div>
        </div>
    </div>
</template>
