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
 *   - "primary" rests LIT — the solid neon FILL — and dims back to the glowing
 *     outline on hover / keyboard focus.
 *   - "default" is the inverse: a glowing neon outline over a solid neutral body
 *     at rest, lighting up to the solid FILL on hover / focus (the original pen's
 *     behaviour).
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
        /** Which mirrored look to rest in: "primary" fill→outline, "default" outline→fill. */
        variant?: "primary" | "default";
        /**
         * Drop the halo — the blurred neon reflection the button pools BELOW itself.
         *
         * Off by default, so every button that already exists keeps the full lit-tube look.
         * It is switched on where a button sits inside another surface rather than on the
         * page: in a detail page's hero the glow spills onto the panel and onto whatever the
         * action row wrapped underneath it, which reads as a smudge rather than as neon (the
         * hero is already framed by its own rotating ring). The button itself is unchanged —
         * this only removes the pool, not the edge glow or the fill.
         */
        noHalo?: boolean;
    }>(),
    {
        variant: "default",
        noHalo: false
    }
);
</script>

<template>
    <button :class="['btn', `btn-${variant}`, { 'btn--no-halo': noHalo }]">
        <slot />
    </button>
</template>
