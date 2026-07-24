<script setup lang="ts">
/******************************************************************************
 * Tooltip
 * A text hint that floats beside its trigger, built on the same primitives as
 * PopOver.vue — the native Popover API (top layer, so no overflow/stacking
 * clipping) + CSS anchor positioning — with zero JS positioning library. Wraps
 * an arbitrary trigger (default slot); shows on hover (after a short intent
 * delay) and on keyboard focus, hides on leave / blur / Escape.
 *
 * A11y: this is a *visual* affordance. The trigger must still carry its own
 * accessible name (e.g. `aria-label` on an icon button). The tip's id is offered
 * through the slot so `aria-describedby` can be bound on the real control when
 * the description itself must be announced. All motion sits under
 * `prefers-reduced-motion: no-preference`.
 *****************************************************************************/
import { computed } from "vue";
import { useTooltip } from "Composables/useTooltip";
import type { TooltipPlacement } from "Composables/useTooltip";

const props = withDefaults(
    defineProps<{
        /** The hint text shown inside the floating tip. */
        text: string;
        /** Side of the trigger the tip sits on (a CSS `position-area`). Default "top". */
        placement?: TooltipPlacement;
        /** ms of hover-intent before showing; focus is always immediate. Default 300. */
        delay?: number;
    }>(),
    {
        placement: "top",
        delay: 300
    }
);

const { tooltipRef, tooltipId, anchorName, show, hide } = useTooltip({ showDelay: props.delay });

/** The `placement` prop bound straight into the scoped style's `position-area`. */
const positionArea = computed(() => props.placement);
</script>

<template>
    <span
        class="tooltip"
        @mouseenter="show()"
        @mouseleave="hide"
        @focusin="show(true)"
        @focusout="hide"
        @keydown.escape="hide"
    >
        <slot :id="tooltipId" />
        <span :id="tooltipId" ref="tooltipRef" popover="manual" role="tooltip" class="tooltip__tip">
            {{ text }}
        </span>
    </span>
</template>

<style scoped lang="scss">
/**
 * anchor-name / position-anchor are set here via v-bind (they only resolve
 * inside an SFC's scoped style); palette, sizing and motion come from the
 * contextual tokens, matching PopOver.vue.
 */
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.tooltip {
    display: inline-flex;

    anchor-name: v-bind(anchorName);
}

.tooltip__tip {
    position: fixed;

    width: max-content;
    max-width: map.get(s.$c-tooltip, "max-width");
    padding: map.get(s.$c-tooltip, "padding");
    border: 0;
    margin: map.get(s.$c-tooltip, "gap");

    background-color: map.get(c.$c-tooltip, "background");
    color: map.get(c.$c-tooltip, "surface");

    border-radius: map.get(s.$c-tooltip, "radius");

    font-size: 0.85rem;
    line-height: 1.3;

    position-anchor: v-bind(anchorName);
    position-area: v-bind(positionArea);

    // flip to the opposite side when the chosen one doesn't fit, like PopOver.
    position-try-fallbacks: flip-block, flip-inline;

    // popover elements are display:none until shown; fade in (and linger in the
    // top layer through the exit via allow-discrete) only when motion is welcome.
    @media (prefers-reduced-motion: no-preference) {
        opacity: 0;

        transition:
            opacity ti.$c-tooltip ease-out,
            display ti.$c-tooltip allow-discrete,
            overlay ti.$c-tooltip allow-discrete;
    }

    &:popover-open {
        @media (prefers-reduced-motion: no-preference) {
            opacity: 1;

            @starting-style {
                opacity: 0;
            }
        }
    }
}
</style>
