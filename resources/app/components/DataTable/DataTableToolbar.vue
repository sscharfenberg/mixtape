<script setup lang="ts">
/******************************************************************************
 * DataTableToolbar
 * The bar above the DataTable: a debounced search field (leading search icon),
 * a selection-count badge (when rows are selected), and a `#actions` slot for
 * bulk-action buttons. Typing is debounced 600ms before the `search` event fires
 * (so we don't navigate on every keystroke); the input re-syncs when the server
 * echoes a new `search` value back after navigation. The search field is styled
 * locally in the scoped block (c/s.$c-datatable) so the component is
 * self-contained.
 *****************************************************************************/
import { ref, watch } from "vue";
import Icon from "Components/UI/Icon.vue";
const props = defineProps<{
    /** Current search query from the server response, synced to the input. */
    search: string | null;
    /** Number of currently selected rows, shown as a badge. */
    selectedCount: number;
}>();
const emit = defineEmits<{
    /** Emitted with the debounced search query after the user stops typing. */
    search: [query: string];
}>();
/** Local search input value, debounced before emitting to the parent. */
const query = ref(props.search ?? "");
let debounceTimer: ReturnType<typeof setTimeout> | null = null;
watch(query, value => {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        emit("search", value);
    }, 600);
});
/** Sync the external search prop back to the local input (e.g. on Inertia navigation). */
watch(
    () => props.search,
    value => {
        query.value = value ?? "";
    }
);
</script>

<template>
    <div class="dt-toolbar">
        <div class="dt-toolbar__search">
            <div class="dt-toolbar__addon"><icon name="search" :size="1" /></div>
            <input
                v-model="query"
                type="search"
                :placeholder="$t('components.datatable.search_placeholder')"
                :aria-label="$t('components.datatable.search')"
                class="dt-toolbar__input"
            />
        </div>
        <span v-if="selectedCount > 0" class="dt-toolbar__selection">
            {{ $t("components.datatable.items_selected", { count: selectedCount }) }}
        </span>
        <div v-if="$slots.actions" class="dt-toolbar__actions">
            <slot name="actions" />
        </div>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/mixins" as m;

@layer components {
    .dt-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;

        padding: 0 0 1rem;

        gap: 1rem;

        &__selection {
            margin-inline-start: auto;
        }

        // the search field: an addon icon box fused to a text input, styled from
        // the datatable palette so it reads as part of the table.
        &__search {
            display: flex;
            flex-grow: 1;
            flex-wrap: nowrap;

            min-width: 0;
            max-width: 50%;

            @include m.mq("portrait") {
                max-width: 24rem;
            }

            @include m.mq("landscape") {
                max-width: 32rem;
            }
        }

        &__addon {
            display: flex;
            align-items: center;

            padding: 0 1ch;
            border: map.get(s.$c-datatable, "border") solid map.get(c.$c-datatable, "pagination", "page", "border");
            border-right: 0;

            background-color: map.get(c.$c-datatable, "pagination", "page", "background");
            color: map.get(c.$c-datatable, "pagination", "page", "surface");

            border-radius: map.get(s.$c-datatable, "pagination", "page", "radius") 0 0
                map.get(s.$c-datatable, "pagination", "page", "radius");
        }

        &__input {
            min-width: 0;
            flex: 1 1 auto;
            padding: 0.5ex 1ch;
            border: map.get(s.$c-datatable, "border") solid map.get(c.$c-datatable, "pagination", "page", "border");
            border-left: 0;

            background-color: map.get(c.$c-datatable, "pagination", "page", "background");
            color: map.get(c.$c-datatable, "pagination", "page", "surface");
            outline: 0;

            border-radius: 0 map.get(s.$c-datatable, "pagination", "page", "radius")
                map.get(s.$c-datatable, "pagination", "page", "radius") 0;
        }
    }
}
</style>
