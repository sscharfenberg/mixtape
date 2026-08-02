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
import Icon from "Components/UI/Icon.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetModeToggle from "Components/UI/Widget/WidgetModeToggle.vue";
import { useWidgetMode } from "Composables/useWidgetMode";
import type { AlbumEntry, WidgetMode, WidgetModes } from "Types/music";
import WidgetList, { type WidgetListItem } from "./WidgetList.vue";

const props = defineProps<WidgetModes<AlbumEntry>>();

const { t } = useI18n();

/** Modes this widget offers (shared with the toggle and the persisted mode). */
const modes: WidgetMode[] = ["latest", "random"];

/** Active mode — restored from localStorage, defaulting to latest. */
const mode = useWidgetMode("albums", "latest", modes);

/**
 * Active-mode albums as list entries. Falls back to `latest` if a mode's set is absent.
 *
 * A pip is dropped rather than shown empty when the tag is missing: an untagged rip has no
 * year, and a chip reading "—" says less than no chip at all.
 */
const items = computed<WidgetListItem[]>(() =>
    (props[mode.value] ?? props.latest).map(album => ({
        id: album.id,
        name: album.name,
        href: album.href,
        pips: [
            album.artist ? { icon: "artist", value: album.artist, label: t("music.columns.artist") } : null,
            album.year !== null ? { icon: "calendar", value: String(album.year), label: t("music.columns.year") } : null
        ].filter(pip => pip !== null)
    }))
);
</script>

<template>
    <widget :refresh="'albums'" skeleton="entries">
        <template #title>
            <icon name="album" />
            {{ t("music.widgets.albums") }}
            <widget-mode-toggle v-model="mode" name="albums-mode" :modes="modes" />
        </template>
        <widget-list :items="items" />
        <template #footer>
            <Link href="/music/albums" class="btn btn-primary" prefetch>{{ t("music.seeAll") }}</Link>
        </template>
    </widget>
</template>
