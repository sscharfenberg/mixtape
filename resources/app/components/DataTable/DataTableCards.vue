<script setup lang="ts" generic="T extends { id: string; href?: string }">
/******************************************************************************
 * DataTableCards
 * The mobile card layout for DataTable — shown instead of the <table> when the
 * component's CONTAINER is narrower than the datatable breakpoint (both are in
 * the DOM; a container query toggles which is visible). Each card shows the
 * `cardPrimary` column as its heading and the other `visibleInCard` columns as a
 * label/value list, skipping empty values. Cell slots (`cell-{key}`) render
 * identically to the desktop table. Selection + three-dot actions mirror the
 * body. Styling (the card grid, only revealed under the container query) lives in
 * the scoped block (c/s.$c-datatable "cards").
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { computed, inject, useSlots } from "vue";
import { isRowNavigation } from "Components/DataTable/rowNavigation";
import Checkbox from "Components/Form/Checkbox.vue";
import Icon from "Components/UI/Icon.vue";
import type { ColumnDef } from "Types/dataTable";
import { DATA_TABLE_KEY } from "Types/dataTable";
const props = defineProps<{
    /** Column definitions, used to determine card primary field and visible fields. */
    columns: ColumnDef<T>[];
    /** Data rows for the current page. */
    rows: T[];
    /** Whether to render per-card selection checkboxes. */
    selectable: boolean;
    /** Whether to render the per-card three-dot actions button. */
    hasActions: boolean;
}>();
const emit = defineEmits<{
    /** Emitted when a card's three-dot action button is clicked. */
    action: [row: T, el: HTMLElement];
}>();
const provided = inject(DATA_TABLE_KEY)!;
const slots = useSlots();
/** The primary column shown at the top of each card. First match wins. */
const primaryCol = computed(() => props.columns.find(c => c.cardPrimary) ?? null);
/**
 * The column rendered as the card's leading artwork rather than as a field. First match
 * wins. A desktop table shows this as a column of its own, which is exactly why a card
 * needs it POSITIONED instead: "Cover: <img>" as a row of a label/value list reads worse
 * than no thumbnail at all — see the note on the excluded key below.
 */
const mediaCol = computed(() => props.columns.find(c => c.cardMedia) ?? null);
/**
 * Columns rendered as the card's label/value fields — everything `visibleInCard` that
 * isn't already placed somewhere else. Both the primary and the media column are
 * excluded by key, since a card would otherwise show each of them twice.
 */
const cardColumns = computed(() =>
    props.columns.filter(c => c.visibleInCard && c.key !== primaryCol.value?.key && c.key !== mediaCol.value?.key)
);
/** Card columns that actually have a non-empty value for the given row. */
function visibleCardColumns(row: T) {
    return cardColumns.value.filter(col => {
        const val = row[col.key as keyof T];
        return val !== null && val !== undefined && val !== "" && val !== 0;
    });
}
/**
 * Navigate to the row's detail page when the card is tapped. Shares the row-click
 * guard with DataTableBody, so a tap on the card's checkbox / actions button / the
 * title's link doesn't also navigate — see rowNavigation.ts.
 */
function onCardClick(row: T, event: MouseEvent) {
    if (row.href && isRowNavigation(event)) router.visit(row.href);
}
/** Emit the action event with the row + trigger button for popover anchoring. */
function onActionClick(row: T, event: MouseEvent) {
    emit("action", row, event.currentTarget as HTMLElement);
}
</script>

