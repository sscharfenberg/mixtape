<script setup lang="ts">
/******************************************************************************
 * Tooltip
 * The wrapper form of a tooltip: for the case where the *trigger* is a group of
 * markup rather than one element you could hang `v-tooltip` on — a stat tile's
 * value + label, a radio + its label. It renders a single inline-flex <span>
 * around the slot and hands that span to `v-tooltip`, so it shares the one
 * TooltipLayer instance (no per-instance popover node, no positioning JS).
 *
 * Prefer `v-tooltip` directly on the element whenever there is one (a button, a
 * table header cell): no extra DOM node, and no wrapper layout to work around.
 * See ./README.md.
 *
 * Triggers come from the directive, so this wrapper is touch-capable for free: a
 * tap on it toggles the tip (there is no hover on a phone), a mouse hovers, a
 * keyboard focus shows it — see ./README.md → Triggers.
 *
 * A11y: this is a *visual* affordance. The trigger must still carry its own
 * accessible name (e.g. `aria-label` on an icon button); the directive adds
 * `aria-describedby` to the wrapper only while the tip is on screen. All motion
 * sits under `prefers-reduced-motion: no-preference`.
 *****************************************************************************/
import { computed } from "vue";
import type { TooltipPlacement } from "Composables/useTooltipLayer";

const props = withDefaults(
    defineProps<{
        /** The hint text shown inside the floating tip. */
        text: string;
        /** Side of the trigger the tip sits on (a CSS `position-area`). Default "top". */
        placement?: TooltipPlacement;
        /** ms of hover-intent before showing; focus and taps are always immediate. Default 300. */
        delay?: number;
    }>(),
    {
        placement: "top",
        delay: 300
    }
);

/**
 * The props mirrored into the directive's option form. Computed so a changed
 * prop reaches an already-open tip through the directive's `updated` hook.
 */
const options = computed(() => ({ text: props.text, placement: props.placement, delay: props.delay }));
</script>

<template>
    <span v-tooltip="options" class="tooltip">
        <slot />
    </span>
</template>

<style scoped lang="scss">
/* The wrapper is the anchor — the tip itself is the shared, globally styled
   TooltipLayer, so nothing here needs to know about positioning. inline-flex
   keeps the wrapper from adding line-height space around the slot content. */
.tooltip {
    display: inline-flex;
}
</style>
