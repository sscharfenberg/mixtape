<script setup lang="ts">
/******************************************************************************
 * SongsStats
 * The strip above the Songs listing — the library's size, then one tile per question worth
 * doing something about: songs never played, songs added this week, songs filed twice, songs
 * travelling with no artwork of their own.
 *
 * EVERY TILE BUT THE FIRST IS A DOOR. Its number is followed by "anzeigen", which visits the
 * same listing narrowed to exactly the rows it counted (`?filter=`, one value per SongFilter
 * case) — the count and the filter are one predicate on the server, so the link cannot land on
 * a different number than the tile showed. A count with no way to reach it is a poster; this
 * strip exists because the listing used to open straight onto its table.
 *
 * THE SERVER DECIDES WHETHER A TILE HAS A LINK AT ALL, and it is worth knowing why that is not
 * this component's call: an href is absent for a count of zero (nothing to show) and points
 * BACK OUT — to the unfiltered listing — for the filter currently applied, which is the only
 * door out of a filtered table. Both readings are about routes and state, which
 * SongsController owns for every other link on the page too. Here we only choose the WORD:
 * "anzeigen" for a door in, "alle anzeigen" for the door out.
 *
 * The counts describe the whole library and never the filtered view, so they hold still while a
 * reader works through one of them — SongsController carries that argument.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
import StatTiles, { type StatTile } from "Components/UI/Widget/StatTiles.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import WidgetGroup from "Components/UI/Widget/WidgetGroup.vue";
import type { SongFilterKey, SongStats } from "Types/music";
import { formatDecimals } from "Utils/formatting";

const props = defineProps<SongStats>();

const { t, locale } = useI18n();

/**
 * Which glyph stands for each filter.
 *
 * The one table this component owes: a sprite name is neither the server's business nor the
 * translator's. `mute` for never played (a song that has never sounded), `recent` for the new
 * arrivals — the same glyph the widgets' "latest" mode wears, so the two mean one thing —
 * `copy` for the same recording twice, `cover` for the artwork that is missing.
 */
const ICONS: Record<SongFilterKey, string> = {
    "never-played": "mute",
    "added-this-week": "recent",
    duplicates: "copy",
    "no-cover": "cover"
};

/**
 * The tiles: the total, then the server's filters in the order it sent them (SongFilter's own
 * case order).
 *
 * TRANSLATED BY THE FILTER'S OWN KEY — `music.songFilters.label["never-played"]` — rather than
 * through a second mapping table, so a new filter is a case, a translation and an icon, with
 * nothing here to keep in step. The total tile borrows the collection card's wording, because
 * it is the same number that card shows and two phrasings for one fact is one too many.
 *
 * Counts arrive raw and are formatted here against the active locale, like every other number
 * in this app (`1.234` reads as four digits in the wrong language otherwise).
 */
const tiles = computed<StatTile[]>(() => [
    {
        key: "total",
        icon: "song",
        value: [formatDecimals(props.total, locale.value)],
        label: t("music.stats.label.songs"),
        hint: t("music.stats.hint.songs")
    },
    ...props.filters.map(stat => ({
        key: stat.key,
        icon: ICONS[stat.key],
        value: [formatDecimals(stat.count, locale.value)],
        label: t(`music.songFilters.label.${stat.key}`),
        hint: t(`music.songFilters.hint.${stat.key}`),
        // Marked as well as worded: the link says "alle anzeigen", and the tile itself says which
        // question the table below is currently answering — see StatTiles for why the word alone
        // is not enough for a reader who arrived at a filtered URL rather than pressing a tile.
        active: stat.active,
        // No href, no link — see the banner for who decides that and why it is not here.
        action: stat.href
            ? {
                  href: stat.href,
                  label: stat.active ? t("music.songFilters.showAll") : t("music.songFilters.show")
              }
            : undefined
    }))
]);
</script>

<template>
    <widget-group>
        <!-- `wide`, so the strip is a row of its own above the table rather than a card with a
             hole beside it: this group holds exactly one widget. -->
        <widget wide>
            <template #title>
                <icon name="song" />
                {{ t("music.songFilters.header") }}
            </template>
            <stat-tiles :tiles="tiles" />
        </widget>
    </widget-group>
</template>
