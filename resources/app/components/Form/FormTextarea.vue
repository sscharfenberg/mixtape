<script setup lang="ts">
/******************************************************************************
 * FormTextarea
 * The multi-line sibling of FormInput, for a form-row's default slot. Same
 * contract in every respect: it declares only `model` (via defineModel(), which
 * powers v-model) and leans on Vue's FALLTHROUGH ATTRIBUTES for everything else
 * — id, name, rows, maxlength, placeholder, aria-*, and listeners (@change /
 * @blur become onChange/onBlur) all reach the <textarea> with no wiring here,
 * because this component has a single root element and inheritAttrs is on. See
 * FormInput's banner for the full reasoning and its caveats (fallthrough is not
 * type-checked; a multi-root refactor would break it).
 *
 * Height is the caller's `rows`, not a CSS min-height: `rows` is the native way
 * to say "this field expects a paragraph", it survives a font-size change, and
 * it keeps the decision at the call site where the content is known.
 *
 * Deliberately NOT a `type` variant of FormInput: <textarea> is a different
 * element with its own child-text value semantics, so a wrapper that switched
 * tags would have to fork on `type` in the template anyway — two small
 * single-root components stay eligible for the fallthrough that makes both of
 * them almost free.
 *
 * Appearance mirrors .form-input and lives in this component's scoped <style>,
 * in `@layer components` for the same reason: an unlayered scoped block would
 * outrank the FormRow-context rules (addon / button border-squaring) and break
 * those seams.
 *****************************************************************************/
const model = defineModel<string>();
</script>

<template>
    <textarea v-model="model" class="form-textarea" />
</template>

<style scoped lang="scss">
/**
 * Same tokens as the single-line input (s.$c-input / c.$c-input) rather than a
 * partial of its own: a textarea IS the input, with more than one line of it, and
 * the two sitting in one form must not drift apart in border, fill or focus ink.
 */
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;
@use "Abstracts/colors" as c;
@use "Abstracts/timings" as ti;

@layer components {
    .form-textarea {
        display: block; // kills the inline-element descender gap under the field

        width: 100%;
        padding: map.get(s.$c-input, "padding");
        border: map.get(s.$c-input, "border") solid map.get(c.$c-input, "border");

        background-color: map.get(c.$c-input, "background");
        color: map.get(c.$c-input, "surface");
        outline: 0;
        border-radius: map.get(s.$c-input, "radius");

        font: inherit; // a textarea defaults to the browser's monospace form font
        line-height: map.get(s.$c-input, "line-height");

        // Vertical only: a textarea a reader can widen escapes the form's column and
        // pushes the layout around, and the useful axis is the one that shows more text.
        resize: vertical;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                background-color ti.$c-input,
                color ti.$c-input,
                border-color ti.$c-input;
        }

        &::placeholder {
            color: map.get(c.$c-input, "placeholder");
        }

        &:not([readonly]):focus,
        &:not([readonly]):active,
        &:not([readonly]):focus-within {
            background-color: map.get(c.$c-input, "background-focus");
            color: map.get(c.$c-input, "surface-focus");
            border-color: map.get(c.$c-input, "border-focus");
        }

        &[readonly],
        &[disabled] {
            cursor: not-allowed;
        }
    }
}
</style>
