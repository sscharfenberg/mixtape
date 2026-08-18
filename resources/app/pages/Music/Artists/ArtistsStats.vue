<script setup lang="ts">
/******************************************************************************
 * ArtistsStats
 * The strip above the Artists listing — how many artists there are, then one tile per question
 * worth acting on: never played, no album of their own, new this month, and a credit that looks
 * like several artists.
 *
 * TWO OF THEM ARE THINGS THE TABLE CANNOT SHOW. Its columns — albums, songs, playing time, size,
 * plays — are all sortable, so a "most played" tile would be a sort in tile clothing; a name that
 * reads as several artists, and anything about dates, have no column at all.
 *
 * The link's word and the tile's mark come from `active`, and every href from the server: see
 * SongsStats, which carries why that split is where it is, and docs/browse-stats.md for the rule
 * the whole arrangement rests on.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import StatTiles, { type StatTile } from "Components/UI/Widget/StatTiles.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetGroup from "Components/UI/Widget/WidgetGroup.vue";
import type { ArtistFilterKey, ArtistStats } from "Types/music";
import { formatDecimals } from "Utils/formatting";

const props = defineProps<ArtistStats>();

const { t, locale } = useI18n();

/**
 * Which glyph stands for each filter.
 *
 * `mute` and `recent` are the songs and albums strips', because they are the same two questions and
 * a reader meeting them again should meet one glyph. `album` for an artist with no album of their
 * own — the subject is albums, and the tile is about their absence — and `abc` for a name that
 * reads as several, the same "letters" glyph the export modal wears where a question is about
 * characters rather than about music.
 */
const ICONS: Record<ArtistFilterKey, string> = {
    "never-played": "mute",
    "compilations-only": "album",
    "added-this-month": "recent",
    "lookalike-name": "abc"
};

/**
 * The tiles: the total, then the server's filters in the order it sent them (the enum's own case
 * order).
 *
 * Each filter is translated by its own key, so a new one is a case, a translation and an icon with
 * nothing here to keep in step. The total borrows the collection card's wording, since it is the
 * same number that card shows.
 */
const tiles = computed<StatTile[]>(() => [
    {
        key: "total",
        icon: "artist",
        value: [formatDecimals(props.total, locale.value)],
        label: t("music.stats.label.artists"),
        hint: t("music.stats.hint.artists")
    },
    ...props.filters.map(stat => ({
        key: stat.key,
        icon: ICONS[stat.key],
        value: [formatDecimals(stat.count, locale.value)],
        label: t(`music.artistFilters.label.${stat.key}`),
        hint: t(`music.artistFilters.hint.${stat.key}`),
        active: stat.active,
        action: stat.href
            ? {
                  href: stat.href,
                  label: stat.active ? t("music.listingFilters.showAll") : t("music.listingFilters.show")
              }
            : undefined
    }))
]);
</script>

<template>
    <widget-group>
        <!-- `wide`: this group holds one card, which takes the row above the table. -->
        <widget wide>
            <template #title>
                <icon name="artist" />
                {{ t("music.artistFilters.header") }}
            </template>
            <stat-tiles :tiles="tiles" />
        </widget>
    </widget-group>
</template>
