<script setup lang="ts">
/******************************************************************************
 * AlbumsWidget
 * The Music page's "Albums" card — four albums, toggled between latest-added
 * (default) and a random pick via the header WidgetModeToggle. Both sets arrive as
 * Inertia props (see MusicController), so the toggle is instant.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetModeToggle from "Components/UI/Widget/WidgetModeToggle.vue";
import type { AlbumEntry, WidgetMode, WidgetModes } from "Types/music";
import WidgetList from "./WidgetList.vue";

const props = defineProps<WidgetModes<AlbumEntry>>();

const { t } = useI18n();
const mode = ref<WidgetMode>("latest");

/** Active-mode albums mapped to the shared list shape (meta = "artist · year"). */
const items = computed(() =>
    (mode.value === "random" ? props.random : props.latest).map((album) => ({
        id: album.id,
        name: album.name,
        meta: [album.artist, album.year].filter(Boolean).join(" · ") || null
    }))
);
</script>

<template>
    <widget>
        <template #title>
            {{ t("music.widgets.albums") }}
            <widget-mode-toggle v-model="mode" name="albums-mode" />
        </template>
        <widget-list :items="items" />
        <template #footer>
            <Link href="/music/albums" class="btn btn-default">{{ t("music.seeAll") }}</Link>
        </template>
    </widget>
</template>
