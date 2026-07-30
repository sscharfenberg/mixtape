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
 *****************************************************************************/
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
}>();
</script>

<template>
    <li class="fact-pair">
        <span class="fact-pair__label">
            <icon v-if="icon" :name="icon" :size="0" />
            {{ label }}
        </span>
        <span class="fact-pair__value" :class="{ 'fact-pair__value--mono': mono }">{{ value }}</span>
    </li>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
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
</style>
