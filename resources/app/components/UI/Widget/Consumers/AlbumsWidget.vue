<script setup lang="ts">
/******************************************************************************
 * AlbumsWidget
 * The Music page's "Albums" card — four albums, toggled between latest-added
 * (default) and a random pick via the header WidgetModeToggle. Both sets arrive as
 * Inertia props (see MusicController), so the toggle is instant.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetModeToggle from "Components/UI/Widget/WidgetModeToggle.vue";
import { useWidgetMode } from "Composables/useWidgetMode";
import type { AlbumEntry, WidgetMode, WidgetModes } from "Types/music";
import WidgetList from "./WidgetList.vue";

const props = defineProps<WidgetModes<AlbumEntry>>();

const { t } = useI18n();

/** Modes this widget offers (shared with the toggle and the persisted mode). */
const modes: WidgetMode[] = ["latest", "random"];

/** Active mode — restored from localStorage, defaulting to latest. */
const mode = useWidgetMode("albums", "latest", modes);

/** Active-mode albums mapped to the shared list shape (meta = "artist · year"). Falls back to `latest` if a mode's set is absent. */
const items = computed(() =>
    (props[mode.value] ?? props.latest).map((album) => ({
        id: album.id,
        name: album.name,
        meta: [album.artist, album.year].filter(Boolean).join(" · ") || null
    }))
);
</script>

<template>
    <widget :refresh="'albums'">
        <template #title>
            {{ t("music.widgets.albums") }}
            <widget-mode-toggle v-model="mode" name="albums-mode" :modes="modes" />
        </template>
        <widget-list :items="items" />
        <template #footer>
            <Link href="/music/albums" class="btn btn-default">{{ t("music.seeAll") }}</Link>
        </template>
    </widget>
</template>
