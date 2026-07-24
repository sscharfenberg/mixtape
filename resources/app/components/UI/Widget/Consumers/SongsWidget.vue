<script setup lang="ts">
/******************************************************************************
 * SongsWidget
 * The Music page's "Songs" card — four songs, toggled between latest-added
 * (default), most-played ("popular", by plays) and a random pick via the header
 * WidgetModeToggle. All sets arrive as Inertia props (see MusicController), so
 * the toggle is instant. "popular" only lists songs with >1 play; when there's
 * no such song yet the set is empty and the list shows a "not enough data" note.
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

/** The active mode's raw set, falling back to `latest` if a mode's set is absent. */
const active = computed(() => props[mode.value] ?? props.latest);

/** Active-mode songs mapped to the shared list shape (meta = performing artist). */
const items = computed(() =>
    active.value.map((song) => ({
        id: song.id,
        name: song.name,
        meta: song.artist
    }))
);

/**
 * Empty-state line for the list. "popular" is filtered server-side to songs with
 * >1 play, so an empty popular set means "not enough listening data yet" rather
 * than "no songs" — surface that distinctly; other modes use the generic empty.
 */
const emptyText = computed(() =>
    mode.value === "popular" && active.value.length === 0 ? t("music.notEnoughData") : undefined
);
</script>

<template>
    <widget :refresh="'songs'">
        <template #title>
            {{ t("music.widgets.songs") }}
            <widget-mode-toggle v-model="mode" name="songs-mode" :modes="['latest', 'popular', 'random']" popular-by="plays" />
        </template>
        <widget-list :items="items" :empty-text="emptyText" />
        <template #footer>
            <Link href="/music/songs" class="btn btn-default">{{ t("music.seeAll") }}</Link>
        </template>
    </widget>
</template>
