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
import Icon from "Components/UI/Icon.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetModeToggle from "Components/UI/Widget/WidgetModeToggle.vue";
import { useWidgetMode } from "Composables/useWidgetMode";
import type { ArtistEntry, WidgetMode, WidgetModes } from "Types/music";
import { formatClock } from "Utils/formatting";
import WidgetList, { type WidgetListItem } from "./WidgetList.vue";

const props = defineProps<WidgetModes<ArtistEntry>>();

const { t } = useI18n();

/** Modes this widget offers (shared with the toggle and the persisted mode). */
const modes: WidgetMode[] = ["latest", "popular", "random"];

/** Active mode — restored from localStorage, defaulting to popular. */
const mode = useWidgetMode("artists", "popular", modes);

/** Active-mode artists — a plain name list (no secondary line). Falls back to `latest` if a mode's set is absent. */
/**
 * Active-mode artists as list entries: what each one adds up to across the collection.
 *
 * All three pips always render — unlike the albums/songs widgets, where a pip stands for a
 * TAG that can be missing, these are counts, and 0 is an answer rather than absent data.
 */
const items = computed<WidgetListItem[]>(() =>
    (props[mode.value] ?? props.latest).map(artist => ({
        id: artist.id,
        name: artist.name,
        href: artist.href,
        pips: [
            { icon: "album", value: String(artist.albums), label: t("music.pips.albumCount") },
            { icon: "song", value: String(artist.songs), label: t("music.pips.songCount") },
            { icon: "duration", value: formatClock(artist.duration) ?? "", label: t("music.pips.totalDuration") }
        ]
    }))
);
</script>

<template>
    <widget :refresh="'artists'" skeleton="entries">
        <template #title>
            <icon name="artist" />
            {{ t("music.widgets.artists") }}
            <widget-mode-toggle v-model="mode" name="artists-mode" :modes="modes" popular-by="duration" />
        </template>
        <widget-list :items="items" />
        <template #footer>
            <Link href="/music/artists" class="btn btn-primary" prefetch>{{ t("music.seeAll") }}</Link>
        </template>
    </widget>
</template>
