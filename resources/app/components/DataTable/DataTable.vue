<script setup lang="ts" generic="T extends { id: string; href?: string }">
/******************************************************************************
 * DataTable
 * A server-driven, accessible table for paginated / sortable / searchable data
 * (ported from cantrip.me). Built for Inertia: the SERVER owns the state (rows,
 * page, sort, search arrive as the `response` prop), and this component just
 * renders it and emits changes via `router.get()` with the state in the URL
 * query — so a sorted/paged/filtered view is bookmarkable and survives reload.
 *
 * This is the orchestrator: selection state (shared with the sub-components via
 * provide/inject), slot forwarding (cell-/header- slots + row actions), the
 * loading overlay during navigation, aria-live announcements, and sticky-header
 * detection. The <table> (desktop) and the card grid (mobile) are BOTH in the
 * DOM; a container query at the `datatable.breakpoint` toggles which shows, so
 * the layout adapts to the table's own width, not the viewport.
 *
 * All styling lives in the scoped block below (contextual c/s.$c-datatable
 * tokens); the sub-components each carry their own scoped styles.
 *****************************************************************************/
import { router } from "@inertiajs/vue3";
import { type Ref, computed, onBeforeUnmount, onMounted, provide, ref, useSlots, watch } from "vue";
import { useI18n } from "vue-i18n";
import DataTableActions from "Components/DataTable/DataTableActions.vue";
import DataTableBody from "Components/DataTable/DataTableBody.vue";
import DataTableCards from "Components/DataTable/DataTableCards.vue";
import DataTableHead from "Components/DataTable/DataTableHead.vue";
import DataTablePagination from "Components/DataTable/DataTablePagination.vue";
import DataTableToolbar from "Components/DataTable/DataTableToolbar.vue";
import LoadingSpinner from "Components/UI/LoadingSpinner.vue";
import type { ColumnDef, TableResponse, SortEntry } from "Types/dataTable";
import { DATA_TABLE_KEY } from "Types/dataTable";
import { scrollIntoViewTop } from "Utils/scroll";
const props = withDefaults(
    defineProps<{
        /** Column definitions controlling rendering, sorting, and visibility. */
        columns: ColumnDef<T>[];
        /** Server-side table response containing rows, pagination, sort, and search state. */
        response: TableResponse<T>;
        /** Whether rows can be selected via checkboxes. */
        selectable?: boolean;
        /**
         * Whether to render the per-row actions column (header + body + card-mode
         * three-dot button). Pass `false` for read-only viewer-mode tables so
         * non-owners don't see an empty actions column / button.
         */
        hasActions?: boolean;
        /** Base URL for Inertia navigation; defaults to current pathname. */
        baseUrl?: string;
        /**
         * Extra CSS class(es) for one ROW, decided per row — the row-level counterpart of
         * `ColumnDef.cellClass`, which can only reach a single cell.
         *
         * A callback rather than a field on the row, because "is this row special" is a
         * question the PAGE answers and the server has no opinion about: the audiobook page
         * marks the chapter its reader left off at, which depends on a bookmark the table
         * knows nothing of. Applies to the card view as well as the table, so a phone gets
         * the same mark.
         */
        rowClass?: (row: T) => string | undefined;
        /**
         * Make rows pressable even though they carry no `href`.
         *
         * The table's own clickable row NAVIGATES: it visits `row.href`, which is what a
         * listing row means everywhere in this app. A chapter has no page to visit — what a
         * reader wants from that row is to hear it — so this says "a press here is an action,
         * and the page will say which" and the press arrives as `@row-click`.
         *
         * The visible affordance is the same either way: the hover wash and the pointer
         * cursor, so a pressable row does not look like a different kind of thing.
         */
        rowClickable?: boolean;
    }>(),
    {
        selectable: false,
        hasActions: true,
        baseUrl: ""
    }
);
const emit = defineEmits<{
    /**
     * A row with no `href` was pressed (see `rowClickable`). The audiobook page plays that
     * chapter with it; a table whose rows navigate never fires this.
     */
    rowClick: [row: T];
}>();

