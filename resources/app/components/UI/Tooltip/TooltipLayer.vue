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
 *   s.$c-tooltip  → sizes/components/_tooltip.scss    (padding, radius, max-width, gap, arrow)
 *   ti.$c-tooltip → timings/components/_tooltip.scss  (fade duration)
 *
 * Placement is fed by two custom properties the template writes on the element:
 * `--tooltip-anchor` (the `anchor-name` of the trigger that currently owns the
 * tip, set inline on that trigger by the v-tooltip directive) and
 * `--tooltip-area` (the requested side). The tip itself needs no z-index — it is
 * in the top layer, above every stacking context by definition; the only z-index
 * here is the tail's, tucking it behind this element's own background inside the
 * stacking context `isolation` creates below.
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

    // The tip is an anchor in its own right, so the tail below can measure against
    // the box the tip *actually* ended up in — including after a flip. A fixed name
    // is safe: there is exactly one tip node per document.
    anchor-name: --tooltip-self;

    position-anchor: var(--tooltip-anchor);
    position-area: var(--tooltip-area);

    // flip to the opposite side when the chosen one doesn't fit, like PopOver.
    position-try-fallbacks: flip-block, flip-inline;

    // The tail below paints *behind* this background (z-index: -1) so the half of
    // it that lies inside the box can never graze the text. That needs a stacking
    // context of our own — a top-layer popover is not guaranteed to provide one,
    // and without it a negative z-index would drop the tail behind the page.
    isolation: isolate;

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

    /**
     * The speech-bubble tail: one square, rotated 45°, parked with its *centre* on
     * the tip's edge — the inner half hides behind the tip's own background, the
     * outer half reads as a triangle pointing at the trigger.
     *
     * A diamond rather than the usual border triangle because it needs no idea of
     * which side the tip ended up on: each inset is the *trigger's* centre clamped
     * between the tip's two edges in that axis. The axis the trigger lies outside
     * of therefore clamps to the near edge (that becomes the tail) while the other
     * stays on the trigger's centre (the aim). One rule covers all four placements
     * — and, more importantly, it keeps pointing the right way after
     * `position-try-fallbacks` flips the tip on a narrow viewport, which CSS gives
     * us no way to ask about. (Doing it in JS instead would mean re-measuring on
     * every scroll, the very thing anchor positioning saves us.)
     *
     * `position: fixed`, so every term below is in viewport coordinates — and, less
     * obviously, because it's the only way `anchor()` resolves here at all: an
     * anchor has to be a descendant of the querying element's containing block
     * unless that containing block is the viewport. Absolutely positioned, this
     * pseudo's containing block is the tip, the trigger isn't inside it, and both
     * insets silently fall back to `auto` — the tail lands mid-text.
     *
     * Two anchors, two syntaxes, on purpose: the bare `anchor(center)` reads the
     * *default* anchor set by `position-anchor` below (the trigger, whose generated
     * name only exists inside `--tooltip-anchor`, so it can't be written literally),
     * while `anchor(--tooltip-self …)` names the tip. Watch that distinction —
     * `anchor(--tooltip-anchor center)` looks right and is silently wrong: it asks
     * for an anchor *called* `--tooltip-anchor`, which nothing has.
     *
     * Guarded on @supports because without anchor positioning the tip renders
     * unanchored (see ./README.md) and a tail would then point at nothing.
     */
    @supports (position-area: top) {
        &::after {
            --tail: #{map.get(s.$c-tooltip, "arrow")};

            position: fixed;
            top: clamp(
                calc(anchor(--tooltip-self top) - var(--tail) / 2),
                calc(anchor(center) - var(--tail) / 2),
                calc(anchor(--tooltip-self bottom) - var(--tail) / 2)
            );
            left: clamp(
                calc(anchor(--tooltip-self left) - var(--tail) / 2),
                calc(anchor(center) - var(--tail) / 2),
                calc(anchor(--tooltip-self right) - var(--tail) / 2)
            );
            z-index: -1;

            width: var(--tail);
            height: var(--tail);

            background-color: map.get(c.$c-tooltip, "background");

            content: "";

            position-anchor: var(--tooltip-anchor);

            rotate: 45deg;
        }
    }
}
</style>
