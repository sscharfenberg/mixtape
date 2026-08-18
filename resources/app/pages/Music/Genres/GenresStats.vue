<script setup lang="ts">
/******************************************************************************
 * GenresStats
 * The strip above the Genres listing — how many genres there are, then one tile per question worth
 * acting on: never played, one artist only, new this week, one song only.
 *
 * "ONE ARTIST ONLY" IS THE ONE THIS STRIP EARNS ITS SPACE WITH, and it is not the table's `artists`
 * column asked with a filter: that column counts artists whose MAIN genre this is, where the tile
 * counts the distinct performers of the genre's own songs. GenreFilter carries why the two
 * disagree, and how far — 74 of 140 genres on the live library.
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
import type { GenreFilterKey, GenreStats } from "Types/music";
import { formatDecimals } from "Utils/formatting";

const props = defineProps<GenreStats>();

const { t, locale } = useI18n();

/**
 * Which glyph stands for each filter.
 *
 * `mute` and `recent` are the other strips', being the same two questions. `artist` and `song` name
 * the subject each remaining tile counts, which is what those tiles are about — a genre that is one
 * artist's, a genre that is one song.
 */
const ICONS: Record<GenreFilterKey, string> = {
    "never-played": "mute",
    "one-artist": "artist",
    "added-this-week": "recent",
    "one-song": "song"
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
        icon: "genre",
        value: [formatDecimals(props.total, locale.value)],
        label: t("music.stats.label.genres"),
        hint: t("music.stats.hint.genres")
    },
    ...props.filters.map(stat => ({
        key: stat.key,
        icon: ICONS[stat.key],
        value: [formatDecimals(stat.count, locale.value)],
        label: t(`music.genreFilters.label.${stat.key}`),
        hint: t(`music.genreFilters.hint.${stat.key}`),
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
                <icon name="genre" />
                {{ t("music.genreFilters.header") }}
            </template>
            <stat-tiles :tiles="tiles" />
        </widget>
    </widget-group>
</template>
