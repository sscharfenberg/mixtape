<script setup lang="ts" generic="T extends { id: string }">
/******************************************************************************
 * DataTableHead
 * The sticky <thead> for DataTable: sortable column buttons with aria-sort, an
 * optional select-all header checkbox (three-state: none / some / all), and an
 * actions-column spacer. Sort clicks emit up to DataTable (which owns the URL
 * navigation). "Stuck" is passed in from DataTable's IntersectionObserver so the
 * header can restyle (drop its top corner radius, brand tint) once affixed.
 * Header content is slot-driven per column (`header-{key}`), falling back to the
 * column label; styling lives in the scoped block (c/s.$c-datatable + z.$c-main).
 * Sortable headers carry a `v-tooltip` hint (the directive, not the wrapper
 * component — a `<th>`'s button is sized to fill the cell and can't take a
 * wrapper span) naming the direction the next click applies. Placed above the
 * header on purpose: below it, the tip would cover the first rows of the very
 * data the user is about to sort.
 *
 * A column can also be marked ascending WITHOUT being the sorted column: the server
 * reports its `tiebreakers`, the extra keys it orders by afterwards, and those get the
 * same marker because they are equally true — an album's tracks are ordered by disc and
 * then track, so showing only "disc" would understate it. They stay out of the sort
 * state proper, though, so clicking one sorts by it from scratch (see isTiebreaker).
 *****************************************************************************/
import { computed, inject } from "vue";
import { useI18n } from "vue-i18n";
import Checkbox from "Components/Form/Checkbox.vue";
import type { ColumnDef, SortEntry } from "Types/dataTable";
import { DATA_TABLE_KEY } from "Types/dataTable";
const props = defineProps<{
    /** Column definitions for rendering header cells. */
    columns: ColumnDef<T>[];
    /** Current sort state, used to display sort indicators. */
    sort: SortEntry[];
    /**
     * Column keys the server is ordering by AFTER the chosen sort, always ascending.
     * Marked exactly like the primary, because on screen they are just as true — an
     * album's tracks really are ordered by disc AND track — but kept out of `sort` so
     * clicking one still sorts by it from scratch rather than toggling to descending.
     */
    tiebreakers: string[];
    /** Whether to render the select-all checkbox column. */
    selectable: boolean;
    /** Whether to render the per-row actions column. */
    hasActions: boolean;
    /** All row IDs on the current page, for the select-all checkbox logic. */
    rowIds: string[];
    /** True when the header is in its sticky (scrolled) state. */
    stuck: boolean;
}>();
const emit = defineEmits<{
    /** Emitted with the column key when a sortable header is clicked. */
    sort: [key: string];
}>();
const provided = inject(DATA_TABLE_KEY)!;
const { t } = useI18n();
/** Determine the aria-sort attribute for a column (undefined when not sortable). */
function ariaSort(col: ColumnDef<T>): "ascending" | "descending" | "none" | undefined {
    if (!col.sortable) return undefined;
    const entry = props.sort.find(s => s.key === col.key);
    if (!entry) return "none";
    return entry.direction === "asc" ? "ascending" : "descending";
}
/** Current sort direction for a column, or null if it isn't the sorted column. */
function sortDir(col: ColumnDef<T>): "asc" | "desc" | null {
    const entry = props.sort.find(s => s.key === col.key);
    return entry?.direction ?? null;
}
/**
 * Whether this column is one of the server's tiebreakers — ordering the table after the
 * chosen sort, and so shown with the same ascending marker.
 *
 * Deliberately NOT folded into sortDir(): that function also drives `sortHint` and, via
 * DataTable.onSort, the asc→desc toggle. A tiebreak column reading as "currently
 * ascending" there would make its first click jump straight to descending, which is not
 * what a reader who has never sorted by it expects.
 */
function isTiebreaker(col: ColumnDef<T>): boolean {
    return sortDir(col) === null && props.tiebreakers.includes(col.key);
}
/**
 * Hover hint for a sortable header: the column name, then the direction the
 * *next* click will apply. Mirrors DataTable.onSort's toggle (unsorted → asc,
 * asc → desc, desc → asc), so the hint can never promise the wrong direction.
 */
function sortHint(col: ColumnDef<T>): string {
    const next = sortDir(col) === "asc" ? "desc" : "asc";
    return t(`components.datatable.sort_hint_${next}`, { column: col.label });
}
/** Header checkbox state: true = all selected, false = none, 'indeterminate' = some. */
const headerCheckState = computed(() => {
    if (props.rowIds.length === 0) return false;
    const selectedOnPage = props.rowIds.filter(id => provided.selectedIds.value.includes(id));
    if (selectedOnPage.length === 0) return false;
    if (selectedOnPage.length === props.rowIds.length) return true;
    return "indeterminate";
});
/** Toggle select-all: selects every row on the page, or clears if all already selected. */
function onHeaderCheckbox() {
    provided.togglePageSelection(props.rowIds);
}
</script>

