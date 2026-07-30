<script setup lang="ts">
/******************************************************************************
 * FactPair
 * One labelled value, drawn as a washed tile: a small capped label (with an optional
 * icon for the KIND of fact) above the value it names.
 *
 * Extracted from Facts so the HERO can use the same tile — a song's artist / album /
 * year appear both in the hero's metadata row and in the facts cards below it, and two
 * implementations of one tile is how the two drift apart. Facts renders these for the
 * pairs it was handed; a caller can also place them itself, which is what the hero's
 * `#metadata` slot takes.
 *
 * Renders an <li>: a set of labelled values is a list, and both hosts wrap it in one.
 * Its width is left to the parent — Facts lets the tiles grow to fill a card's line,
 * the hero has them hug their content — because "how wide" is the row's decision, not
 * the tile's.
 *
 * Pass `href` and the tile becomes a LINK — filled in the brand pair rather than washed,
 * because a fact that leads somewhere is a different kind of object from the dead ends
 * around it. The anchor wraps only the value, but is stretched over the whole tile by a
 * pseudo-element (see the styles), so the padding is clickable while the link's
 * accessible name stays just the value — "Luciferian Towers", not "ALBUM Luciferian
 * Towers".
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import Icon from "Components/UI/Icon.vue";

defineProps<{
    /** Already translated. Rendered in small caps as the quiet half of the tile. */
    label: string;
    /** Display-ready text. A caller that might have nothing should not render the pair at all. */
    value: string;
    /** Sprite symbol id for the kind of fact this is, shown before the label. */
    icon?: string;
    /** Render the value monospaced — for values read character by character (paths, hashes). */
    mono?: boolean;
    /**
     * Where this fact leads, if anywhere — an Inertia path, so the tile navigates
     * client-side like any other <Link>. Omit for the normal, unclickable tile; the
     * caller decides, because only it knows whether the thing behind the value has a page
     * (a song's album does, its codec does not).
     */
    href?: string;
}>();
</script>

<template>
    <li class="fact-pair" :class="{ 'fact-pair--link': href }">
        <span class="fact-pair__label">
            <icon v-if="icon" :name="icon" :size="0" />
            {{ label }}
        </span>
        <Link
            v-if="href"
            :href="href"
            class="fact-pair__value fact-pair__value--link"
            :class="{ 'fact-pair__value--mono': mono }"
            >{{ value }}</Link
        >
        <span v-else class="fact-pair__value" :class="{ 'fact-pair__value--mono': mono }">{{ value }}</span>
    </li>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/typography" as t;

/* Stacked: label over value. Stacking is why there is no label column to align across
   tiles and no baseline to reconcile between the two type sizes — they are not side by
   side. No `flex-grow` here on purpose: whether a tile stretches to fill its line is the
   host row's call (Facts says yes, the hero says no). */
.fact-pair {
    display: flex;
    flex-direction: column;

    padding: map.get(s.$c-facts, "tile-padding");
    gap: map.get(s.$c-facts, "pair-gap");

    background-color: map.get(c.$c-facts, "tile-background");
    border-radius: map.get(s.$c-facts, "tile-radius");
}

/* Small letter-spaced caps in the muted label tint — the hi-fi spec-sheet look, and the
   quiet half of the tile so the value below can be the loud one.

   A flex row because the label may carry an icon for the KIND of fact it is; the gap is
   set even when there is no icon, which costs nothing (flex gaps only apply between
   items) and means adding one never shifts the text. */
.fact-pair__label {
    display: flex;
    align-items: center;

    gap: map.get(s.$c-facts, "label-icon-gap");

    color: map.get(c.$c-facts, "label");

    font-size: map.get(s.$c-facts, "label-font-size");
    text-transform: uppercase;
    letter-spacing: map.get(s.$c-facts, "label-tracking");
}

/* The loud half of the tile: a step up in size from body text, which is what makes it
   read as the fact and the label as its caption — no colour needed. Tabular figures so
   digits in stacked tiles (bit rate, sample rate, size, dates) line up instead of
   jittering by glyph width.

   `overflow-wrap: anywhere` because values are file tags, so an unbroken 80-character
   token is a thing that happens (a German compound, a glued-together composer credit, a
   path). Without it the tile's min-content is that whole token and its card grows ~600px
   past its line — measured, not hypothetical. `anywhere` rather than `break-word`
   precisely because it also lowers min-content, which is what lets the tile shrink in the
   first place. */
.fact-pair__value {
    overflow-wrap: anywhere;

    font-size: map.get(s.$c-facts, "value-font-size");
    font-variant-numeric: tabular-nums;
}

/* Monospaced, a step down from the value's own size: a mono face at the same size looks
   oversized beside the proportional text around it. */
.fact-pair__value--mono {
    font-family: map.get(t.$c-facts, "mono");
    font-size: map.get(s.$c-facts, "mono-font-size");
}

/* A tile that leads somewhere: FILLED in the brand pair instead of washed. Both halves
   take the pair's ink — including the label, which normally wears a muted grey that has
   no contrast to spare on a saturated fill; the size and the caps keep the hierarchy
   without needing a second colour.

   `position: relative` is the anchor of the stretched link below. */
.fact-pair--link {
    position: relative;

    background-color: map.get(c.$c-facts, "link-background");
    color: map.get(c.$c-facts, "link-surface");

    .fact-pair__label {
        color: inherit;
    }

    /* Hover INVERTS the tile — fill and ink swap places — rather than underlining the
       value. Two reasons it is the better signal here: the tile is the click target, so
       the feedback should be the tile's, not the text's; and an underline inside a
       coloured chip adds a second link cue to something the fill already announced.
       Reading the swap out of the same two tokens means it cannot drift from the resting
       state, and the label follows for free (it inherits).

       Hovering the TILE, not the anchor: the pointer is over the tile whenever it is
       over the stretched hit box, so this fires for the padding too. */
    &:hover {
        background-color: map.get(c.$c-facts, "link-surface");
        color: map.get(c.$c-facts, "link-background");
    }

    @media (prefers-reduced-motion: no-preference) {
        transition:
            background-color ti.$c-facts ease-out,
            color ti.$c-facts ease-out;
    }

    /* The focus ring belongs to the TILE, not to the value inside it: the anchor's own box
       is just the text, while what a keyboard user sees highlighted has to be the thing
       they are about to activate — which the stretched pseudo-element made the whole tile.
       `:has()` is how the outline gets from the focused child onto the parent. */
    &:has(.fact-pair__value--link:focus-visible) {
        outline: map.get(s.$c-facts, "link-outline") solid currentcolor;
        outline-offset: map.get(s.$c-facts, "link-outline");
    }
}

/* The link itself is bare type — no blue, and no underline in any state — because the
   FILL is the affordance and the hover inversion above is the feedback. Both colours come
   from the tile (`inherit`), so the anchor never has an opinion of its own to keep in
   step.

   `::after` is the click target: it covers the tile, so the padding and the label
   activate the link too, without wrapping the label in the anchor (which would make the
   link's accessible name "ALBUM Luciferian Towers"). `border-radius: inherit` keeps the
   hit box off the tile's rounded corners. */
.fact-pair__value--link {
    color: inherit;

    text-decoration: none;

    &::after {
        position: absolute;
        inset: 0;

        border-radius: inherit;

        content: "";
    }
}
</style>
