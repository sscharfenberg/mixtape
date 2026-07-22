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
 * Appearance lives in this component's scoped <style>, wrapped in
 * `@layer components` (same as FormInput) so it sits in the components cascade
 * layer the old global .btn-* rules used. Values come from the contextual
 * tokens (c.$c-button / s.$c-button / ti.$c-button); the neon glow spreads are
 * `em`-based effect constants, so they scale with the button's font size.
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

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

// The neon look has two states: an unlit OUTLINE (glowing edge, neon label on a
// transparent body) and a lit FILL (solid neon body, dark label). Each look is
// a mixin so the two variants just compose them — and swap them on hover.
@mixin neon-outline {
    color: var(--btn-neon);

    text-shadow:
        0 0 0.125em hsl(0deg 0% 100% / 30%),
        0 0 0.45em currentcolor;

    &::before {
        opacity: 0.7;
    }

    &::after {
        opacity: 0;
    }
}

@mixin neon-fill {
    color: var(--btn-contrast);

    text-shadow: none;

    &::before {
        opacity: 1;
    }

    &::after {
        opacity: 1;
    }
}

@layer components {
    .btn {
        --btn-neon: #{map.get(c.$c-button, "neon")};
        --btn-contrast: #{map.get(c.$c-button, "contrast")};

        display: inline-flex;
        position: relative;
        align-items: center;
        justify-content: center;
        isolation: isolate;

        padding: map.get(s.$c-button, "padding");
        border: map.get(s.$c-button, "border") solid var(--btn-neon);
        gap: map.get(s.$c-button, "gap");

        outline: 0;
        border-radius: map.get(s.$c-button, "radius");

        // the inset + outer edge glow that reads as a lit neon tube
        box-shadow:
            inset 0 0 0.5em 0 var(--btn-neon),
            0 0 0.5em 0 var(--btn-neon);

        font: inherit;
        font-weight: 600;
        line-height: map.get(s.$c-button, "min-height");
        text-decoration: none;

        cursor: pointer;
        user-select: none;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                color ti.$c-button linear,
                text-shadow ti.$c-button linear;
        }

        // the two glow layers: ::after is the neon fill sitting behind the label,
        // ::before is the blurred "reflection" pooled below the button. their
        // opacity is what the state mixins drive.
        &::before,
        &::after {
            position: absolute;
            opacity: 0;

            width: 100%;
            height: 100%;

            background-color: var(--btn-neon);

            content: "";

            pointer-events: none;

            @media (prefers-reduced-motion: no-preference) {
                transition: opacity ti.$c-button linear;
            }
        }

        &::before {
            top: 120%;
            left: 0;

            transform: perspective(1em) rotateX(40deg) scale(1, 0.35);

            filter: blur(1em);
        }

        &::after {
            top: 0;
            left: 0;
            z-index: -1;

            border-radius: inherit;
            box-shadow: 0 0 2em 0.5em var(--btn-neon);
        }

        &[disabled] {
            background-color: map.get(c.$c-button, "background-disabled");
            color: map.get(c.$c-button, "surface-disabled");
            border-color: transparent;
            box-shadow: none;

            font-style: italic;
            text-shadow: none;

            cursor: not-allowed;

            &::before,
            &::after {
                opacity: 0;
            }
        }
    }

    // primary: unlit at rest, lights up on hover / focus (the original pen).
    .btn-primary {
        @include neon-outline;

        background-color: map.get(c.$c-button, "background-primary");

        &:not([disabled]):hover,
        &:not([disabled]):focus-visible {
            @include neon-fill;
        }
    }

    // default: the mirror image — lit at rest, dims to the outline on hover / focus.
    .btn-default {
        @include neon-fill;

        &:not([disabled]):hover,
        &:not([disabled]):focus-visible {
            @include neon-outline;
        }
    }
}
</style>
