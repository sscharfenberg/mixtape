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
 * It renders through Widget / WidgetGroup rather than inventing a card: those
 * already give the responsive auto-fit grid, the solid card surface and the
 * cyan→pink gradient title strip the browse pages use, so a detail page reads as
 * the same app as the listing it was reached from — and any later change to the
 * card look lands here for free.
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
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetGroup from "Components/UI/Widget/WidgetGroup.vue";

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
     * Let a card holding a `wide` row span two grid columns (Widget's own `wide`).
     * Opt-in, because it only pays off when a group really carries something long —
     * a file path — and would otherwise leave a half-empty card.
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
    <widget-group>
        <widget v-for="group in groups" :key="group.title" :wide="spansWide(group)">
            <template v-if="group.title" #title>{{ group.title }}</template>
            <!-- role="list" because the list marker is styled away, and Safari/VoiceOver
                 drops list semantics from a list without markers. -->
            <ul class="facts" role="list">
                <li v-for="fact in group.facts" :key="fact.key" class="facts__fact">
                    <span class="facts__label">{{ fact.label }}</span>
                    <span
                        class="facts__value"
                        :class="{ 'facts__value--wide': fact.wide, 'facts__value--mono': fact.mono }"
                        >{{ fact.value }}</span
                    >
                </li>
            </ul>
        </widget>
    </widget-group>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/typography" as t;

/* Layout only — the one colour is the label tint, so values inherit the card's
   text colour and keep following the theme. Gutters come from s.$c-facts. The UA
   list padding/margin is zeroed because the grid's own gap does all the spacing;
   normalize.css leaves lists alone, so this is where it happens. */
.facts {
    display: grid;
    grid-template-columns: max-content 1fr;

    padding: 0;
    margin: 0;
    gap: map.get(s.$c-facts, "row-gap") map.get(s.$c-facts, "column-gap");

    list-style: none;
}

/* Each fact is one <li> — so the markup stays a plain list — but its label and
   value have to line up with every *other* row's, which a per-item grid can't do
   (each row would size its own label column). `subgrid` is exactly that: the item
   spans both of the list's columns and borrows them, inheriting the column gutter
   from the parent too, so one wide label pushes the value column for all rows. */
.facts__fact {
    display: grid;
    grid-template-columns: subgrid;
    grid-column: 1 / -1;

    /* Only bites on a --wide row, where the value wraps onto a second line. */
    row-gap: map.get(s.$c-facts, "row-gap");
}

/* Small letter-spaced caps in the muted label tint — the hi-fi spec-sheet look,
   which also lets the values stay the loud half of each row at plain body size. */
.facts__label {
    color: map.get(c.$c-facts, "label");

    font-size: map.get(s.$c-facts, "label-font-size");
    text-transform: uppercase;
    letter-spacing: map.get(s.$c-facts, "label-tracking");
}

/* Tabular figures so digits in stacked rows (bit rate, sample rate, size, dates)
   line up in columns instead of jittering by glyph width. */
.facts__value {
    font-variant-numeric: tabular-nums;
}

/* Spans the whole row, so the value starts on its own line under its label, and
   breaks anywhere — a path has no spaces to wrap at and would otherwise push the
   grid wider than the viewport on a phone. */
.facts__value--wide {
    grid-column: 1 / -1;

    overflow-wrap: anywhere;
}

.facts__value--mono {
    font-family: map.get(t.$c-facts, "mono");
    font-size: map.get(s.$c-facts, "mono-font-size");
}
</style>
