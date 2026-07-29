<script setup lang="ts">
/******************************************************************************
 * Facts
 * The "everything we have stored about this thing" block: label/value rows laid
 * out as titled cards. Written for the song detail page but deliberately
 * generic, because the album / artist / genre pages show the same block with
 * different rows — the caller assembles the rows (translating labels and
 * locale-formatting values, which only it knows how to do), this component
 * groups and lays them out.
 *
 * It draws its OWN cards and their layout. It used to render through Widget /
 * WidgetGroup to inherit them, but a Widget is a browse-page card — it carries a
 * loader overlay, a refresh footer and a skeleton state, none of which a static list
 * of stored facts will ever use, and a detail page shouldn't be pinned to the browse
 * pages' component to get a box. It keeps the Widget's SURFACE (same fill, border,
 * radius, and the same ~300px minimum card width, via tokens that mirror its picks),
 * so a detail page still reads as the same app as the listing it was reached from —
 * but not its chrome: the group title is bare type in the app's h2 ink rather than a
 * filled cyan→pink strip, because a page of stored facts should be quiet.
 *
 * Two nested wrapping flex rows all the way down: cards wrap and share their line's
 * width, and inside each card the tiles do the same. Why flex and not grid, in both
 * cases, is spelled out at the rules below — it comes down to filling a line rather
 * than filling a track.
 *
 * Grouping is driven by each row's optional `group`, in order of first
 * appearance, so a caller just tags its rows and never assembles a nested
 * structure. Rows with no `group` collect into one untitled card, which is also
 * what an entirely ungrouped caller gets.
 *
 * Rows whose value is null or empty are dropped *here* rather than by every
 * caller, so a page can pass one fixed row list and let the holes fall out. That
 * is the common case, not an edge case: tags in a ripped collection are sparse,
 * and a page showing "Genre: —" a dozen times reads as broken rather than as
 * untagged. A group left empty by that filter disappears with them.
 *****************************************************************************/
import { computed } from "vue";
import Icon from "Components/UI/Icon.vue";

/** One fact — `key` keys the v-for, `group` sorts it into a card, the flags pick how the value is presented. */
export type Fact = {
    key: string;
    label: string;
    /** Raw-but-display-ready text; null (or "") drops the whole row. */
    value: string | null;
    /** Card title this row belongs under (already translated). Rows sharing one land in one card, in first-seen order. */
    group?: string;
    /** Sprite icon name for what KIND of fact this is, shown beside the label. Omit for none. */
    icon?: string;
    /**
     * Marks a fact as carrying something long — a file path. With `wideGroups` its whole
     * CARD takes a row to itself, so the value gets the room it needs. (Every value is
     * full-width inside its own tile regardless; this is about the card.)
     */
    wide?: boolean;
    /** Render the value monospaced — for values read character by character rather than as prose (paths, hashes). */
    mono?: boolean;
};

/** One card: its title (empty for the untitled catch-all group) and the rows that survived the filter. */
type FactGroup = { title: string; facts: Fact[] };

const props = defineProps<{
    /** The rows, in display order. Ones without a value are dropped — see the banner. */
    facts: Fact[];
    /**
     * Let a card holding a `wide` row take a whole row to itself. Opt-in, because it only
     * pays off when a group really carries something long — a file path — and would
     * otherwise leave a mostly-empty card stretched across the page.
     */
    wideGroups?: boolean;
}>();

/**
 * The cards to render: rows with something to say, bucketed by `group` in order
 * of first appearance. A Map keeps that order (it is insertion-ordered by
 * definition), which is what lets the caller's row order be the only thing
 * deciding the layout — there is no second list of group titles to keep in sync.
 *
 * A `computed` so the cards follow a caller whose rows are themselves reactive:
 * they are rebuilt on a locale switch, since labels, values AND group titles are
 * all locale-dependent.
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
 * actually holds a `wide` fact — so the extra width follows the content that needs it
 * rather than a group's position in the list.
 */
const spansWide = (group: FactGroup): boolean => props.wideGroups === true && group.facts.some(fact => fact.wide);
</script>

<template>
    <div class="facts">
        <div
            v-for="group in groups"
            :key="group.title"
            class="facts__card"
            :class="{ 'facts__card--wide': spansWide(group) }"
        >
            <!-- A real heading, not the <div> the Widget's title strip was: each group
                 is a section of the page's content, so it belongs in the heading
                 outline. h2 assumes the host page's own title is its h1 — true of the
                 song page, whose h1 lives in its hero. -->
            <h2 v-if="group.title" class="facts__title">{{ group.title }}</h2>
            <!-- role="list" because the list marker is styled away, and Safari/VoiceOver
                 drops list semantics from a list without markers. -->
            <ul class="facts__list" role="list">
                <li v-for="fact in group.facts" :key="fact.key" class="facts__fact">
                    <span class="facts__label">
                        <icon v-if="fact.icon" :name="fact.icon" :size="0" />
                        {{ fact.label }}
                    </span>
                    <span class="facts__value" :class="{ 'facts__value--mono': fact.mono }">{{ fact.value }}</span>
                </li>
            </ul>
        </div>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/typography" as t;

/* The cards wrap as a flex row, NOT an auto-fit grid, and the difference is the
   `--wide` card below. An auto-fit grid collapses tracks nothing is placed in, which is
   what used to keep three cards filling a four-track row — but a card spanning `1 / -1`
   occupies every track, so none collapse and the row ends in dead space the width of a
   card. Grid has no way to say "span the tracks that are actually used".

   Flex has no tracks to leave empty: every card carries the same `flex-basis`, so how
   many fit a line is still decided by `group-min`, and `flex-grow` then hands the
   leftover back to the cards on that line. Three cards on a line are three equal cards
   filling it, whatever the viewport. */
