<script setup lang="ts">
/******************************************************************************
 * WidgetList
 * The shared list body for the music consumer widgets: renders `items` as a
 * name (+ optional secondary line) list, or an "empty" line when there are
 * none. Split out so all four widgets share one list look and get restyled in
 * one place.
 *****************************************************************************/
import { useI18n } from "vue-i18n";

const { t } = useI18n();

defineProps<{
    /** Rows to show — a display name plus an optional secondary line each. */
    items: { id: string; name: string; meta?: string | null }[];
}>();
</script>

<template>
    <ul v-if="items.length" class="widget-list">
        <li v-for="item in items" :key="item.id" class="widget-list__item">
            <span class="widget-list__name">{{ item.name }}</span>
            <span v-if="item.meta" class="widget-list__meta">{{ item.meta }}</span>
        </li>
    </ul>
    <p v-else class="widget-list__empty">{{ t("music.empty") }}</p>
</template>

<style scoped lang="scss">
// Minimal placeholder styling (muted via opacity, relative sizing — no minted
// colours) until the widgets get their real design pass.
.widget-list {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: 0.5rem;

    list-style: none;

    &__item {
        display: flex;
        flex-direction: column;

        overflow: hidden;
    }

    &__name {
        font-weight: 600;
    }

    &__meta {
        opacity: 0.75;

        overflow: hidden;

        font-size: 0.85em;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    &__empty {
        opacity: 0.75;

        margin: 0;

        font-style: italic;
    }
}
</style>
