<script setup lang="ts">
/******************************************************************************
 * DataTablePagination
 * The pagination bar for DataTable: first/prev/next/last buttons, a windowed set
 * of page numbers with ellipsis truncation, a jump-to-page field, the "from–to /
 * total" info, and a page-size Select (25/50/100). All navigation is emitted up
 * to DataTable, which owns the URL. The page-numbers and jump field only appear
 * when there's more than one page; the info + page-size Select always show.
 * Styling lives in the scoped block (c/s/ti.$c-datatable).
 *****************************************************************************/
import { computed, ref } from "vue";
import Select from "Components/Form/Select/Select.vue";
import Icon from "Components/UI/Icon.vue";
const props = defineProps<{
    /** Current page number (1-based). */
    page: number;
    /** Number of rows per page. */
    pageSize: number;
    /** Total number of rows across all pages. */
    total: number;
}>();
const emit = defineEmits<{
    /** Emitted when the user navigates to a different page. */
    navigate: [page: number];
    /** Emitted when the user changes the page size. */
    pageSizeChange: [size: number];
}>();
/** Total number of pages for the current total + page size. */
const totalPages = computed(() => Math.ceil(props.total / props.pageSize));
/** First row number displayed on the current page (1-based). */
const from = computed(() => (props.page - 1) * props.pageSize + 1);
/** Last row number displayed on the current page. */
const to = computed(() => Math.min(props.page * props.pageSize, props.total));
/**
 * Visible page numbers with ellipsis truncation — a sliding window of NEIGHBORS
 * pages either side of the current page. First/last already have dedicated icon
 * buttons, so they aren't repeated here; an "…" marks more pages in a direction.
 * Example for page 5 of 20: ["…", 3, 4, 5, 6, 7, "…"].
 */
const NEIGHBORS = 2;
const visiblePages = computed(() => {
    const pages: (number | "...")[] = [];
    const total = totalPages.value;
    const current = props.page;
    if (total <= 1) return pages;
    const start = Math.max(1, current - NEIGHBORS);
    const end = Math.min(total, current + NEIGHBORS);
    if (start > 1) pages.push("...");
    for (let i = start; i <= end; i++) pages.push(i);
    if (end < total) pages.push("...");
    return pages;
});
/** User-entered page number for the "jump to page" input. */
const jumpToPage = ref(props.page);
/** Clamp the entered page to the valid range before navigating. */
function onJumpToPage() {
    const clamped = Math.max(1, Math.min(totalPages.value, jumpToPage.value));
    jumpToPage.value = clamped;
    emit("navigate", clamped);
}
/** Page-size choices for the Select (values are strings; the emit re-casts to number). */
const pageSizeOptions = [25, 50, 100].map(s => ({ value: String(s), label: String(s) }));
</script>

<template>
    <nav class="dt-pagination" :aria-label="$t('components.datatable.pagination')">
        <div v-if="totalPages > 1" class="dt-pagination__col">
            <button
                :disabled="page <= 1"
                @click="emit('navigate', 1)"
                :aria-label="$t('components.datatable.first')"
                class="dt-pagination__page"
            >
                <icon name="first-page" :size="1" />
            </button>
            <button
                :disabled="page <= 1"
                @click="emit('navigate', page - 1)"
                :aria-label="$t('components.datatable.previous')"
                class="dt-pagination__page"
            >
                <icon name="chevron" :size="1" :additional-classes="['left']" />
            </button>
            <template v-for="p in visiblePages" :key="p">
                <span v-if="p === '...'" class="dt-pagination__ellipsis">…</span>
                <button
                    v-else
                    :class="{ 'dt-pagination__current': p === page }"
                    :aria-current="p === page ? 'page' : undefined"
                    @click="emit('navigate', p)"
                    class="dt-pagination__page"
                >
                    {{ p }}
                </button>
            </template>
            <button
                :disabled="page >= totalPages"
                @click="emit('navigate', page + 1)"
                :aria-label="$t('components.datatable.next')"
                class="dt-pagination__page"
            >
                <icon name="chevron" :size="1" :additional-classes="['right']" />
            </button>
            <button
                :disabled="page >= totalPages"
                @click="emit('navigate', totalPages)"
                :aria-label="$t('components.datatable.last')"
                class="dt-pagination__page"
            >
                <icon name="last-page" :size="1" />
            </button>
        </div>
        <div v-if="totalPages > 1" class="dt-pagination__col">
            <label for="jumpToPage">{{ $t("components.datatable.jump_to_page") }}</label>
            <input
                type="text"
                inputmode="numeric"
                :min="1"
                :max="totalPages"
                id="jumpToPage"
                v-model.number="jumpToPage"
                @keydown.enter="onJumpToPage"
                class="dt-pagination__jump"
                :aria-label="$t('components.datatable.jump_to_page')"
            />
        </div>
        <div class="dt-pagination__col">
            <span class="dt-pagination__info">{{ from }}–{{ to }} / {{ total }}</span>
            <Select
                :options="pageSizeOptions"
                :selected="String(pageSize)"
                :sort="false"
                :ariaLabel="$t('components.datatable.page_size')"
                @change="emit('pageSizeChange', Number($event))"
                :clearable="false"
                max="6rem"
            />
        </div>
    </nav>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

