<script setup lang="ts">
/******************************************************************************
 * GenresWidget
 * The Music page's "Genres" card — four genres, toggled between latest (most
 * recently added, by newest track) and a random pick via the header
 * ModeToggle. Both sets arrive as Inertia props (see MusicController).
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Widget from "Components/UI/Widget/Widget.vue";
import type { TaxonomyEntry, WidgetMode, WidgetModes } from "Types/music";
import ModeToggle from "./ModeToggle.vue";
import WidgetList from "./WidgetList.vue";

const props = defineProps<WidgetModes<TaxonomyEntry>>();

const { t } = useI18n();
const mode = ref<WidgetMode>("latest");

/** Active-mode genres — a plain name list (no secondary line). */
const items = computed(() => (mode.value === "random" ? props.random : props.latest));
</script>

<template>
    <widget>
        <template #title>
            {{ t("music.widgets.genres") }}
            <mode-toggle v-model="mode" name="genres-mode" />
        </template>
        <widget-list :items="items" />
        <template #footer>
            <Link href="/music/genres" class="btn btn-default">{{ t("music.seeAll") }}</Link>
        </template>
    </widget>
</template>
