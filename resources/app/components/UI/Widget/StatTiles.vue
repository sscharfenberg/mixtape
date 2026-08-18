<script setup lang="ts">
/******************************************************************************
 * StatTiles
 * The grid of stat tiles a stats card is made of — a glyph, a label and a big value each,
 * every tile explained by a tooltip.
 *
 * SHARED BY THE MUSIC AND AUDIOBOOK CARDS, and what is worth sharing is not really the markup,
 * it is the MEASUREMENTS: the wrapping-flex-lines decision, the `min-content` floor and the
 * unbreakable value pieces are each the answer to a bug found by looking at a browser, and a
 * second copy of them would be the copy that quietly stops matching.
 *
 * A card supplies the tiles; this owns how they are laid out and how a value may break.
 *****************************************************************************/
import { computed } from "vue";
import Icon from "Components/UI/Icon.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
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
    /**
     * A link under the value, for a tile whose number is worth following — the Songs listing's
     * strip points each of its counts at the table narrowed to exactly those rows.
     *
     * Absent means a read-only tile, which is what the collection cards are made of: "96,00 GB"
     * leads nowhere in particular. Absent ALSO covers the count of zero, because the server
     * sends no href for one (SongsController) — a link to an empty table is a promise the page
     * cannot keep, and deciding that here would put the rule in the wrong layer.
     */
    action?: { href: string; label: string };
    /**
     * Mark this tile as the one whose filter is currently narrowing the table it belongs to.
     *
     * The link's WORD changes with it ("show all" rather than "show"), but a word is not enough
     * on its own: a reader who arrives at a filtered URL — a bookmark, a back button, a shared
     * link — has to be able to see WHY the table is short before reading anything.
     */
    active?: boolean;
}

const props = defineProps<{
    /** The tiles to draw, in order. */
    tiles: StatTile[];
}>();

/**
 * The tiles cut into consecutive runs of the same width, each drawn as its own row group.
 *
 * IT EXISTS SO THAT ONE ROW CAN BE GIVEN THE CARD'S LEFTOVER HEIGHT — see the grid's style rule,
 * which is where that leftover comes from and why the bottom row is the right place for it. A
 * single wrapping container has no way to hand its spare cross space to one line rather than
 * sharing it between all of them, so the rows have to be real boxes for the pair case; the groups
 * collapse to `display: contents` everywhere else, which puts every tile back in one wrapping
 * container and leaves that case untouched.
 *
 * RUNS RATHER THAN "the narrow ones, then the wide ones", so the order a card declares is the
 * order drawn. Splitting by width alone would silently move a wide tile to the end — no consumer
 * puts one anywhere but last, which is exactly why that would go unnoticed.
 */
/**
 * Whether any tile in this set offers a link, which every tile then has to make room for.
 *
 * A tile is a centred column, so one with a link underneath pushes its own number UP: measured
 * at 1440px, the two tiles with a link sat their values 10px above the three without, and a row
 * of big numbers that do not share a baseline reads as a rendering fault rather than as a
 * difference in what the tiles offer. So the line is reserved in all of them or in none of them
 * — and "none" is the collection cards, whose tiles link nowhere and must not grow a blank line.
 */
const hasActions = computed<boolean>(() => props.tiles.some(tile => tile.action !== undefined));

const groups = computed<StatTile[][]>(() =>
    props.tiles.reduce<StatTile[][]>((rows, tile) => {
        const current = rows[rows.length - 1];

        if (current && (current[0].wide === true) === (tile.wide === true)) current.push(tile);
        else rows.push([tile]);

        return rows;
    }, [])
);
</script>

<template>
    <div class="widget-stats__grid" :class="{ 'widget-stats__grid--actions': hasActions }">
        <div v-for="group in groups" :key="group[0].key" class="widget-stats__lines">
            <tooltip
                v-for="tile in group"
                :key="tile.key"
                :text="tile.hint"
                class="widget-stats__cell"
                :class="{ 'widget-stats__cell--wide': tile.wide, 'widget-stats__cell--active': tile.active }"
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
                <!-- NOT PREFETCHED, though LabelledLink would oblige and the target is only a
                     listing: these sit in a strip a reader's pointer crosses on the way down to
                     the table, so warming on hover would spend a full filtered listing query per
                     tile brushed past. A reader who means it can wait for the visit. -->
                <labelled-link v-if="tile.action" :href="tile.action.href" icon="" class="widget-stats__action">
                    {{ tile.action.label }}
                </labelled-link>
            </tooltip>
        </div>
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

   `flex: 1` on the container is what hands the card's spare height to the tiles, and where that
   height GOES is the one thing this file cannot decide for itself — see the row groups below.

   NOT A SUBGRID, which is the obvious way to make two cards' rows agree and cannot work here: a
   subgrid spans a DECLARED number of tracks, and the number of tile rows is a wrapping outcome
   rather than a constant. It is three at the pair's two-up width, fewer once the group collapses
   to one column, more on a phone. Pinning it would mean going back to a fixed column count —
   the arrangement the paragraph above measures the cost of. */