/**
 * Explicit slot contract — vue-tsc loses prop inference for slots that
 * sit inside a nested `<template v-if … #default>` (the `actions` slot
 * lives inside the DataTableActions wrapper below). Declaring it here
 * keeps the consumer's destructure (`{ row, close }`) typed correctly.
 *
 * Cell and header slots are column-driven, so the catch-all index
 * signature allows arbitrary slot names without enumerating columns.
 */
defineSlots<{
    actions(props: { row: T; close: () => void }): unknown;
    "toolbar-actions"(props: { selectedIds: string[] }): unknown;
    empty(): unknown;
    // Catch-all for the column-driven cell-*/header-* slots (their prop shapes vary
    // per column). `any` is required, not `unknown`: the index signature must be
    // assignable-from the typed named slots above, which `unknown` params break.

    [name: string]: (props?: any) => unknown;
}>();
const { t } = useI18n();
const slots = useSlots();
/** Cell slot names to forward to the body + cards (columns opt in per-key). */
const cellSlotNames = computed(() => Object.keys(slots).filter(name => name.startsWith("cell-")));
/** Header slot names to forward to the head (columns opt in per-key). */
const headerSlotNames = computed(() => Object.keys(slots).filter(name => name.startsWith("header-")));
/** Server sends sort as object|null; normalise to the internal always-array form. */
const sort = computed<SortEntry[]>(() => {
    if (!props.response.sort) return [];
    return [props.response.sort];
});
/** The smallest page size the pager's Select offers (DataTableService::ALLOWED_PAGE_SIZES). */
const SMALLEST_PAGE_SIZE = 25;

/**
 * Whether the pagination bar is worth drawing at all.
 *
 * It is not, for a table whose whole contents fit on one page at EVERY page size the Select
 * offers: there is no page to go to, and no page size that would change anything, so the
 * control reduces to "1–7 / 7" beside a dropdown that does nothing. A genre with three
 * albums or an EP with four tracks is the common case for that.
 *
 * Decided on `totalUnfiltered`, not `total`. A search narrowing five hundred rows to one
 * still wants its pager — both to say so and to offer a page size — and deciding on the
 * filtered count would make the whole bar appear and disappear as the reader types, moving
 * everything below it on every keystroke.
 */
const showPagination = computed(
    () => Boolean(props.response.pageSize) && props.response.totalUnfiltered > SMALLEST_PAGE_SIZE
);

/** IDs of currently selected rows, shared with child components via provide/inject. */
const selectedIds = ref<string[]>([]);
/** Toggle a single row's selection state. */
function toggleSelection(id: string) {
    const idx = selectedIds.value.indexOf(id);
    if (idx === -1) {
        selectedIds.value.push(id);
    } else {
        selectedIds.value.splice(idx, 1);
    }
}
/** Toggle selection for all rows on the current page (select all / deselect all). */
function togglePageSelection(ids: string[]) {
    const allSelected = ids.every(id => selectedIds.value.includes(id));
    if (allSelected) {
        selectedIds.value = selectedIds.value.filter(id => !ids.includes(id));
    } else {
        const missing = ids.filter(id => !selectedIds.value.includes(id));
        selectedIds.value.push(...missing);
    }
}
/** Clear selection on sort/filter/search change, preserve it across page changes. */
watch(
    () => [props.response.sort, props.response.search, props.response.filters],
    () => {
        selectedIds.value = [];
    },
    { deep: true }
);
provide(DATA_TABLE_KEY, {
    selectedIds,
    toggleSelection,
    togglePageSelection
});
/** All row IDs on the current page, used by the header select-all checkbox. */
const rowIds = computed(() => props.response.rows.map(row => row.id));
/** True while an Inertia navigation is in flight — shows the loading overlay. */
const isLoading = ref(false);
/*
 * Prefetches are NOT navigations, and Inertia fires the same `start` / `finish` events for
 * them. Without this guard, merely running the pointer across the table — which is what
 * arms DataTableBody's hover prefetch — flashed the overlay over rows that were not going
 * anywhere. `finish` is guarded too, and for the opposite reason: a prefetch completing
 * while a real visit is in flight would otherwise clear the overlay early.
 */
