<script setup lang="ts">
/******************************************************************************
 * DataTableToolbar
 * The bar above the DataTable: a debounced search field (leading search icon), a chip saying so
 * when the search has been NARROWED to the row's own name, a selection-count badge (when rows are
 * selected), and a `#actions` slot for bulk-action buttons. Typing is debounced 600ms before the `search` event fires
 * (so we don't navigate on every keystroke); the input re-syncs when the server
 * echoes a new `search` value back after navigation. The search field is styled
 * locally in the scoped block (c/s.$c-datatable) so the component is
 * self-contained.
 *
 * THE NARROWING CHIP IS NOT DECORATION. A listing arrived at from the cross-kind search dropdown
 * matches titles ONLY (`?searchIn=name`), because that is what the dropdown counted — and a table
 * quietly showing fewer rows than its own search would find is the same class of confusion the mode
 * was introduced to fix, just pointing the other way. So the mode announces itself and the chip is
 * the way out: pressing it drops the parameter and re-runs the listing's own wider search.
 *
 * Typing keeps the mode, deliberately. The reader arrived in "titles only" and the URL says so, so
 * refining the query stays in the reading they chose until they press the chip — which is also what
 * `buildUrl` in DataTable does for free by preserving the query it does not own.
 *****************************************************************************/
import { ref, watch } from "vue";
import Icon from "Components/UI/Icon.vue";
const props = defineProps<{
    /** Current search query from the server response, synced to the input. */
    search: string | null;
    /**
     * Which search the server ran — `"name"` when it was narrowed to the row's own name. Null for
     * the listing's own default, which draws no chip because there is nothing to get out of.
     */
    searchIn?: string | null;
    /** Number of currently selected rows, shown as a badge. */
    selectedCount: number;
}>();
const emit = defineEmits<{
    /** Emitted with the debounced search query after the user stops typing. */
    search: [query: string];
    /** The reader pressed the narrowing chip: drop the mode and search wide again. */
    widen: [];
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
        <!-- Only while the narrowing is real — the server echoes the mode back only when it
             actually applied, so this cannot offer a way out of something that did not happen. -->
        <button
            v-if="searchIn === 'name'"
            v-tooltip="$t('components.datatable.search_in_name_hint')"
            type="button"
            class="dt-toolbar__mode"
            @click="emit('widen')"
        >
            <icon name="search" :size="1" />
            {{ $t("components.datatable.search_in_name") }}
            <icon name="close" :size="1" />
        </button>
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
@use "Abstracts/timings" as ti;

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

        /* The narrowing chip. It borrows the pagination page button's palette rather than minting
           one, because it is the same kind of object — a small pressable pill in the table's own
           chrome — and it sits next to the field it is talking about. */
        &__mode {
            display: inline-flex;
            align-items: center;

            padding: 0.25ex 1ch;
            border: map.get(s.$c-datatable, "border") solid map.get(c.$c-datatable, "pagination", "page", "border");

            /* BESIDE THE FIELD, not at the far end of the row. The toolbar is
               `justify-content: space-between`, which pushed this to the trailing edge — a chip
               explaining the search box, a thousand pixels away from it. `auto` on the trailing
               side claims the slack instead, so the chip sits against the field and whatever
               follows keeps its own place. */
            margin-inline-end: auto;
            gap: 0.5ch;

            background-color: map.get(c.$c-datatable, "pagination", "page", "background");
            color: map.get(c.$c-datatable, "pagination", "page", "surface");

            border-radius: map.get(s.$c-datatable, "pagination", "page", "radius");

            cursor: pointer;

            @media (prefers-reduced-motion: no-preference) {
                transition:
                    background-color ti.$c-datatable linear,
                    color ti.$c-datatable linear;
            }

            &:hover,
            &:focus-visible {
                background-color: map.get(c.$c-datatable, "pagination", "page-hover", "background");
                color: map.get(c.$c-datatable, "pagination", "page-hover", "surface");
            }
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
