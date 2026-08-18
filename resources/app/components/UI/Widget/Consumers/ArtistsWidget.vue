<script setup lang="ts">
/******************************************************************************
 * ArtistsWidget
 * The Music page's "Artists" card — four artists, toggled via the header
 * WidgetModeToggle between "popular" (default; the reader's own listens), latest
 * (newest track) and a random pick. All sets arrive as Inertia props (see
 * MusicController). Defaults to popular because "latest artist" is a weak signal
 * here — artists have no date of their own. Which also means this card can OPEN
 * empty, on a library nobody has listened to yet: it then says "not enough data"
 * rather than ranking artists by something that is not popularity.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetModeToggle from "Components/UI/Widget/WidgetModeToggle.vue";
import { useWidgetMode } from "Composables/useWidgetMode";
import type { ArtistEntry, WidgetMode, WidgetModes } from "Types/music";
import { formatClock, formatTimesPlayed } from "Utils/formatting";
import WidgetList, { type WidgetListItem } from "./WidgetList.vue";

const props = defineProps<WidgetModes<ArtistEntry>>();

const { t } = useI18n();

/** Modes this widget offers (shared with the toggle and the persisted mode). */
const modes: WidgetMode[] = ["latest", "popular", "random"];

/** Active mode — restored from localStorage, defaulting to popular. */
const mode = useWidgetMode("artists", "popular", modes);

/** The active mode's raw set, falling back to `latest` if a mode's set is absent. */
const active = computed(() => props[mode.value] ?? props.latest);

/**
 * Active-mode artists as list entries: what each one adds up to across the collection.
 *
 * The first three pips always render — unlike the albums/songs widgets, where a pip stands
 * for a TAG that can be missing, these are counts, and 0 is an answer rather than absent
 * data.
 *
 * THE PLAY PIP IS THE EXCEPTION, and drops out at zero. It is not a fact about the
 * collection but about the reader, and "you have never played this" is nothing to report —
 * on a library not yet lived in it would put an identical "0" on every card in every widget.
 * Same rule as the hero tiles', and the same reason the listings draw a dash there.
 */
const items = computed<WidgetListItem[]>(() =>
    active.value.map(artist => ({
        id: artist.id,
        name: artist.name,
        href: artist.href,
        pips: [
            { icon: "album", value: String(artist.albums), label: t("music.pips.albumCount") },
            { icon: "song", value: String(artist.songs), label: t("music.pips.songCount") },
            { icon: "duration", value: formatClock(artist.duration) ?? "", label: t("music.pips.totalDuration") },
            artist.plays > 0
                ? { icon: "plays", value: formatTimesPlayed(artist.plays), label: t("music.pips.playCount") }
                : null
        ].filter(pip => pip !== null)
    }))
);

/**
 * Empty-state line for the list. "popular" holds only artists the reader has played, so an
 * empty set there is "nothing listened to yet" rather than "no artists" — and on THIS card it
 * is what a new instance opens on, since popular is its default mode. The other modes take
 * the generic empty.
 */
const emptyText = computed(() =>
    mode.value === "popular" && active.value.length === 0 ? t("music.notEnoughData") : undefined
);
</script>

<template>
    <widget :refresh="'artists'" skeleton="entries">
        <template #title>
            <icon name="artist" />
            {{ t("music.widgets.artists") }}
            <widget-mode-toggle v-model="mode" name="artists-mode" :modes="modes" />
        </template>
        <widget-list :items="items" :empty-text="emptyText" />
        <template #footer>
            <Link href="/music/artists" class="btn btn-primary" prefetch>{{ t("music.seeAll") }}</Link>
        </template>
    </widget>
</template>
