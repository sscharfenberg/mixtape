<script setup lang="ts">
/******************************************************************************
 * StatTiles
 * The grid of stat tiles a stats card is made of — a glyph, a label and a big value each,
 * every tile explained by a tooltip.
 *
 * EXTRACTED FROM StatsWidget when the Audiobooks area wanted the same card. What
 * moved with it is not really the markup, it is the MEASUREMENTS: the wrapping-flex-lines
 * decision, the `min-content` floor, and the unbreakable value pieces are each the answer to
 * a bug that was found by looking at a browser, and a second copy of them would be the copy
 * that quietly stops matching.
 *
 * A card supplies the tiles; this owns how they are laid out and how a value may break.
 *****************************************************************************/
import Icon from "Components/UI/Icon.vue";
import Tooltip from "Components/UI/Tooltip/Tooltip.vue";

/** One stat tile — a glyph, a label, the formatted value, and a tooltip explaining it. */
export interface StatTile {
    key: string;
    icon: string;
    /**
     * The value as the PIECES IT MAY BREAK BETWEEN, drawn one unbreakable span each. Almost
     * every tile holds exactly one — a number and its unit are one word to a reader, and
     * "96,00" with "GB" alone on the line below reads as two facts. A phrase like a playtime
     * is the reason it is a list: it is long enough to need a break, and the honest place for
     * one is between its units, never inside "21 Stunden".
     */
    value: string[];
    label: string;
    hint: string;
    /**
     * Span the whole remaining line instead of one tile's width, for a value that is a PHRASE
     * rather than a number. The cell's own style rule carries the measurements.
     */
    wide?: boolean;
}

defineProps<{
    /** The tiles to draw, in order. */
    tiles: StatTile[];
}>();
</script>

<template>
    <div class="widget-stats__grid">
        <tooltip
            v-for="tile in tiles"
            :key="tile.key"
            :text="tile.hint"
            class="widget-stats__cell"
            :class="{ 'widget-stats__cell--wide': tile.wide }"
        >
            <span class="widget-stats__head">
                <icon :name="tile.icon" :size="1" />
                <span class="widget-stats__label">{{ tile.label }}</span>
            </span>
            <!-- One span per unbreakable piece, with the separating SPACE as a text node
                 outside them: that space is the only place the value may break, which is what
                 keeps "96,00 GB" and "21 Stunden" whole.

                 ALL ON ONE LINE, and the space written as an interpolation rather than as
                 markup, because both halves of that are load-bearing. A newline between the
                 pieces would put a whitespace text node INSIDE the run and Vue's `condense`
                 would drop it; a `<span> </span>` separator loses its space the same way
                 (measured — the tests caught it). An interpolation is a real expression node,
                 so nothing may collapse it. Lose the space and the value still looks right at
                 a glance while reading and copying as "2 Tage,3 Stunden". -->
            <span class="widget-stats__value"><template v-for="(part, index) in tile.value" :key="part">{{ index > 0 ? " " : "" }}<span class="widget-stats__part">{{ part }}</span></template></span>
        </tooltip>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

/* WRAPPING FLEX LINES, NOT A TRACK GRID, and the difference is the whole of "no whitespace".

   It was `grid-template-columns: repeat(auto-fit, minmax(7rem, 1fr))`, which decides a track
   COUNT from the width and then leaves any track the tiles do not reach standing empty —
   measured at 1280px: six tracks for five number tiles, so every row ended 132px short. And no
   tile count fixes that, because the count that divides evenly changes with the width: a sixth
   tile squares 1280px and leaves 1100px with a row holding one tile and four empty tracks.

   Flex lines have no tracks to leave empty. Each tile is `flex: 1 1 7rem`, so it wraps at
   about the same width as before and then GROWS to fill whatever line it lands on — five
   across, or three and two, always flush to both edges. The cost is that tiles on different
   lines can differ in width, which for independent facts reads as fine and never as ragged.

   `flex: 1` on the container is what hands the card's spare height to the tiles;
   `align-content` decides what happens to it, and the default `stretch` shares it between the
   lines rather than pooling it under the last one.

   THAT DEFAULT IS ALSO WHY TWO CARDS SIDE BY SIDE CANNOT LINE UP, which is what
   `--stat-tiles-align` exists for (2026-08-14, the owner). Stretching is a per-card decision
   made from that card's OWN spare height: on the welcome page the music card's playtime runs to
   "2 Monate, 17 Tage, …" and wraps to two lines, so it has ~30px less to give away than the
   audiobook card beside it — and each card shared what it had across its own three lines.
   Measured at 1440px: identical row 1 in both, drawn 72px tall in one and 83px in the other, so
   the years row started 10px lower on the right and the playtime row 22px lower. Every tile a
   different size to its opposite number.

   `flex-start` is the fix, and the cost is explicit rather than hidden: lines take their natural
   height, so all three rows start at the same y in both cards (485 / 570 / 654, measured) and
   only the playtime's own box is taller — which is fine, because it is last and grows downward.
   The shorter card then leaves its slack as a strip at the bottom, the very thing `stretch` was
   added to remove. So this is a CHOICE BETWEEN TWO COSTS, not a bug fix, and which one is right
   depends on the neighbours: a card alone beside four browse widgets has a lot of spare height
   and should absorb it, while a matched pair should agree with each other.

   WHICH IS WHY THE GROUP DECIDES, NOT THIS FILE. A custom property inherits, so
   `WidgetGroup --pair` publishes `flex-start` and this reads it — no prop threaded down through
   Widget and the two consumer cards to reach a layout question none of them asks. */