.widget-stats__grid {
    display: flex;
    align-content: stretch;

    /* A COLUMN OF ROW GROUPS, but only where those groups are boxes at all (below). */
    flex-flow: var(--stat-tiles-flow, row) wrap;

    flex: 1;

    gap: map.get(s.$c-widget, "cell-gap");
}

/* One run of same-width tiles. `display: contents` BY DEFAULT, so it has no box and every tile is
   a direct item of the one wrapping container above — which is the whole of the ordinary case, and
   why a card that is not one of a matched pair is completely unaffected by any of this.

   WHY THE GROUPS BECOME BOXES FOR A PAIR. Spare height is shared by `align-content`, which works
   per LINE and cannot favour one — and each card computes its share from its OWN leftover. On the
   welcome page the music card's playtime runs to "2 Monate, 17 Tage, …" and wraps to two lines,
   so it has ~30px less to give away than the audiobook card beside it, and every row ended up a
   different height to its opposite number: measured at 1440px, an identical first row drawn 72px
   tall in one card and 83px in the other, so the years row started 10px lower on the right and
   the playtime row 22px lower.

   As real boxes the rows take their natural height, so all three start at the same y in both
   cards (485 / 570 / 654, measured) — and the LAST group grows, which is where the leftover goes.
   That is the half a per-line rule cannot express: the shorter card's 32px lands under its
   playtime, so both cards' bottom tiles end flush (104px tall, bottom 758) instead of one being
   72px with a 32px strip of nothing beneath it.

   IT IS THE BOTTOM ROW BECAUSE IT IS THE ONE THAT CAN ABSORB HEIGHT HONESTLY. A tile is a glyph,
   a label and a value centred in whatever box it gets, so a taller box reads as air rather than
   as a mistake — and being last, it grows downward into space no other row wants. Sharing the
   leftover between all the rows instead is what pulls them out of step, and giving it to a row
   with a row beneath it would just move the problem up.

   WHY THE GROUP DECIDES AND NOT THIS FILE: a card alone beside four browse widgets has real
   spare height to absorb — 89px on the Music page, measured, which `stretch` spreads over three
   lines to draw every tile 102px instead of 72px. Route that to the bottom row instead and the
   playtime tile is ~22px taller than the five above it, for no reason a reader could see. So
   "which row takes the slack" is a question about a card's NEIGHBOURS, and `WidgetGroup --pair`
   answers it. Custom properties inherit, so it reaches here with no prop threaded through Widget
   and the two consumer cards to carry an answer neither of them knows. */
.widget-stats__lines {
    display: var(--stat-tiles-rows, contents);
    align-content: stretch;

    /* Natural height, so the rows of two cards in a pair agree — except the last, which takes
       what is left over. Inert under `display: contents`, which is what keeps the ordinary case
       exactly as it was. */
    flex-grow: 0;
    flex-wrap: wrap;

    gap: map.get(s.$c-widget, "cell-gap");

    &:last-child {
        flex-grow: 1;
    }
}

/* Each tile is the Tooltip's root span (class merged onto it); as a flex item its inline-flex
   is blockified, so we only set the direction and the alignment.

   CENTRED BOTH WAYS, which takes three properties rather than one,
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

    /* THE ONE THAT IS ON — the filter currently narrowing the table below the strip.

       A marked cell, not a recoloured one: the border and a barely-tinted ground say "this one"
       while leaving the big number on the same near-grey every other number sits on. The
       token pair is the mode toggle's selected pair, so "on" looks like "on" throughout the app
       (see the colour partial for why it is re-picked rather than borrowed).

       The border replaces nothing, so it has to come out of the padding rather than adding to
       the tile's size: box-sizing is border-box (layout/_base.scss), which is exactly what keeps
       a marked tile the same size as its neighbours. */
    &--active {
        border: map.get(s.$c-widget, "border") solid map.get(c.$c-widget, "cell-active-border");

        background-color: map.get(c.$c-widget, "cell-active-background");
    }
}

/* RESERVE THE LINK'S LINE IN EVERY TILE OF A STRIP THAT HAS LINKS, so the numbers share a
   baseline across the row — see `hasActions` for the measurement. A hidden non-breaking space
   at the link's own size is what reserves exactly one line of it: an empty box would collapse,
   and a hard-coded height would be this rule's guess at a line-height it does not own.

   `:not(:has())` rather than a flag per tile, because the question is about the tile ("do you
   already have one?") and the DOM is the only honest place that answer lives. */
.widget-stats__grid--actions .widget-stats__cell:not(:has(.widget-stats__action))::after {
    visibility: hidden;

    font-size: map.get(s.$c-widget, "cell-action-font-size");

    content: "\00a0";
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

/* The tile's own link, on the line under the value.

   Smaller than the label, so the NUMBER stays what the eye lands on — the link is what to do
   about the number rather than part of reading it. No colour of its own on purpose: it is a
   LabelledLink, which carries the app's text-link palette, so a tile's action looks like every
   other link on the page instead of like a control this component invented. */
.widget-stats__action {
    font-size: map.get(s.$c-widget, "cell-action-font-size");
}

.widget-stats__label {
    font-size: 1rem;
}
</style>
