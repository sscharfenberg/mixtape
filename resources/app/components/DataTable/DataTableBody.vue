<script setup lang="ts" generic="T extends { id: string; href?: string }">
/******************************************************************************
 * DataTableBody
 * The desktop <tbody> for DataTable: one <tr> per row, with per-column cells
 * (slot-driven via `cell-{key}`, falling back to the raw value), optional
 * selection checkbox, an optional three-dot actions button (emits up so the
 * shared popover anchors to it), and clickable rows when the row carries an
 * `href` (navigates to the detail page — prefetched on hover, see onRowEnter).
 * Styling — zebra striping, cell borders, rounded last-row corners, the
 * clickable row's hover wash — lives in the scoped block (c/s/ti.$c-datatable).
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { inject, onUnmounted, useSlots } from "vue";
import { isRowNavigation } from "Components/DataTable/rowNavigation";
import Checkbox from "Components/Form/Checkbox.vue";
import Icon from "Components/UI/Icon.vue";
import type { ColumnDef } from "Types/dataTable";
import { DATA_TABLE_KEY } from "Types/dataTable";

// How long the pointer must rest on a row before its page is fetched. This is
// Inertia's own default for <Link prefetch> (config `prefetch.hoverDelay`),
// matched deliberately so a row and a link inside one behave identically — and
// so sweeping the pointer down a 25-row table fires nothing.
const HOVER_INTENT = 75;
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
let prefetchTimer: ReturnType<typeof setTimeout> | undefined;
/**
 * Navigate to the row's detail page when the row is clicked. `isRowNavigation`
 * filters out the clicks that only *look* like row clicks — on a control inside a
 * cell, at the end of a drag-select, or with a modifier held; see rowNavigation.ts.
 */
function onRowClick(row: T, event: MouseEvent) {
    if (row.href && isRowNavigation(event)) router.visit(row.href);
}
/**
 * Fetch the row's detail page while the pointer is resting on it, so the click
 * that usually follows already has its response in hand — no progress bar, no
 * wait, just the new page. A row is the app's main way into a detail page, and
 * it is a `router.visit` rather than a <Link>, so it gets none of Inertia's
 * built-in link prefetching for free. Inertia caches the response for 30s and
 * dedupes repeats, so re-entering a row costs nothing.
 */
function onRowEnter(row: T) {
    const href = row.href;
    if (!href) return;
    clearTimeout(prefetchTimer);
    prefetchTimer = setTimeout(() => router.prefetch(href), HOVER_INTENT);
}
/** Drop a prefetch the pointer left before the intent delay was up — including one still pending at unmount. */
function cancelPrefetch() {
    clearTimeout(prefetchTimer);
}
onUnmounted(cancelPrefetch);
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
            @click="onRowClick(row, $event)"
            @mouseenter="onRowEnter(row)"
            @mouseleave="cancelPrefetch"
        >
            <td v-if="selectable" class="dt-body__check" @click.stop>
                <checkbox
                    :ref-id="`dt-select-${row.id}`"
                    :model-value="provided.selectedIds.value.includes(row.id)"
                    :label="$t('components.datatable.select_row')"
                    @update:model-value="provided.toggleSelection(row.id)"
                />
            </td>
            <td
                v-for="col in columns"
                :key="col.key"
                :class="col.cellClass"
                :style="{ textAlign: col.align ?? 'left' }"
            >
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
@use "Abstracts/timings" as ti;

@layer components {
    .dt-body {
        &__row--clickable {
            cursor: pointer;

            // Only clickable rows animate, so a read-only table pays for nothing.
            // The halo fades on the row, the fill on its cells — two rules because
            // they sit on two different elements.
            @media (prefers-reduced-motion: no-preference) {
                transition: box-shadow ti.$c-datatable ease-out;

                td {
                    transition: background-color ti.$c-datatable ease-out;
                }
            }
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

        /* The hovered clickable row lights up like every other live control in the
           app: the same two-layer neon halo as an open popover / a focused input /
           a checked checkbox, over a slightly stronger fill. The whole row does it,
           because the whole row is the click target (`:hover td`, never `td:hover`).

           `position: relative` on the row is what makes the halo visible at all: a
           box-shadow paints outside its own border box, so the next row's opaque
           cells would cover the bottom half of it. Positioning the hovered row moves
           it into the positioned paint phase, above its unpositioned siblings —
           without a z-index on purpose, so it still passes UNDER the sticky <thead>
           (z.$c-main = 1). The glow spreads are em-based effect constants living in
           the component, per the note in sizes/components/_button.scss.

           The fill rule is nested inside `.dt-body` rather than under the
           `&__row--clickable` block above so it compiles to a DESCENDANT selector and
           carries one class more than the zebra rules. Under `&__row--clickable` it
           would be `.dt-body__row--clickable:hover td[data-v]` — an exact specificity
           tie with `.dt-body tr:nth-child(odd) td[data-v]` (3 classes, 2 elements)
           once Vue's scope attribute joins in, and a tie is settled by source order,
           which the stripes won. Verified in the browser: the background didn't budge. */
        .dt-body__row--clickable:hover {
            position: relative;

            box-shadow:
                0 0 0.6em 0.1em map.get(c.$c-datatable, "row-glow"),
                0 0 1.5em 0.25em map.get(c.$c-datatable, "row-glow");

            td {
                background-color: map.get(c.$c-datatable, "row-hover");
            }
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