const isNavigation = (event: { detail: { visit: { prefetch: boolean } } }): boolean =>
    !event.detail.visit.prefetch;
/*
 * BOTH UNSUBSCRIBES ARE KEPT, because `router.on` registers on the global router, which
 * outlives this component. A DataTable unmounts on every navigation away from a listing, so
 * dropping the return value leaves a pair of handlers per visit writing to a dead `isLoading`.
 */
const offStart = router.on("start", event => {
    if (isNavigation(event)) isLoading.value = true;
});
const offFinish = router.on("finish", event => {
    if (isNavigation(event)) isLoading.value = false;
});
/** Screen reader announcement text, updated on sort and page changes. */
const announcement = ref("");
watch(sort, newSort => {
    if (newSort.length === 0) return;
    const entry = newSort[0];
    const col = props.columns.find(c => c.key === entry.key);
    const label = col?.label ?? entry.key;
    announcement.value =
        entry.direction === "asc"
            ? t("components.datatable.sorted_asc", { column: label })
            : t("components.datatable.sorted_desc", { column: label });
});
watch(
    () => props.response.page,
    page => {
        if (!props.response.pageSize) return;
        const totalPages = Math.ceil(props.response.total / props.response.pageSize);
        announcement.value = t("components.datatable.page_status", {
            page,
            total: totalPages,
            size: props.response.rows.length
        });
    }
);
/** The row whose action popover is currently open, or null. */
const activeRow = ref<T | null>(null) as Ref<T | null>;
/** The three-dot button that opened the popover — used to return focus on close. */
const actionButtonRef = ref<HTMLElement | null>(null);
/** Open the action popover for a row, anchored to the trigger button. */
function onAction(row: T, el: HTMLElement) {
    activeRow.value = row;
    actionButtonRef.value = el;
}
/** Reset popover state and return focus to the three-dot button that opened it. */
function onCloseActions() {
    const triggerEl = actionButtonRef.value;
    activeRow.value = null;
    actionButtonRef.value = null;
    triggerEl?.focus();
}
/**
 * Handle to the row-actions popover so slot consumers can request a programmatic
 * dismiss: native `auto` popovers only close on outside-click / Escape / another
 * popover opening, so an in-place action (Inertia delete with preserveScroll)
 * leaves it open unless the consumer calls this via the slot's `close`.
 */
const actionsRef = ref<{ hide: () => void } | null>(null);
/** Programmatically dismiss the row-actions popover (exposed to consumers as `close`). */
function closeActionsPopover() {
    actionsRef.value?.hide();
}
/**
 * Merge new params into the current URL, preserving existing query state
 * (e.g. pageSize survives a page navigation). Params set to null are removed.
 *
 * The FRAGMENT is carried over too, though nothing about the table reads it. A hash is
 * client-only state the server never sees and the table has no business owning — an
 * in-page anchor the reader followed, or whatever a future consumer keeps there — so a
 * sort or a page change has no business silently discarding it.
 */
function buildUrl(params: Record<string, string | number | null>) {
    const base = props.baseUrl || window.location.pathname;
    const url = new URL(base, window.location.origin);
    const currentParams = new URLSearchParams(window.location.search);
    for (const [key, value] of currentParams) {
        url.searchParams.set(key, value);
    }
    for (const [key, value] of Object.entries(params)) {
        if (value === null || value === "") {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, String(value));
        }
    }
    return url.pathname + url.search + window.location.hash;
}
const root = ref<HTMLElement | null>(null);
/**
 * Visit the table's own URL, then bring the table's top back into view.
 *
 * `preserveScroll: true` and an explicit scroll, rather than letting Inertia reset it:
 * its own reset goes to the top of the DOCUMENT, which on a tabbed detail page scrolls
 * up past the hero and the tab strip and hides the table that just changed. Scrolling the
 * table instead lands the reader on its first row — the same behaviour, from the same
 * helper, as the Discography pager, so the two controls agree wherever they sit side by
 * side (`scroll-margin-top` below clears the sticky header).
 *
 * `onSuccess` rather than a global `router.on("finish")`: this table should follow ITS
 * OWN visits, not every navigation that happens to pass through the page.
 */
