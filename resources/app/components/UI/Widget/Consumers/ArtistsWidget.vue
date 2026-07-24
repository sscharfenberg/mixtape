<script setup lang="ts">
/******************************************************************************
 * ArtistsWidget
 * The Music page's "Artists" card — four artists, toggled via the header
 * WidgetModeToggle between "popular" (default; most total file duration),
 * latest (newest track) and a random pick. All sets arrive as Inertia props
 * (see MusicController). Defaults to popular because "latest artist" is a weak
 * signal here — artists have no date of their own.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetModeToggle from "Components/UI/Widget/WidgetModeToggle.vue";
import type { TaxonomyEntry, WidgetMode, WidgetModes } from "Types/music";
import WidgetList from "./WidgetList.vue";

const props = defineProps<WidgetModes<TaxonomyEntry>>();

const { t } = useI18n();
const mode = ref<WidgetMode>("popular");

/** Active-mode artists — a plain name list (no secondary line). Falls back to `latest` if a mode's set is absent. */
const items = computed(() => props[mode.value] ?? props.latest);
</script>

<template>
    <widget>
        <template #title>
            {{ t("music.widgets.artists") }}
            <widget-mode-toggle v-model="mode" name="artists-mode" :modes="['latest', 'popular', 'random']" />
        </template>
        <widget-list :items="items" />
        <template #footer>
            <Link href="/music/artists" class="btn btn-default">{{ t("music.seeAll") }}</Link>
        </template>
    </widget>
</template>