<template>
    <div class="dt-cards">
        <article
            v-for="row in rows"
            :key="row.id"
            class="dt-cards__card"
            :class="{ 'dt-cards__card--clickable': !!row.href }"
            @click="onCardClick(row, $event)"
        >
            <div class="dt-cards__header">
                <div v-if="selectable" class="dt-cards__check" @click.stop>
                    <checkbox
                        :ref-id="`dt-card-select-${row.id}`"
                        :model-value="provided.selectedIds.value.includes(row.id)"
                        :label="$t('components.datatable.select_row')"
                        @update:model-value="provided.toggleSelection(row.id)"
                    />
                </div>
                <!-- Artwork, before the heading it belongs to. Rendered through the
                     column's OWN `cell-` slot, so the page writes the <img> (and its
                     placeholder) once and both layouts show the same thing. Sized by
                     the page too — slot content keeps its parent's scope, so the
                     caller's own class applies. -->
                <div v-if="mediaCol && slots[`cell-${mediaCol.key}`]" class="dt-cards__media">
                    <slot :name="`cell-${mediaCol.key}`" :row="row" />
                </div>
                <div v-if="primaryCol" class="dt-cards__primary">
                    <slot v-if="slots[`cell-${primaryCol.key}`]" :name="`cell-${primaryCol.key}`" :row="row" />
                    <template v-else>{{ row[primaryCol.key] }}</template>
                </div>
                <button
                    v-if="hasActions"
                    type="button"
                    class="dt-cards__action popover-button popover-button--rounded"
                    :style="{ 'anchor-name': `--dt-action-${row.id}` }"
                    @click.stop="onActionClick(row, $event)"
                    :aria-label="$t('components.datatable.row_actions')"
                >
                    <icon name="more" />
                </button>
            </div>
            <dl class="dt-cards__fields">
                <template v-for="col in visibleCardColumns(row)" :key="col.key">
                    <dt class="dt-cards__label">{{ col.label }}</dt>
                    <dd class="dt-cards__value">
                        <slot v-if="slots[`cell-${col.key}`]" :name="`cell-${col.key}`" :row="row" />
                        <template v-else>{{ row[col.key] }}</template>
                    </dd>
                </template>
            </dl>
        </article>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/mixins" as m;

@layer components {
    /* Only visible in narrow containers — DataTable's container query hides the <table> there. */
    .dt-cards {
        display: none;
    }

    @include m.cq(map.get(s.$c-datatable, "breakpoint"), $mobile-first: false) {
        .dt-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(map.get(s.$c-datatable, "cards", "min-width"), 1fr));

            gap: map.get(s.$c-datatable, "cards", "gap");
        }

        .dt-cards__card {
            padding: map.get(s.$c-datatable, "cards", "padding");
            border: map.get(s.$c-datatable, "cards", "border") solid map.get(c.$c-datatable, "cards", "border");

            background-color: map.get(c.$c-datatable, "cards", "background");
            color: map.get(c.$c-datatable, "cards", "surface");
            border-radius: map.get(s.$c-datatable, "cards", "radius");

            &--clickable {
                cursor: pointer;

                // Same fill + neon halo as the desktop row, so a hybrid device that
                // both hovers and taps sees one behaviour. `position: relative` for
                // the same reason as there: the halo has to paint over the sibling
                // cards that come after it in the grid. On a touch-only device
                // :hover never fires — the tap navigates instead.
                &:hover {
                    position: relative;

                    background-color: map.get(c.$c-datatable, "row-hover");
                    box-shadow:
                        0 0 0.6em 0.1em map.get(c.$c-datatable, "row-glow"),
                        0 0 1.5em 0.25em map.get(c.$c-datatable, "row-glow");
                }

                @media (prefers-reduced-motion: no-preference) {
                    transition:
                        background-color ti.$c-datatable ease-out,
                        box-shadow ti.$c-datatable ease-out;
                }
            }
        }

        .dt-cards__header {
            display: flex;
            align-items: center;

            margin-bottom: 0.5rem;

            gap: 1ch;
        }

        /* Holds whatever the page slotted in at its own size — no dimensions here, since
           a media column could be artwork, an avatar or a status glyph. `flex-shrink: 0`
           so a long heading beside it crops the TEXT rather than squashing the picture,
           and `display: flex` to kill the inline baseline gap under an <img>. */
        .dt-cards__media {
            display: flex;
            flex-shrink: 0;
        }

        .dt-cards__primary {
            flex: 1;

            font-weight: 600;
        }

        .dt-cards__action {
            cursor: pointer;
        }

        .dt-cards__fields {
            display: grid;
            grid-template-columns: minmax(30%, auto) 1fr;

            margin: 0;
            gap: 0.25rem 0.5rem;
        }

        .dt-cards__label {
            display: flex;
            align-items: center;
        }

        .dt-cards__value {
            margin: 0;
        }
    }
}
</style>
