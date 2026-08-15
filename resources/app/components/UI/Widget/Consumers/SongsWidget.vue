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
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetModeToggle from "Components/UI/Widget/WidgetModeToggle.vue";
import { useWidgetMode } from "Composables/useWidgetMode";
import type { SongEntry, WidgetMode, WidgetModes } from "Types/music";
import { formatTimesPlayed } from "Utils/formatting";
import WidgetList, { type WidgetListItem } from "./WidgetList.vue";

const props = defineProps<WidgetModes<SongEntry>>();

const { t } = useI18n();

/** Modes this widget offers (shared with the toggle and the persisted mode). */
const modes: WidgetMode[] = ["latest", "popular", "random"];

/** Active mode — restored from localStorage, defaulting to latest. */
const mode = useWidgetMode("songs", "latest", modes);

/** The active mode's raw set, falling back to `latest` if a mode's set is absent. */
const active = computed(() => props[mode.value] ?? props.latest);

/**
 * Active-mode songs as list entries. A pip is dropped rather than shown empty when the tag
 * is missing — a file crediting nobody has no artist, and "—" says less than no chip.
 */
const items = computed<WidgetListItem[]>(() =>
    active.value.map(song => ({
        id: song.id,
        name: song.name,
        href: song.href,
        pips: [
            song.artist ? { icon: "artist", value: song.artist, label: t("music.columns.artist") } : null,
            song.year !== null ? { icon: "calendar", value: String(song.year), label: t("music.columns.year") } : null,
            // The reader's OWN listens — which is deliberately not what `popular` ranks by
            // (that is the whole household), so a card can lead the popular set showing a
            // small number here. MusicController's `songs()` explains the pair.
            song.plays > 0
                ? { icon: "plays", value: formatTimesPlayed(song.plays), label: t("music.pips.playCount") }
                : null
        ].filter(pip => pip !== null)
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
    <widget :refresh="'songs'" skeleton="entries">
        <template #title>
            <icon name="song" />
            {{ t("music.widgets.songs") }}
            <widget-mode-toggle v-model="mode" name="songs-mode" :modes="modes" popular-by="plays" />
        </template>
        <widget-list :items="items" :empty-text="emptyText" />
        <template #footer>
            <Link href="/music/songs" class="btn btn-primary" prefetch>{{ t("music.seeAll") }}</Link>
        </template>
    </widget>
</template>
