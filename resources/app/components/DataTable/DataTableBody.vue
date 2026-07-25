<script setup lang="ts" generic="T extends { id: string; href?: string }">
/******************************************************************************
 * DataTableBody
 * The desktop <tbody> for DataTable: one <tr> per row, with per-column cells
 * (slot-driven via `cell-{key}`, falling back to the raw value), optional
 * selection checkbox, an optional three-dot actions button (emits up so the
 * shared popover anchors to it), and clickable rows when the row carries an
 * `href` (navigates to the detail page). Styling — zebra striping, cell borders,
 * rounded last-row corners — lives in the scoped block (c/s.$c-datatable).
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { inject, useSlots } from "vue";
import Checkbox from "Components/Form/Checkbox.vue";
import Icon from "Components/UI/Icon.vue";
import type { ColumnDef } from "Types/dataTable";
import { DATA_TABLE_KEY } from "Types/dataTable";
defineProps<{
    /** Column definitions controlling which cells to render per row. */
    columns: ColumnDef<T>[];
    /** Data rows for the current page. */
    rows: T[];
    /** Whether to render per-row selection checkboxes. */
    selectable: boolean;
    /** Whether to render the per-row three-dot actions button. */
    hasActions: boolean;
}>();
const emit = defineEmits<{
    /** Emitted when a row's three-dot action button is clicked. */
    action: [row: T, el: HTMLElement];
}>();
const provided = inject(DATA_TABLE_KEY)!;
const slots = useSlots();
/** Navigate to the row's detail page when the row is clicked (only if href is set). */
function onRowClick(row: T) {
    if (row.href) router.visit(row.href);
}
/** Emit the action event with the row + trigger button element for popover anchoring. */
function onActionClick(row: T, event: MouseEvent) {
    emit("action", row, event.currentTarget as HTMLElement);
}
</script>

<template>
    <tbody class="dt-body">
        <tr
            v-for="row in rows"
            :key="row.id"
            :class="{ 'dt-body__row--clickable': !!row.href }"
            @click="row.href && onRowClick(row)"
        >
            <td v-if="selectable" class="dt-body__check" @click.stop>
                <checkbox
                    :ref-id="`dt-select-${row.id}`"
                    :model-value="provided.selectedIds.value.includes(row.id)"
                    :label="$t('components.datatable.select_row')"
                    @update:model-value="provided.toggleSelection(row.id)"
                />
            </td>
            <td v-for="col in columns" :key="col.key" :class="col.cellClass" :style="{ textAlign: col.align ?? 'left' }">
                <slot v-if="slots[`cell-${col.key}`]" :name="`cell-${col.key}`" :row="row" />
                <template v-else>{{ row[col.key] }}</template>
            </td>
            <td v-if="hasActions" class="dt-body__actions">
                <button
                    type="button"
                    class="popover-button popover-button--rounded"
                    :style="{ 'anchor-name': `--dt-action-${row.id}` }"
                    @click.stop="onActionClick(row, $event)"
                    :aria-label="$t('components.datatable.row_actions')"
                >
                    <icon name="more" />
                </button>
            </td>
        </tr>
    </tbody>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

@layer components {
    .dt-body {
        &__row--clickable {
            cursor: pointer;
        }

        &__check {
            width: 2rem;

            vertical-align: middle;
        }

        &__actions {
            width: 3rem;

            button {
                cursor: pointer;
            }
        }

        td {
            padding: map.get(s.$c-datatable, "padding", "td");

            color: map.get(c.$c-datatable, "td", "surface");

            &.no-padding {
                padding: 0;
            }

            &:not(:last-child) {
                border-right: map.get(s.$c-datatable, "border") solid map.get(c.$c-datatable, "border");
            }
        }

        tr:nth-child(odd) td {
            background-color: map.get(c.$c-datatable, "td", "background", "odd");
        }

        tr:nth-child(even) td {
            background-color: map.get(c.$c-datatable, "td", "background", "even");
        }

        tr:not(:last-child) td {
            border-bottom: map.get(s.$c-datatable, "border") solid map.get(c.$c-datatable, "border");
        }

        tr:last-child {
            td:first-child {
                border-bottom-left-radius: calc(
                    #{map.get(s.$c-datatable, "radius")} - #{map.get(s.$c-datatable, "border")}
                );
            }

            td:last-child {
                border-bottom-right-radius: calc(
                    #{map.get(s.$c-datatable, "radius")} - #{map.get(s.$c-datatable, "border")}
                );
            }
        }
    }
}
</style>
