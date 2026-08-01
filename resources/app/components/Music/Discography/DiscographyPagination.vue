<script setup lang="ts">
/******************************************************************************
 * DiscographyPagination
 * The pager under a Discography. It owns two things: WHETHER a pager is drawn at
 * all, and what changing the page size does to the reader's position. The list
 * beside it only reads `page` / `pageSize` back out and slices.
 *
 * The control itself is the DataTable's own pager, reused rather than rebuilt.
 * That component is pure presentation — it takes page / pageSize / total and
 * emits, knowing nothing about a server or a URL — so the albums tab gets the same
 * control, in the same place, as the songs tab beside it. This wrapper is what
 * keeps that reuse in one file: Discography never reaches into components/DataTable
 * itself, and if the two pagers should ever diverge, this is where they part.
 *
 * Everything here is LOCAL and client-side, which is the whole point. A tabbed page
 * sends every panel's data on every request precisely so switching tabs costs
 * nothing, so paging a set already sitting in memory has nothing to fetch — and a
 * server-paged list would collide with the real DataTable beside it anyway, since
 * DataTableService reads `page` / `pageSize` UNPREFIXED. See Discography's banner.
 *****************************************************************************/
import { computed } from "vue";
import DataTablePagination from "Components/DataTable/DataTablePagination.vue";

const props = defineProps<{
    /** How many albums there are in total — not how many are on screen. */
    total: number;
}>();

/** The current page, 1-based. Two-way, so the list can slice on it. */
const page = defineModel<number>("page", { required: true });

/** How many albums a page holds. Two-way, since the pager can change it. */
const pageSize = defineModel<number>("pageSize", { required: true });

/**
 * Whether a pager is worth drawing at all — a discography of three albums does not need
 * "1–3 / 3" and a page-size Select under it. Most artists are in exactly that case (the
 * average is under two albums), so this keeps the control out of the common view entirely
 * rather than showing a one-page pager on nearly every artist in the library.
 */
const isPaged = computed(() => props.total > pageSize.value);

/**
 * Re-slice for a new page size, keeping the reader near where they were rather than
 * dumping them back on page 1: whichever album was first on screen stays on screen.
 *
 * Worth the arithmetic because the sizes on offer are far apart (25 → 100): someone deep
 * in a long genre who widens the page would otherwise lose their place entirely.
 */
const onPageSizeChange = (size: number): void => {
    const firstVisible = (page.value - 1) * pageSize.value;
    pageSize.value = size;
    page.value = Math.floor(firstVisible / size) + 1;
};
</script>

<template>
    <data-table-pagination
        v-if="isPaged"
        :page="page"
        :page-size="pageSize"
        :total="total"
        @navigate="page = $event"
        @page-size-change="onPageSizeChange"
    />
</template>
