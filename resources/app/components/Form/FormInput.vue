<script setup lang="ts">
/******************************************************************************
 * FormInput
 * The text input inside a form-row's default slot. A thin wrapper around a
 * native <input>: the only thing this component *declares* is `model` (via
 * defineModel(), which powers v-model). Everything else relies on Vue's
 * FALLTHROUGH ATTRIBUTES.
 *
 * How that works: any attribute or listener a parent sets on <FormInput> that
 * is NOT a declared prop / model / emit is collected into `$attrs`, and because
 * this component has a single root element with inheritAttrs on (the default),
 * Vue stamps them straight onto that root — here, the <input>. So id, type,
 * name, autocomplete, autofocus, placeholder, aria-* and data-* attrs, and
 * listeners (@change / @blur / @input become onChange/onBlur/onInput) all reach
 * the input with zero wiring in this file. Consequences we rely on:
 *   - `type` stays parent-controlled (e.g. text ⇄ password toggling).
 *   - Change/blur handlers can be attached on <FormInput …> later, as-is.
 *   - `class` / `style` MERGE with the root's own (so `class="form-input"`
 *     below is kept even if a parent adds a class).
 * Caveats: fallthrough is not type-checked — a misspelt attribute silently does
 * nothing rather than erroring; and it only auto-applies because the root is a
 * single element. A multi-root refactor would need inheritAttrs:false +
 * v-bind="$attrs" on the target element.
 * See https://vuejs.org/guide/components/attrs.html
 *
 * Appearance (the .form-input rules) lives in this component's scoped <style>,
 * deliberately wrapped in `@layer components` so the FormRow-context rules
 * (addon / button border-squaring in components/form/row/*) can still override
 * the border/radius — an unlayered scoped block would win over @layer
 * components and break those seams.
 *****************************************************************************/
const model = defineModel<string>();
</script>

<template>
    <input v-model="model" class="form-input" />
</template>

<style scoped lang="scss">
/**
 * Size / colour values come from the contextual Abstracts tokens (s.$c-input /
 * c.$c-input), never the global palette — same source the old global
 * _input.scss consumed.
 */
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;
@use "Abstracts/colors" as c;

@layer components {
    .form-input {
        width: 100%;
        padding: 0.75ex 4ch 0.75ex 2ch;
        border: map.get(s.$c-input, "border") solid map.get(c.$c-input, "border");

        background-color: map.get(c.$c-input, "background");
        color: map.get(c.$c-input, "surface");
        outline: 0;
        border-radius: map.get(s.$c-input, "radius");

        line-height: map.get(s.$c-input, "line-height");

        // 150ms == cantrip's $timings "fast"; no timings token group yet.
        transition:
            background-color 150ms,
            color 150ms,
            border-color 150ms;

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
