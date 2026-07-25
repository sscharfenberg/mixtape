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
/** Columns visible in card mode, excluding the primary. */
const cardColumns = computed(() => props.columns.filter(c => c.visibleInCard && c.key !== primaryCol.value?.key));
/** Card columns that actually have a non-empty value for the given row. */
function visibleCardColumns(row: T) {
    return cardColumns.value.filter(col => {
        const val = row[col.key as keyof T];
        return val !== null && val !== undefined && val !== "" && val !== 0;
    });
}
/** Navigate to the row's detail page when the card is tapped (only if href is set). */
function onCardClick(row: T) {
    if (row.href) router.visit(row.href);
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
            @click="row.href && onCardClick(row)"
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
@use "Abstracts/mixins" as m;

@layer components {
    /* Only visible in narrow containers — DataTable's container query hides the <table> there. */
    .dt-cards {
        display: none;
    }

    @include m.cq("desktop", $mobile-first: false) {
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
            }
        }

        .dt-cards__header {
            display: flex;
            align-items: center;

            margin-bottom: 0.5rem;

            gap: 1ch;
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
