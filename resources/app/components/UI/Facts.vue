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
 * It draws its OWN cards and its own auto-fit grid. It used to render through
 * Widget / WidgetGroup to inherit them, but a Widget is a browse-page card — it
 * carries a loader overlay, a refresh footer and a skeleton state, none of which a
 * static list of stored facts will ever use, and a detail page shouldn't be pinned
 * to the browse pages' component to get a box. It keeps the Widget's SURFACE (same
 * fill, border, radius and 300px grid floor, via tokens that mirror its picks), so a
 * detail page still reads as the same app as the listing it was reached from — but
 * not its chrome: the group title is bare type in the app's h2 ink rather than a
 * filled cyan→pink strip, because a page of stored facts should be quiet.
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

/** One row — `key` keys the v-for, `group` sorts it into a card, the two flags pick how the value is presented. */
export type Fact = {
    key: string;
    label: string;
    /** Raw-but-display-ready text; null (or "") drops the whole row. */
    value: string | null;
    /** Card title this row belongs under (already translated). Rows sharing one land in one card, in first-seen order. */
    group?: string;
    /** Give the value the full width of the card, on its own line under its label — for values too long for a column. */
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
     * Let a card holding a `wide` row span two grid columns. Opt-in, because it only
     * pays off when a group really carries something long — a file path — and would
     * otherwise leave a half-empty card.
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
 * Whether a card should span two columns: only when asked for, and only when it
 * actually holds a full-width row — so the span follows the content that needs it
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
                    <span class="facts__label">{{ fact.label }}</span>
                    <span
                        class="facts__value"
                        :class="{ 'facts__value--wide': fact.wide, 'facts__value--mono': fact.mono }"
                        >{{ fact.value }}</span
                    >
                </li>
            </ul>
        </div>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/typography" as t;

/* The card grid: as many equal columns as fit, each at least `group-min` wide but
   never wider than its row — `min(<group-min>, 100%)` keeps a lone card from
   overflowing a narrow viewport. `dense` backfills the gaps a `--wide` card leaves. */
.facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(#{map.get(s.$c-facts, "group-min")}, 100%), 1fr));
    grid-auto-flow: dense;

    gap: map.get(s.$c-facts, "group-gap");

    /* One card per group. It spans two implicit row tracks (title / rows) and subgrids
       into them, so the titles share a height across a row and the rows below start on
       the same line even when one group's title wraps and another's doesn't.
       `row-gap: 0` keeps the two bands flush — the title's and the list's own padding
       do the spacing; only the grid's own gap holds cards apart.

       Solid surface, no frosted glass: a detail page sits on a solid background, so
       there would be nothing behind it to blur. */
    &__card {
        display: grid;
        grid-template-rows: subgrid;
        grid-row: span 2;

        border: map.get(s.$c-facts, "card-border") solid map.get(c.$c-facts, "card-border");
        row-gap: 0;

        background-color: map.get(c.$c-facts, "card-background");
        color: map.get(c.$c-facts, "card-surface");
        border-radius: map.get(s.$c-facts, "card-radius");

        /* Opt-in `--wide`: span two columns, gated to `landscape` and up where the grid
           reliably fits two of its tracks — below that it is a single column, so
           spanning two would overflow. */
        &--wide {
            @include m.mq("landscape") {
                grid-column: span 2;
            }
        }

        /* An untitled group (the catch-all bucket, or an entirely ungrouped caller) has
           nothing to fill the first band, so its rows would sit in the title band and
           start a line higher than every titled card's. Push them into the second band
           to keep the row of cards aligned. */
        &:not(:has(> .facts__title)) > .facts__list {
            grid-row: 2;
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

    /* The rows: two columns, labels sized to their content. The UA list marker and
       margin are dropped (normalize.css leaves lists alone, so it happens here) and the
       card's padding is applied here rather than on a wrapper, so the list itself is
       the card's body.

       `align-content: start` is load-bearing, not tidiness. The list is a grid ITEM in
       the card's subgrid band, so it stretches to the tallest card in the row — and a
       grid container's default `align-content: normal` then stretches its own auto rows
       to fill that height, spreading a two-row card's rows down the whole card. (The
       Widget's body <div> used to absorb the stretch, so the list never saw it.) */
    &__list {
        display: grid;
        align-content: start;
        grid-template-columns: max-content 1fr;

        padding: map.get(s.$c-facts, "card-padding");
        margin: 0;
        gap: map.get(s.$c-facts, "row-gap") map.get(s.$c-facts, "column-gap");

        list-style: none;
    }

    /* Each fact is one <li> — so the markup stays a plain list — but its label and
       value have to line up with every *other* row's, which a per-item grid can't do
       (each row would size its own label column). `subgrid` is exactly that: the item
       spans both of the list's columns and borrows them, inheriting the column gutter
       from the parent too, so one wide label pushes the value column for all rows. */
    &__fact {
        display: grid;
        grid-template-columns: subgrid;
        grid-column: 1 / -1;

        /* Only bites on a --wide row, where the value wraps onto a second line. */
        row-gap: map.get(s.$c-facts, "row-gap");
    }

    /* Small letter-spaced caps in the muted label tint — the hi-fi spec-sheet look,
       which also lets the values stay the loud half of each row at plain body size. */
    &__label {
        color: map.get(c.$c-facts, "label");

        font-size: map.get(s.$c-facts, "label-font-size");
        text-transform: uppercase;
        letter-spacing: map.get(s.$c-facts, "label-tracking");
    }

    /* Tabular figures so digits in stacked rows (bit rate, sample rate, size, dates)
       line up in columns instead of jittering by glyph width. */
    &__value {
        font-variant-numeric: tabular-nums;

        /* Spans the whole row, so the value starts on its own line under its label, and
           breaks anywhere — a path has no spaces to wrap at and would otherwise push
           the grid wider than the viewport on a phone. */
        &--wide {
            grid-column: 1 / -1;

            overflow-wrap: anywhere;
        }

        /* Monospaced, a step down in size: a mono face at body size looks oversized
           beside the proportional text around it. */
        &--mono {
            font-family: map.get(t.$c-facts, "mono");
            font-size: map.get(s.$c-facts, "mono-font-size");
        }
    }
}
</style>
