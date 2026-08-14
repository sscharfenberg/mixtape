<script setup lang="ts">
/******************************************************************************
 * WidgetGroup
 * The responsive card grid the browse pages lay their Widgets out in — as many equal columns
 * as fit, one below that. Drop Widgets inside it; `wide` on one spans two of its columns.
 *
 * `pair` swaps the per-column floor for a wider one, for a group of exactly two STATS cards
 * (the welcome page). It exists because the floor is in `rem` while the root font-size steps
 * DOWN on small viewports — so the general floor shrinks precisely where a dense card can least
 * afford it, and the pair stayed two-up at widths where a written-out playtime wrapped to three
 * lines. See the `pair-min` token for the measurement.
 *****************************************************************************/
withDefaults(
    defineProps<{
        /** Size the columns for two dense stats cards rather than for browse widgets. */
        pair?: boolean;
    }>(),
    { pair: false }
);
</script>

<template>
    <div class="widget-group" :class="{ 'widget-group--pair': pair }"><slot /></div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

// A responsive card grid: as many equal columns as fit, each at least
// `group-min` wide but never wider than its row — `min(<group-min>, 100%)`
// keeps a lone card from overflowing a narrow viewport. `dense` backfills gaps
// so cards that span more than one track don't leave holes.

// Each Widget spans three implicit row tracks (title / body / footer) and
// subgrids into them, so those bands share a height across a row and every
// card's footer lines up. This assumes the widgets in a group share that
// structure — a card that omits a section just leaves its band empty.
.widget-group {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(#{map.get(s.$c-widget, "group-min")}, 100%), 1fr));
    grid-auto-flow: dense;

    gap: map.get(s.$c-widget, "group-gap");
}

// The same grid on a wider floor — see the component banner and the token.
.widget-group--pair {
    grid-template-columns: repeat(auto-fit, minmax(min(#{map.get(s.$c-widget, "pair-min")}, 100%), 1fr));
}
</style>