.facts {
    display: flex;
    flex-wrap: wrap;

    gap: map.get(s.$c-facts, "group-gap");

    /* One card per group, itself a column: title, then the tiles. Equal basis + equal
       grow is what makes the cards sharing a line equal in width; `align-items`'s default
       stretch makes them equal in height.

       Solid surface, no frosted glass: a detail page sits on a solid background, so
       there would be nothing behind it to blur. */
    &__card {
        display: flex;
        flex-direction: column;

        flex: 1 1 map.get(s.$c-facts, "group-min");

        border: map.get(s.$c-facts, "card-border") solid map.get(c.$c-facts, "card-border");

        background-color: map.get(c.$c-facts, "card-background");
        color: map.get(c.$c-facts, "card-surface");
        border-radius: map.get(s.$c-facts, "card-radius");

        /* Opt-in `--wide`: a basis of the whole line, so the card takes a row to itself at
           every width — no breakpoint needed, and no track left behind it. */
        &--wide {
            flex-basis: 100%;
        }
    }

    /* The group title: bare type on the card, no filled band and no rule under it. Its
       padding omits the bottom side on purpose — the list below brings its own top
       padding, and doubling the two would open a gap wider than the card's own inset.
       `margin: 0` because this is an <h2> and the spacing here is padding, not UA
       margins. */
    &__title {
        display: flex;
        align-items: center;

        padding: map.get(s.$c-facts, "card-padding") map.get(s.$c-facts, "card-padding") 0;
        margin: 0;
        gap: 0.5ch;

        color: map.get(c.$c-facts, "title-surface");

        font-size: map.get(s.$c-facts, "title-font-size");
        font-weight: 600;
    }

    /* Tiles flow and wrap, each only as wide as its own content — a flex row rather than
       a grid, because a grid would impose shared column widths and these tiles have
       nothing to line up with each other ("CD 1/1" has no business being as wide as an
       album title). Flex items size to max-content and shrink only when a line is full,
       which is exactly "as wide as the content dictates".

       The UA list marker and margin are dropped (normalize.css leaves lists alone, so it
       happens here) and the card's padding is applied here rather than on a wrapper, so
       the list itself is the card's body.

       `align-content: start` is load-bearing, not tidiness. `flex: 1` makes the list fill
       whatever height its card was stretched to (so cards sharing a line stay equal), and
       a wrapped flex container's default `align-content: normal` behaves as stretch —
       which would spread its lines of tiles down that whole height instead of leaving
       them packed at the top. `align-items` is left at its default, so tiles sharing a
       line share a height. */
    &__list {
        display: flex;
        align-content: start;
        flex-wrap: wrap;

        flex: 1;

        padding: map.get(s.$c-facts, "card-padding");
        margin: 0;
        gap: map.get(s.$c-facts, "tile-gap");

        list-style: none;
    }

    /* Each fact is a tile: one <li>, so the markup stays a plain list, washed and
       rounded, with its label stacked over its value. Stacking is what removes the old
       two-column/subgrid machinery entirely — there is no label column to align across
       rows any more, and no baseline to reconcile between two different type sizes
       sitting side by side, because they no longer sit side by side.

       `flex-grow: 1` over an `auto` basis: content still decides how the tiles on a line
       divide it up (a long album title takes more than "CD 1/1"), but the leftover space
       is handed back to them so every line reaches the card's edge instead of ending
       ragged. The trade is that a line holding few tiles — the last one, usually —
       stretches them wider than their content needs. */
    &__fact {
        display: flex;
        flex-grow: 1;
        flex-direction: column;

        padding: map.get(s.$c-facts, "item-padding");
        gap: map.get(s.$c-facts, "item-gap");

        background-color: map.get(c.$c-facts, "item-background");
        border-radius: map.get(s.$c-facts, "item-radius");
    }

    /* Small letter-spaced caps in the muted label tint — the hi-fi spec-sheet look, and
       the quiet half of the tile so the value below can be the loud one.

       A flex row because the label may carry an icon for the KIND of fact it is; the
       gap is set even when there is no icon, which costs nothing (flex gaps only apply
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

    /* The loud half of the tile: a step up in size from body text, which is what makes
       it read as the fact and the label as its caption — no colour needed. Tabular
       figures so digits in stacked tiles (bit rate, sample rate, size, dates) line up
       instead of jittering by glyph width.

       `overflow-wrap: anywhere` because values are mp3 tags, so an unbroken
       80-character token is a thing that happens (a German compound, a glued-together
       composer credit, a path). Without it the tile's min-content is that whole token
       and the card grows ~600px past its grid column — measured, not hypothetical.
       `anywhere` rather than `break-word` precisely because it also lowers min-content,
       which is what lets the tile shrink in the first place. */
    &__value {
        overflow-wrap: anywhere;

        font-size: map.get(s.$c-facts, "value-font-size");
        font-variant-numeric: tabular-nums;

        /* Monospaced, a step down from the value's own size: a mono face at the same
           size looks oversized beside the proportional text around it. */
        &--mono {
            font-family: map.get(t.$c-facts, "mono");
            font-size: map.get(s.$c-facts, "mono-font-size");
        }
    }
}
</style>