function visit(params: Record<string, string | number | null>) {
    router.get(
        buildUrl(params),
        {},
        { preserveState: true, preserveScroll: true, onSuccess: () => scrollIntoViewTop(root.value) }
    );
}
/** Toggle sort direction for a column and navigate to page 1. */
function onSort(key: string) {
    const current = sort.value.find(s => s.key === key);
    let direction: "asc" | "desc" = "asc";
    if (current) {
        direction = current.direction === "asc" ? "desc" : "asc";
    }
    visit({ sort: key, dir: direction, page: 1 });
}
/**
 * Drop the narrow search mode, so the listing's own (wider) search runs — what the toolbar's
 * narrowing chip presses.
 *
 * Back to page 1 like every other change to what the table is showing: the row that was on page 4
 * of the narrowed result is somewhere else entirely in the wide one.
 */
function onWiden() {
    visit({ searchIn: null, page: 1 });
}

/** Navigate with an updated search query, resetting to page 1. */
function onSearch(query: string) {
    visit({ search: query || null, page: 1 });
}
/** Navigate to a specific page number. */
function onNavigate(page: number) {
    visit({ page });
}
/** Change page size and reset to page 1. */
function onPageSizeChange(size: number) {
    visit({ pageSize: size, page: 1 });
}
/** Sentinel at the top of the wrapper; its intersection tells us when the head is stuck. */
const stickysentinel = ref<HTMLElement | null>(null);
/** True when the table header is stuck (scrolled past the sentinel). */
const isStuck = ref(false);
let observer: IntersectionObserver | null = null;
onMounted(() => {
    if (!stickysentinel.value) return;
    // Resolve the sticky offset to a px length for the rootMargin. The head's
    // `top` is `var(--datatable-sticky-offset, 0)`, which may itself resolve to
    // another custom property (the app sets it to var(--app-header-height)); the
    // head's COMPUTED `top` gives the final px, whereas reading the custom
    // property returns the unresolved `var(...)` string (an invalid rootMargin).
    const head = stickysentinel.value.closest(".dt")?.querySelector(".dt-head");
    const offsetPx = head ? parseFloat(getComputedStyle(head).top) || 0 : 0;
    const margin = offsetPx ? `-${offsetPx}px 0px 0px 0px` : "0px";
    observer = new IntersectionObserver(
        ([entry]) => {
            isStuck.value = !entry.isIntersecting;
        },
        { rootMargin: margin }
    );
    observer.observe(stickysentinel.value);
});
onBeforeUnmount(() => {
    observer?.disconnect();
    offStart();
    offFinish();
});
</script>

