<script setup lang="ts">
/******************************************************************************
 * GenresWidget
 * The Music page's "Genres" card — four genres, toggled via the header
 * WidgetModeToggle between "popular" (default; most total file duration),
 * latest (newest track) and a random pick. All sets arrive as Inertia props
 * (see MusicController). Defaults to popular because "latest genre" is a weak
 * signal here — genres have no date of their own.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetModeToggle from "Components/UI/Widget/WidgetModeToggle.vue";
import { useWidgetMode } from "Composables/useWidgetMode";
import type { GenreEntry, WidgetMode, WidgetModes } from "Types/music";
import WidgetList, { type WidgetListItem } from "./WidgetList.vue";

const props = defineProps<WidgetModes<GenreEntry>>();

const { t } = useI18n();

/** Modes this widget offers (shared with the toggle and the persisted mode). */
const modes: WidgetMode[] = ["latest", "popular", "random"];

/** Active mode — restored from localStorage, defaulting to popular. */
const mode = useWidgetMode("genres", "popular", modes);

/** Active-mode genres — a plain name list (no secondary line). Falls back to `latest` if a mode's set is absent. */
/**
 * Active-mode genres as list entries.
 *
 * The three numbers use exactly the rules the genre's own page uses — artists and albums by
 * DOMINANT genre, songs literally — so a reader meeting the same genre in both places is
 * not told two different things. All three always render: 0 is an answer here.
 */
const items = computed<WidgetListItem[]>(() =>
    (props[mode.value] ?? props.latest).map(genre => ({
        id: genre.id,
        name: genre.name,
        href: genre.href,
        pips: [
            { icon: "artist", value: String(genre.artists), label: t("music.pips.artistCount") },
            { icon: "album", value: String(genre.albums), label: t("music.pips.albumCount") },
            { icon: "song", value: String(genre.songs), label: t("music.pips.songCount") }
        ]
    }))
);
</script>

<template>
    <widget :refresh="'genres'" skeleton="entries">
        <template #title>
            <icon name="genre" />
            {{ t("music.widgets.genres") }}
            <widget-mode-toggle v-model="mode" name="genres-mode" :modes="modes" popular-by="duration" />
        </template>
        <widget-list :items="items" />
        <template #footer>
            <Link href="/music/genres" class="btn btn-primary">{{ t("music.seeAll") }}</Link>
        </template>
    </widget>
</template>