.widget-stats__grid {
    display: flex;
    align-content: var(--stat-tiles-align, stretch);
    flex-wrap: wrap;

    flex: 1;

    gap: map.get(s.$c-widget, "cell-gap");
}

/* Each tile is the Tooltip's root span (class merged onto it); as a flex item its inline-flex
   is blockified, so we only set the direction and the alignment.

   CENTRED BOTH WAYS (the owner's call, 2026-08-13). It takes three properties, not one,
   because a column flex box centres along each axis by a different name: `justify-content`
   centres the head-and-value PAIR in the tile's height, `align-items` centres each of those
   two boxes across its width, and `text-align` centres the lines INSIDE a box that wrapped —
   the last being the only one that reaches a phrase long enough to break at all.

   The height half is the one doing the most work now that the tiles stretch: `align-content`
   on the grid above hands them the card's spare height, so a tile is routinely taller than the
   two lines it holds, and without this they would all hang from their top edges. */
.widget-stats__cell {
    align-items: center;
    justify-content: center;
    flex-direction: column;

    min-width: min-content;

    /* Grow to fill the line and wrap at about 7rem — but NEVER shrink past the widest thing in
       the tile that cannot break.

       `min-width: 0` was right while the values wrapped: a long one could reflow onto two
       lines instead of widening its own basis and pushing a sibling onto the next line. Making
       each value one unbreakable run turned that floor into a hole — the tile shrank under its
       own text, which then ran straight out through the padding, so the size tile read
       "83,27 GB" hard against both edges at 1600px (the owner's catch).

       `min-content` here is that unbreakable run PLUS the padding, because box-sizing is
       border-box (layout/_base.scss), so the padding is inside what the tile asks for rather
       than something it gives up first. A line that can no longer hold five tiles now wraps to
       four rather than squeezing all five past their own content. */
    flex: 1 1 7rem;

    padding: map.get(s.$c-widget, "cell-padding");
    gap: 0.15rem;

    background-color: map.get(c.$c-widget, "cell-background");
    border-radius: map.get(s.$c-widget, "cell-radius");

    text-align: center;

    /* THE WHOLE REMAINING ROW FOR A PHRASE, at the same size as every other tile.

       The measurements, at 1280px where the old grid gave ~146px tracks. In ONE track the
       thirty-character playtime wrapped to six lines and made its tile 305px tall — "Sekunden"
       alone is wider than one. Sharing a line with two others it was two lines with dead space
       beside it. On a line of its own it is one line, full width, and nothing is empty
       anywhere.

       `flex-basis: 100%` rather than a span count, so it does not have to know how many tiles
       shared the line above it — and so another stat tile can be added without touching this. */
    &--wide {
        flex-basis: 100%;
    }
}

/* The glyph and the label on one line above the number, which is what the icons bought: the
   label no longer has to carry the tile alone, so it can be read at a glance and the number
   below it stays the thing that stands out. */
.widget-stats__head {
    display: flex;
    align-items: center;

    gap: 0.4ch;

    color: map.get(c.$c-widget, "footer-surface");
}

.widget-stats__value {
    color: map.get(c.$c-widget, "surface");

    font-size: 1.8rem;
    font-weight: 700;
    line-height: 1.1;
}

/* ONE PIECE THAT MAY NOT BREAK INSIDE ITSELF — the `<nobr>` job, done with the property rather
   than the element: Vue does not know `nobr` as a native tag, so a literal one resolves as a
   component and warns on every render in dev.

   A number and its unit are one word to a reader. "96,00" with "GB" alone underneath reads as
   two facts, and a year range is a live case rather than a theoretical one — a dash IS a break
   opportunity, so "1965–" could sit at the end of a line with "2024" below it.

   A phrase is the only value made of several of these, and it may break BETWEEN them: those
   spaces are text nodes in the template, outside these spans, so they survive as the value's
   only break points. A trailing space inside the span would not — `nowrap` suppresses the
   break at a space it contains, which would make the whole phrase one unbreakable run and
   overflow the tile. */
.widget-stats__part {
    white-space: nowrap;
}

/* Bigger than it was (0.8rem), because the tiles have the room and a label nobody can read is
   a label doing none of its work — the same correction the search pips needed. */
.widget-stats__label {
    font-size: 1rem;
}
</style>
