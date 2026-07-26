<script setup lang="ts">
/******************************************************************************
 * TooltipLayer
 * The app's *single* floating tip. Mounted once in FullLayout — every trigger,
 * whether it uses the `v-tooltip` directive or the Tooltip wrapper, shows this
 * one node against its own CSS anchor (state comes from the useTooltipLayer
 * singleton). One node instead of one-per-trigger is what makes tooltips on a
 * table's every header (or every row) free.
 *
 * It is a native `popover`, so it renders in the top layer and can't be clipped
 * by an `overflow:hidden` / stacking-context ancestor (a widget frame, the
 * sticky thead), and it is placed purely by CSS anchor positioning — no JS
 * positioning library, and the tip tracks its anchor on scroll for free.
 *
 * Teleported to <body>, which also puts it after every possible trigger in tree
 * order — a requirement of anchor positioning, since a positioned element can
 * only anchor to an element that precedes it. Teleporting does NOT cost us the
 * scoped style: the scope attribute is stamped on this component's own markup,
 * so it travels with the node.
 *****************************************************************************/
import { TOOLTIP_ID, useTooltipLayer } from "Composables/useTooltipLayer";

const { tipRef, text, anchorName, placement } = useTooltipLayer();
</script>

<template>
    <Teleport to="body">
        <span
            :id="TOOLTIP_ID"
            ref="tipRef"
            popover="manual"
            role="tooltip"
            class="tooltip-layer"
            :style="{ '--tooltip-anchor': anchorName, '--tooltip-area': placement }"
            >{{ text }}</span
        >
    </Teleport>
</template>

<style scoped lang="scss">
/**
 * Everything visual about a tooltip lives here — the one place to look, and the
 * one place to adapt when this folder is copied into another project (see
 * ./README.md → Styling). Every literal comes from a contextual token group:
 *
 *   c.$c-tooltip  → colors/components/_tooltip.scss   (background, surface)
 *   s.$c-tooltip  → sizes/components/_tooltip.scss    (padding, radius, max-width, gap)
 *   ti.$c-tooltip → timings/components/_tooltip.scss  (fade duration)
 *
 * Placement is fed by two custom properties the template writes on the element:
 * `--tooltip-anchor` (the `anchor-name` of the trigger that currently owns the
 * tip, set inline on that trigger by the v-tooltip directive) and
 * `--tooltip-area` (the requested side). No z-index anywhere: the popover is in
 * the top layer, which sits above every stacking context by definition.
 *
 * Motion: the fade — plus the discrete display/overlay transitions that keep the
 * tip in the top layer *while* it fades out — sits behind
 * prefers-reduced-motion: no-preference. Without the preference the tip simply
 * appears; unlike the loading spinner, a static hint doesn't read as broken.
 */
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.tooltip-layer {
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

    // A hint is never interactive, and this one node roams the whole page — so it
    // must not swallow the hover or click of whatever it happens to cover (a
    // "bottom" tip on a sticky table header sits right over the first row).
    pointer-events: none;

    position-anchor: var(--tooltip-anchor);
    position-area: var(--tooltip-area);

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
