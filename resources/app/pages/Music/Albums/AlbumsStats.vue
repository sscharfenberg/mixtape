<script setup lang="ts">
/******************************************************************************
 * AlbumsStats
 * The strip above the Albums listing — how many albums there are, then one tile per question
 * worth doing something about: never played, added this week, missing a track, holding only one.
 *
 * THE SAME ARRANGEMENT AS SongsStats, deliberately not a shared component. What the two have in
 * common is the tile grid (StatTiles) and the server's payload shape (ListingStats); what differs
 * is every tile's meaning, its glyph and its wording — so a shared "listing strip" would be a
 * component whose whole body is a per-listing lookup table, with the page-local file it replaced
 * still needed to hold the tables. Two small files that each read top to bottom beat one that
 * cannot be read without knowing which listing is asking.
 *
 * TWO OF THESE COUNTS ARE THINGS THE TABLE CANNOT SHOW. An album's columns are all per-album and
 * all sortable, so a "most played" tile would be a sort in tile clothing — where a gap in the
 * track numbering is invisible in the listing and takes opening albums one at a time to find.
 *
 * The link's word and the tile's mark come from `active`, and the href from the server: see
 * SongsStats, which carries why that split is where it is.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import StatTiles, { type StatTile } from "Components/UI/Widget/StatTiles.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetGroup from "Components/UI/Widget/WidgetGroup.vue";
import type { AlbumFilterKey, AlbumStats } from "Types/music";
import { formatDecimals } from "Utils/formatting";

const props = defineProps<AlbumStats>();

const { t, locale } = useI18n();

/**
 * Which glyph stands for each filter.
 *
 * `mute` and `recent` are the songs strip's, because they are the same two questions and a
 * reader meeting them twice should meet one glyph. `warning` for the gap — it is the one tile
 * here that reports a FAULT rather than a fact — and `song` for an album holding a single track,
 * which is what that album really is.
 */
const ICONS: Record<AlbumFilterKey, string> = {
    "never-played": "mute",
    "added-this-week": "recent",
    incomplete: "warning",
    "single-track": "song"
};

/**
 * The tiles: the album count, then the server's filters in the order it sent them (AlbumFilter's
 * own case order).
 *
 * Each filter is translated by its own key, so a new one is a case, a translation and an icon
 * with nothing here to keep in step. The total borrows the collection card's wording, since it is
 * the same number that card shows.
 */
const tiles = computed<StatTile[]>(() => [
    {
        key: "total",
        icon: "album",
        value: [formatDecimals(props.total, locale.value)],
        label: t("music.stats.label.albums"),
        hint: t("music.stats.hint.albums")
    },
    ...props.filters.map(stat => ({
        key: stat.key,
        icon: ICONS[stat.key],
        value: [formatDecimals(stat.count, locale.value)],
        label: t(`music.albumFilters.label.${stat.key}`),
        hint: t(`music.albumFilters.hint.${stat.key}`),
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
                <icon name="album" />
                {{ t("music.albumFilters.header") }}
            </template>
            <stat-tiles :tiles="tiles" />
        </widget>
    </widget-group>
</template>
