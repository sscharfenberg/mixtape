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
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetModeToggle from "Components/UI/Widget/WidgetModeToggle.vue";
import { useWidgetMode } from "Composables/useWidgetMode";
import type { TaxonomyEntry, WidgetMode, WidgetModes } from "Types/music";
import WidgetList from "./WidgetList.vue";

const props = defineProps<WidgetModes<TaxonomyEntry>>();

const { t } = useI18n();

/** Modes this widget offers (shared with the toggle and the persisted mode). */
const modes: WidgetMode[] = ["latest", "popular", "random"];

/** Active mode — restored from localStorage, defaulting to popular. */
const mode = useWidgetMode("artists", "popular", modes);

/** Active-mode artists — a plain name list (no secondary line). Falls back to `latest` if a mode's set is absent. */
const items = computed(() => props[mode.value] ?? props.latest);
</script>

<template>
    <widget :refresh="'artists'">
        <template #title>
            {{ t("music.widgets.artists") }}
            <widget-mode-toggle v-model="mode" name="artists-mode" :modes="modes" popular-by="duration" />
        </template>
        <widget-list :items="items" />
        <template #footer>
            <Link href="/music/artists" class="btn btn-primary">{{ t("music.seeAll") }}</Link>
        </template>
    </widget>
</template>