@layer components {
    .dt-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;

        padding: map.get(s.$c-datatable, "pagination", "padding");
        border: map.get(s.$c-datatable, "pagination", "border") solid map.get(c.$c-datatable, "pagination", "border");
        margin: map.get(s.$c-datatable, "pagination", "margin");
        gap: map.get(s.$c-datatable, "pagination", "gap");

        background-color: map.get(c.$c-datatable, "pagination", "background");
        color: map.get(c.$c-datatable, "pagination", "surface");
        border-radius: map.get(s.$c-datatable, "pagination", "radius");

        &__col {
            display: flex;
            align-items: center;
            flex-wrap: wrap;

            gap: 0.5rem;
        }

        &__jump {
            width: map.get(s.$c-datatable, "pagination", "jump", "width");
            padding: map.get(s.$c-datatable, "pagination", "jump", "padding");
            border: map.get(s.$c-datatable, "pagination", "page", "border") solid
                map.get(c.$c-datatable, "pagination", "page", "border");

            background-color: map.get(c.$c-datatable, "pagination", "page", "background");
            color: map.get(c.$c-datatable, "pagination", "page", "surface");
            border-radius: map.get(s.$c-datatable, "pagination", "page", "radius");

            text-align: center;
        }

        &__page {
            display: flex;
            align-items: center;
            justify-content: center;

            min-width: map.get(s.$c-datatable, "pagination", "page", "min-width");
            padding: map.get(s.$c-datatable, "pagination", "page", "padding");
            border: map.get(s.$c-datatable, "pagination", "page", "border") solid
                map.get(c.$c-datatable, "pagination", "page", "border");

            background-color: map.get(c.$c-datatable, "pagination", "page", "background");
            color: map.get(c.$c-datatable, "pagination", "page", "surface");
            border-radius: map.get(s.$c-datatable, "pagination", "page", "radius");

            @media (prefers-reduced-motion: no-preference) {
                transition:
                    background-color ti.$c-datatable linear,
                    color ti.$c-datatable linear;
            }

            // the chevron icon points down by default; rotate for prev / next.
            :deep(.icon.left) {
                transform: rotate(90deg);
            }

            :deep(.icon.right) {
                transform: rotate(-90deg);
            }

            &:not([disabled], .dt-pagination__current):hover {
                background-color: map.get(c.$c-datatable, "pagination", "page-hover", "background");
                color: map.get(c.$c-datatable, "pagination", "page-hover", "surface");

                cursor: pointer;
            }

            &[disabled] {
                opacity: 0.5;

                cursor: not-allowed;
            }
        }

        &__current {
            background-color: map.get(c.$c-datatable, "pagination", "page-current", "background");
            color: map.get(c.$c-datatable, "pagination", "page-current", "surface");
            border-color: map.get(c.$c-datatable, "pagination", "page-current", "surface");
        }
    }
}
</style>
