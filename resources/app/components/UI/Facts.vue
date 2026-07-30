<script setup lang="ts">
/******************************************************************************
 * Facts
 * A collection of grouped Cards holding key/value pairs — "everything we have
 * stored about this thing". The caller assembles the pairs (translating labels and
 * locale-formatting values, which only it knows how to do) and tags each with a
 * group; this buckets them and renders one Card per group, so no caller ever
 * assembles a nested structure.
 *
 * It owns the PAIRS and nothing else: CardGroup lays out the row, Card draws the
 * panel and its title. Written for the song detail page, and generic by
 * construction, because the album / artist / genre pages show the same block with
 * different pairs.
 *
 * Each pair is a tile — a washed panel with its label above its value — that is only
 * as wide as its own content, wrapping within the card. A flex row, not a grid, on
 * purpose: a grid imposes shared column widths and these tiles have nothing to line
 * up with each other ("CD 1/1" has no business being as wide as an album title).
 *
 * Pairs whose value is null or empty are dropped *here* rather than by every caller,
 * so a page can pass one fixed list and let the holes fall out. That is the common
 * case, not an edge case: tags in a ripped collection are sparse, and a page showing
 * "Genre: —" a dozen times reads as broken rather than as untagged. A group left
 * empty by that filter disappears with them.
 *****************************************************************************/
import { computed } from "vue";
import Card from "Components/UI/Card/Card.vue";
import CardGroup from "Components/UI/Card/CardGroup.vue";
import Icon from "Components/UI/Icon.vue";

/** One key/value pair — `key` keys the v-for, `group` sorts it into a card, the flags pick how the value is presented. */
export type Fact = {
    key: string;
    label: string;
    /** Raw-but-display-ready text; null (or "") drops the whole pair. */
    value: string | null;
    /** Card title this pair belongs under (already translated). Pairs sharing one land in one card, in first-seen order. */
    group?: string;
    /** Sprite icon name for what KIND of fact this is, shown beside the label. Omit for none. */
    icon?: string;
    /**
     * Marks a pair as carrying something long — a file path. With `wideGroups` its whole
     * CARD takes a row to itself, so the value gets the room it needs. (Every value is
     * full-width inside its own tile regardless; this is about the card.)
     */
    wide?: boolean;
    /** Render the value monospaced — for values read character by character rather than as prose (paths, hashes). */
    mono?: boolean;
};

/** One card's worth: its title (empty for the untitled catch-all group) and the pairs that survived the filter. */
type FactGroup = { title: string; facts: Fact[] };

const props = defineProps<{
    /** The pairs, in display order. Ones without a value are dropped — see the banner. */
    facts: Fact[];
    /**
     * Let a card holding a `wide` pair take a whole row to itself. Opt-in, because it
     * only pays off when a group really carries something long — a file path — and would
     * otherwise leave a mostly-empty card stretched across the page.
     */
    wideGroups?: boolean;
}>();

/**
 * The cards to render: pairs with something to say, bucketed by `group` in order of
 * first appearance. A Map keeps that order (it is insertion-ordered by definition),
 * which is what lets the caller's own order be the only thing deciding the layout —
 * there is no second list of group titles to keep in sync.
 *
 * A `computed` so the cards follow a caller whose pairs are themselves reactive: they
 * are rebuilt on a locale switch, since labels, values AND group titles are all
 * locale-dependent.
 */
const groups = computed<FactGroup[]>(() => {
    const buckets = new Map<string, Fact[]>();

    for (const fact of props.facts) {
        if (fact.value === null || fact.value === "") continue;

        const title = fact.group ?? "";
        const bucket = buckets.get(title);

        if (bucket) bucket.push(fact);
        else buckets.set(title, [fact]);
    }

    return [...buckets].map(([title, facts]) => ({ title, facts }));
});

/**
 * Whether a card should take a row to itself: only when asked for, and only when it
 * actually holds a `wide` pair — so the extra width follows the content that needs it
 * rather than a group's position in the list.
 */
const spansWide = (group: FactGroup): boolean => props.wideGroups === true && group.facts.some(fact => fact.wide);
</script>