<template>
    <div ref="root" class="dt" :class="{ 'dt--loading': isLoading }">
        <data-table-toolbar
            :search="response.search"
            :search-in="response.searchIn"
            :selected-count="selectedIds.length"
            @search="onSearch"
            @widen="onWiden"
        >
            <template v-if="$slots['toolbar-actions']" #actions>
                <slot name="toolbar-actions" :selected-ids="selectedIds" />
            </template>
        </data-table-toolbar>

        <div class="dt__wrapper">
            <div v-if="isLoading" class="dt__overlay">
                <loading-spinner :size="6" />
            </div>

            <!-- Sentinel for sticky header detection -->
            <div ref="stickysentinel" class="dt__sticky-sentinel" />

            <!-- Desktop: table layout — header + rows only when there ARE rows -->
            <table v-if="response.rows.length > 0" class="dt__table" :aria-busy="isLoading">
                <data-table-head
                    :columns="columns"
                    :sort="sort"
                    :tiebreakers="response.tiebreakers ?? []"
                    :selectable="selectable"
                    :has-actions="hasActions"
                    :row-ids="rowIds"
                    :stuck="isStuck"
                    @sort="onSort"
                >
                    <template v-for="name in headerSlotNames" :key="name" #[name]="slotProps">
                        <slot :name="name" v-bind="slotProps" />
                    </template>
                </data-table-head>
                <data-table-body
                    :columns="columns"
                    :rows="response.rows"
                    :selectable="selectable"
                    :has-actions="hasActions"
                    :row-class="rowClass"
                    :row-clickable="rowClickable"
                    @action="onAction"
                    @row-click="emit('rowClick', $event)"
                >
                    <template v-for="name in cellSlotNames" :key="name" #[name]="slotProps">
                        <slot :name="name" v-bind="slotProps" />
                    </template>
                </data-table-body>
            </table>

            <!-- Mobile: card layout -->
            <data-table-cards
                v-if="response.rows.length > 0"
                :columns="columns"
                :rows="response.rows"
                :selectable="selectable"
                :has-actions="hasActions"
                @action="onAction"
            >
                <template v-for="name in cellSlotNames" :key="name" #[name]="slotProps">
                    <slot :name="name" v-bind="slotProps" />
                </template>
            </data-table-cards>

            <!-- Empty state — inside the wrapper so its min-height reserves the space -->
            <div v-if="response.rows.length === 0 && !isLoading" class="dt__empty">
                <slot name="empty" />
            </div>
        </div>

        <!-- Pagination. Dropped entirely for a table small enough that none of it could do
             anything — see `showPagination`. -->
        <data-table-pagination
            v-if="showPagination && response.pageSize"
            :page="response.page"
            :page-size="response.pageSize"
            :total="response.total"
            @navigate="onNavigate"
            @page-size-change="onPageSizeChange"
        />

        <!-- Row action popover -->
        <data-table-actions
            v-if="hasActions"
            ref="actionsRef"
            :row="activeRow"
            :trigger-el="actionButtonRef"
            @close="onCloseActions"
        >
            <template v-if="activeRow" #default>
                <slot name="actions" :row="activeRow" :close="closeActionsPopover" />
            </template>
        </data-table-actions>

        <!-- Screen reader announcements -->
        <div class="sr-only" aria-live="polite">{{ announcement }}</div>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/mixins" as m;

@layer components {
    .dt {
        container-type: inline-size;

        /* Where a sort / search / page change scrolls back to (see `visit`). The app header
           is `position: sticky` and publishes its live height as `--app-header-height`, so
           clearing it by that much is what stops the toolbar landing underneath it. */
        scroll-margin-top: calc(var(--app-header-height, 0px) + #{map.get(s.$c-datatable, "radius")});

        &--loading {
            pointer-events: none;
        }

        &__wrapper {
            position: relative;

            // reserve the loading overlay's height in the flow so toggling the
            // overlay / empty state never shifts the page ("no jump"), and the
            // spinner always has room below the header on an empty result set.
            min-height: map.get(s.$c-datatable, "overlay-min-height");
        }

        &__sticky-sentinel {
            height: 0;
        }

        &__overlay {
            display: flex;
            position: absolute;
            inset: 0;
            z-index: 1;
            align-items: center;
            justify-content: center;

            border: map.get(s.$c-datatable, "border") dashed map.get(c.$c-datatable, "border");

            background: map.get(c.$c-datatable, "overlay");
            color: map.get(c.$c-datatable, "spinner"); // brand-tints the spinner (it uses currentcolor)
            border-radius: map.get(s.$c-datatable, "radius");
        }

        &__table {
            display: table;

            width: 100%;
            border: map.get(s.$c-datatable, "border") solid map.get(c.$c-datatable, "border");

            border-radius: map.get(s.$c-datatable, "radius");

            border-spacing: 0;
        }

        &__empty {
            display: flex;
            align-items: center;
            justify-content: center;

            min-height: map.get(s.$c-datatable, "overlay-min-height");
            padding: 2rem;

            text-align: center;
        }
    }

    // wide container → the table; narrow container → the cards (DataTableCards).
    @include m.cq(map.get(s.$c-datatable, "breakpoint")) {
        .dt__table {
            display: table;
        }
    }

    @include m.cq(map.get(s.$c-datatable, "breakpoint"), $mobile-first: false) {
        .dt__table {
            display: none;
        }
    }
}
</style>
