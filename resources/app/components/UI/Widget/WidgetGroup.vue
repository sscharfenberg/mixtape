<script setup lang="ts">
/******************************************************************************
 * WidgetGroup
 * The responsive card grid the browse pages lay their Widgets out in — as many equal columns
 * as fit, one below that. Drop Widgets inside it; `wide` on one spans two of its columns.
 *
 * `pair` says "this group is two MATCHED stats cards" (the welcome page), and it settles two
 * things that only matter when a card has an opposite number:
 *
 *   - a wider per-column floor, because the general one is in `rem` while the root font-size
 *     steps DOWN on small viewports — so it shrinks precisely where a dense card can least
 *     afford it, and the pair stayed two-up at widths where a written-out playtime wrapped to
 *     three lines. See the `pair-min` token.
 *   - the tile rows become real boxes at their natural height, so the two cards' rows line up
 *     rather than each sharing its own spare height across its own lines — and the BOTTOM row
 *     takes what is left over, so neither card ends in a strip of nothing. The rule lives here
 *     and is read below; StatTiles carries the measurements.
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

/* The same grid on a wider floor — see the component banner and the token.

   AND THE PAIR'S SPARE HEIGHT GOES TO THE BOTTOM ROW, which is the second half of "these two are
   a matched set". A wrapping tile grid shares its card's spare height between its own lines, and
   the two cards never have the same amount to share — the music card's playtime wraps to two
   lines where the audiobook card's does not — so every row ends up a different height to its
   opposite number. Making the tile rows real boxes at their natural height lines them up, and
   letting the LAST one grow puts the leftover somewhere a reader reads as air rather than as a
   32px strip of nothing under the shorter card.

   TWO DECLARATIONS, ONE DECISION, so they belong together: the rows have to be boxes to be
   sizeable at all (`--stat-tiles-rows`), and the container has to stack them (`--stat-tiles-flow`).
   Set one without the other and the tiles lay out sideways. Published as custom properties
   because they inherit — StatTiles reads them several components below, with no prop threaded
   through Widget and the consumer cards to carry an answer neither of them knows. StatTiles
   carries the measurements, and why this is a question about a card's NEIGHBOURS rather than
   about the card. */
.widget-group--pair {
    --stat-tiles-rows: flex;
    --stat-tiles-flow: column;

    grid-template-columns: repeat(auto-fit, minmax(min(#{map.get(s.$c-widget, "pair-min")}, 100%), 1fr));
}
</style>