<template>
    <card-group>
        <card v-for="group in groups" :key="group.title" :title="group.title || undefined" :wide="spansWide(group)">
            <!-- role="list" because the list marker is styled away, and Safari/VoiceOver
                 drops list semantics from a list without markers. -->
            <ul class="facts" role="list">
                <li v-for="fact in group.facts" :key="fact.key" class="facts__pair">
                    <span class="facts__label">
                        <icon v-if="fact.icon" :name="fact.icon" :size="0" />
                        {{ fact.label }}
                    </span>
                    <span class="facts__value" :class="{ 'facts__value--mono': fact.mono }">{{ fact.value }}</span>
                </li>
            </ul>
        </card>
    </card-group>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/typography" as t;

/* Tiles flow and wrap, each only as wide as its own content. Flex items size to
   max-content and shrink only when a line is full, which is exactly that.

   The UA list marker and margin are dropped (normalize.css leaves lists alone, so it
   happens here); the padding around the whole set is the Card body's, not ours.

   `align-content: start` is load-bearing, not tidiness. Card stretches its body — and
   this list with it — to the tallest card on the line, and a wrapped flex container's
   default `align-content: normal` behaves as stretch, which would spread its lines of
   tiles down that whole height instead of leaving them packed at the top.
   `align-items` is left at its default, so tiles sharing a line share a height. */
.facts {
    display: flex;
    align-content: start;
    flex-wrap: wrap;

    padding: 0;
    margin: 0;
    gap: map.get(s.$c-facts, "gap");

    list-style: none;

    /* One pair, stacked: label over value. Stacking is why there is no label column to
       align across tiles and no baseline to reconcile between the two type sizes — they
       are not side by side.

       `flex-grow: 1` over an `auto` basis: content still decides how the tiles on a line
       divide it up (a long album title takes more than "CD 1/1"), but the leftover space
       is handed back to them so every line reaches the card's edge instead of ending
       ragged. The trade is that a line holding few tiles — the last one, usually —
       stretches them wider than their content needs. */
    &__pair {
        display: flex;
        flex-grow: 1;
        flex-direction: column;

        padding: map.get(s.$c-facts, "tile-padding");
        gap: map.get(s.$c-facts, "pair-gap");

        background-color: map.get(c.$c-facts, "tile-background");
        border-radius: map.get(s.$c-facts, "tile-radius");
    }

    /* Small letter-spaced caps in the muted label tint — the hi-fi spec-sheet look, and
       the quiet half of the tile so the value below can be the loud one.

       A flex row because the label may carry an icon for the KIND of fact it is; the gap
       is set even when there is no icon, which costs nothing (flex gaps only apply
       between items) and means adding one never shifts the text. */
    &__label {
        display: flex;
        align-items: center;

        gap: map.get(s.$c-facts, "label-icon-gap");

        color: map.get(c.$c-facts, "label");

        font-size: map.get(s.$c-facts, "label-font-size");
        text-transform: uppercase;
        letter-spacing: map.get(s.$c-facts, "label-tracking");
    }

    /* The loud half of the tile: a step up in size from body text, which is what makes it
       read as the fact and the label as its caption — no colour needed. Tabular figures
       so digits in stacked tiles (bit rate, sample rate, size, dates) line up instead of
       jittering by glyph width.

       `overflow-wrap: anywhere` because values are file tags, so an unbroken
       80-character token is a thing that happens (a German compound, a glued-together
       composer credit, a path). Without it the tile's min-content is that whole token and
       the card grows ~600px past its line — measured, not hypothetical. `anywhere` rather
       than `break-word` precisely because it also lowers min-content, which is what lets
       the tile shrink in the first place. */
    &__value {
        overflow-wrap: anywhere;

        font-size: map.get(s.$c-facts, "value-font-size");
        font-variant-numeric: tabular-nums;

        /* Monospaced, a step down from the value's own size: a mono face at the same size
           looks oversized beside the proportional text around it. */
        &--mono {
            font-family: map.get(t.$c-facts, "mono");
            font-size: map.get(s.$c-facts, "mono-font-size");
        }
    }
}
</style>
