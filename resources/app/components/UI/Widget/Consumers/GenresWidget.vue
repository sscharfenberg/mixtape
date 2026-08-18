<script setup lang="ts">
/******************************************************************************
 * GenresWidget
 * The Music page's "Genres" card — four genres, toggled via the header
 * WidgetModeToggle between "popular" (default; the reader's own listens), latest
 * (newest track) and a random pick. All sets arrive as Inertia props (see
 * MusicController). Defaults to popular because "latest genre" is a weak signal
 * here — genres have no date of their own. Which also means this card can OPEN
 * empty, on a library nobody has listened to yet, exactly as the artists one can.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetModeToggle from "Components/UI/Widget/WidgetModeToggle.vue";
import { useWidgetMode } from "Composables/useWidgetMode";
import type { GenreEntry, WidgetMode, WidgetModes } from "Types/music";
import { formatTimesPlayed } from "Utils/formatting";
import WidgetList, { type WidgetListItem } from "./WidgetList.vue";

const props = defineProps<WidgetModes<GenreEntry>>();

const { t } = useI18n();

/** Modes this widget offers (shared with the toggle and the persisted mode). */
const modes: WidgetMode[] = ["latest", "popular", "random"];

/** Active mode — restored from localStorage, defaulting to popular. */
const mode = useWidgetMode("genres", "popular", modes);

/** The active mode's raw set, falling back to `latest` if a mode's set is absent. */
const active = computed(() => props[mode.value] ?? props.latest);

/**
 * Active-mode genres as list entries.
 *
 * The three numbers use exactly the rules the genre's own page uses — artists and albums by
 * DOMINANT genre, songs literally — so a reader meeting the same genre in both places is
 * not told two different things. All three always render: 0 is an answer here.
 */
const items = computed<WidgetListItem[]>(() =>
    active.value.map(genre => ({
        id: genre.id,
        name: genre.name,
        href: genre.href,
        pips: [
            { icon: "artist", value: String(genre.artists), label: t("music.pips.artistCount") },
            { icon: "album", value: String(genre.albums), label: t("music.pips.albumCount") },
            { icon: "song", value: String(genre.songs), label: t("music.pips.songCount") },
            // Dropped at zero, unlike the three counts above it: this one is about the reader,
            // not the collection — see ArtistsWidget, which documents the rule.
            genre.plays > 0
                ? { icon: "plays", value: formatTimesPlayed(genre.plays), label: t("music.pips.playCount") }
                : null
        ].filter(pip => pip !== null)
    }))
);

/**
 * Empty-state line for the list. "popular" holds only genres the reader has played, so an
 * empty set there is "nothing listened to yet" rather than "no genres" — and, popular being
 * this card's default mode, it is what a new instance opens on. The other modes take the
 * generic empty.
 */
const emptyText = computed(() =>
    mode.value === "popular" && active.value.length === 0 ? t("music.notEnoughData") : undefined
);
</script>

<template>
    <widget :refresh="'genres'" skeleton="entries">
        <template #title>
            <icon name="genre" />
            {{ t("music.widgets.genres") }}
            <widget-mode-toggle v-model="mode" name="genres-mode" :modes="modes" />
        </template>
        <widget-list :items="items" :empty-text="emptyText" />
        <template #footer>
            <Link href="/music/genres" class="btn btn-primary" prefetch>{{ t("music.seeAll") }}</Link>
        </template>
    </widget>
</template>