<template>
    <thead class="dt-head" :class="{ 'dt-head--stuck': stuck }">
        <tr>
            <th v-if="selectable" class="dt-head__check" @click.stop>
                <checkbox
                    ref-id="dt-select-all"
                    :model-value="headerCheckState === true"
                    :indeterminate="headerCheckState === 'indeterminate'"
                    :label="$t('components.datatable.select_all')"
                    @update:model-value="onHeaderCheckbox"
                />
            </th>
            <th
                v-for="col in columns"
                :key="col.key"
                :style="{ width: col.width ?? 'auto' }"
                :aria-sort="ariaSort(col)"
            >
                <button
                    v-if="col.sortable"
                    v-tooltip:top="sortHint(col)"
                    type="button"
                    class="dt-head__sort-btn"
                    :class="{
                        'dt-head__sort-btn--asc': sortDir(col) === 'asc' || isTiebreaker(col),
                        'dt-head__sort-btn--desc': sortDir(col) === 'desc'
                    }"
                    @click="emit('sort', col.key)"
                    :style="{ textAlign: col.align ?? 'left' }"
                    :aria-label="$slots[`header-${col.key}`] ? col.label : undefined"
                >
                    <slot :name="`header-${col.key}`" :column="col">{{ col.label }}</slot>
                </button>
                <span v-else :style="{ textAlign: col.align ?? 'left' }">
                    <slot :name="`header-${col.key}`" :column="col">{{ col.label }}</slot>
                </span>
                <!-- A tiebreak column's state, said rather than only drawn — and placed
                     OUTSIDE the button on purpose. Inside, it joined the button's
                     accessible name ("Track Nach Track aufsteigend sortiert"), which
                     describes the state in the name of the action; out here the button
                     stays "Track" and the cell still carries the fact. `aria-sort` is not
                     used for it, because ARIA asks for one sorted column at a time. -->
                <span v-if="isTiebreaker(col)" class="sr-only">
                    {{ $t("components.datatable.sorted_asc", { column: col.label }) }}
                </span>
            </th>
            <th v-if="hasActions" class="dt-head__actions">
                <span class="sr-only">{{ $t("components.datatable.actions") }}</span>
            </th>
        </tr>
    </thead>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/z-indexes" as z;

@layer components {
    .dt-head {
        position: sticky;
        top: var(--datatable-sticky-offset, 0);
        z-index: z.$c-main;

        &__check {
            width: 2rem;
        }

        &__sort-btn {
            position: relative;

            padding-right: 1.25rem !important; /* space for the sort triangles */

            &::before,
            &::after {
                position: absolute;
                right: 0.4rem;

                opacity: 0.3;

                width: 0;
                height: 0;
                border-right: 0.3125rem solid transparent;
                border-left: 0.3125rem solid transparent;

                content: "";
            }

            /* Asc triangle (pointing up) — top */
            &::before {
                top: calc(50% - 0.465rem);

                border-bottom: 0.375rem solid currentcolor;
            }

            /* Desc triangle (pointing down) — bottom */
            &::after {
                bottom: calc(50% - 0.465rem);

                border-top: 0.375rem solid currentcolor;
            }

            &--asc::before {
                opacity: 1;
            }

            &--desc::after {
                opacity: 1;
            }
        }

        &__actions {
            width: 3rem;
        }

        th {
            border-bottom: map.get(s.$c-datatable, "border") solid map.get(c.$c-datatable, "border");

            background-color: map.get(c.$c-datatable, "th", "background");

            text-align: left;

            &:not(:last-child) {
                border-right: map.get(s.$c-datatable, "border") solid map.get(c.$c-datatable, "border");
            }

            &:first-child {
                border-top-left-radius: calc(
                    #{map.get(s.$c-datatable, "radius")} - #{map.get(s.$c-datatable, "border")}
                );
            }

            &:last-child {
                border-top-right-radius: calc(
                    #{map.get(s.$c-datatable, "radius")} - #{map.get(s.$c-datatable, "border")}
                );
            }

            &:not(:has(button)) {
                padding: map.get(s.$c-datatable, "padding", "th");
            }

            button {
                width: 100%;
                height: 100%;
                padding: map.get(s.$c-datatable, "padding", "th");
                border: 0;
                gap: 0.25rem;

                background: transparent;

                cursor: pointer;
            }

            button,
            span {
                color: map.get(c.$c-datatable, "th", "surface");

                font-weight: normal;
            }
        }

        &--stuck {
            th:first-child {
                border-top-left-radius: 0;
            }

            th:last-child {
                border-top-right-radius: 0;
            }

            th {
                // local bump above the head's own resting content while affixed
                z-index: 2;

                background-color: map.get(c.$c-datatable, "th", "background-stuck");
                color: map.get(c.$c-datatable, "th", "surface-stuck");
            }
        }
    }
}
</style>
