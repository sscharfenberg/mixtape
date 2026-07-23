<script setup lang="ts">
/******************************************************************************
 * Button
 * The shared neon action button, ported from Kevin Powell's "Neon button with
 * CSS" (codepen QWdRzON) and recoloured from his neon pink to the retro /
 * synthwave cyan. Renders a single <button>; everything the caller sets that
 * isn't a declared prop — type, disabled, @click, aria-*, autofocus — reaches
 * that element via Vue's FALLTHROUGH ATTRIBUTES (single root, inheritAttrs on),
 * and `class` MERGES with the variant class below. The button label (and any
 * leading <Icon>) go in the default slot.
 *
 * `variant` picks which of the two mirrored looks the button rests in:
 *   - "primary" rests UNLIT — a glowing neon outline with a transparent body —
 *     and lights up to the solid FILL on hover / keyboard focus (the original
 *     pen's behaviour).
 *   - "default" is the inverse: solid neon FILL at rest, dimming back to the
 *     outline on hover / focus.
 * So a primary and a default button placed together read as opposites, and
 * either one's hover state is the other one's resting state.
 *
 * Appearance is the global `.btn` / `.btn-*` classes (styles/components/
 * _button.scss, in the components cascade layer), so the same look is reusable
 * on any element — e.g. an Inertia <Link> styled as a button. This component is
 * just the convenient <button> wrapper over those classes.
 *****************************************************************************/
withDefaults(
    defineProps<{
        /** Which mirrored look to rest in: "primary" unlit→fill, "default" fill→unlit. */
        variant?: "primary" | "default";
    }>(),
    {
        variant: "default"
    }
);
</script>

<template>
    <button :class="`btn btn-${variant}`">
        <slot />
    </button>
</template>
