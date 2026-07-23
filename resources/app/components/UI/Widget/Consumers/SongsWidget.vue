<script setup lang="ts">
/******************************************************************************
 * SongsWidget
 * The Music page's "Songs" card — four songs, toggled between latest-added
 * (default) and a random pick via the header WidgetModeToggle. Both sets arrive as
 * Inertia props (see MusicController), so the toggle is instant.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetModeToggle from "Components/UI/Widget/WidgetModeToggle.vue";
import type { SongEntry, WidgetMode, WidgetModes } from "Types/music";
import WidgetList from "./WidgetList.vue";

const props = defineProps<WidgetModes<SongEntry>>();

const { t } = useI18n();
const mode = ref<WidgetMode>("latest");

/** Active-mode songs mapped to the shared list shape (meta = performing artist). */
const items = computed(() =>
    (mode.value === "random" ? props.random : props.latest).map((song) => ({
        id: song.id,
        name: song.name,
        meta: song.artist
    }))
);
</script>

<template>
    <widget>
        <template #title>
            {{ t("music.widgets.songs") }}
            <widget-mode-toggle v-model="mode" name="songs-mode" />
        </template>
        <widget-list :items="items" />
        <template #footer>
            <Link href="/music/songs" class="btn btn-default">{{ t("music.seeAll") }}</Link>
        </template>
    </widget>
</template>
