<script setup lang="ts">
/******************************************************************************
 * StatsWidget
 * The Music page's collection-stats card — a WIDE Widget (spans two grid
 * columns for room) whose body is a grid of stat tiles (big number + label),
 * each wrapped in a Tooltip that explains how the number is derived. Music-only,
 * matching the browse widgets; no mode toggle or footer — stats are singular.
 *
 * Layout: the five compact tiles sit in their own `auto-fit` grid so they fill
 * the full width (a full-row-spanning tile in the same grid would stop `auto-fit`
 * collapsing the spare track, leaving a gap); the long playtime breakdown is a
 * separate full-width row below.
 *
 * Values arrive raw from MusicController (CollectionStats) and are formatted
 * here: counts locale-aware, size bytes → GB/MB, and playtime seconds → a human
 * "months, days, hours, minutes, seconds" breakdown. Months are a flat 30 days
 * (a duration has no calendar), so the parts always sum back to the exact total.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Tooltip from "Components/UI/Tooltip/Tooltip.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import type { CollectionStats } from "Types/music";
import { formatDecimals, formatDuration, formatFileSize } from "Utils/formatting";

const props = defineProps<CollectionStats>();

const { t, locale } = useI18n();

/** One stat tile — a formatted value, its label, and a tooltip explaining the calculation. */
type StatTile = { key: string; value: string; label: string; hint: string };

/** The five compact count/size tiles that fill the auto-fit grid (formatted via Utils/formatting). */
const compactTiles = computed<StatTile[]>(() => [
    {
        key: "songs",
        value: formatDecimals(props.songs, locale.value),
        label: t("music.stats.label.songs"),
        hint: t("music.stats.hint.songs")
    },
    {
        key: "size",
        value: formatFileSize(props.sizeBytes, locale.value),
        label: t("music.stats.label.size"),
        hint: t("music.stats.hint.size")
    },
    {
        key: "albums",
        value: formatDecimals(props.albums, locale.value),
        label: t("music.stats.label.albums"),
        hint: t("music.stats.hint.albums")
    },
    {
        key: "artists",
        value: formatDecimals(props.artists, locale.value),
        label: t("music.stats.label.artists"),
        hint: t("music.stats.hint.artists")
    },
    {
        key: "genres",
        value: formatDecimals(props.genres, locale.value),
        label: t("music.stats.label.genres"),
        hint: t("music.stats.hint.genres")
    }
]);

/**
 * The playtime tile — its own full-width row, since the breakdown is a long
 * phrase. i18n is injected into formatDuration: each unit resolves to a
 * pluralised label from the shared `common.duration.*` keys.
 */
const playtimeTile = computed<StatTile>(() => ({
    key: "playtime",
    value: formatDuration(props.playtimeSeconds, (key, count) => t(`common.duration.${key}`, count)),
    label: t("music.stats.label.playtime"),
    hint: t("music.stats.hint.playtime")
}));
</script>

<template>
    <widget wide centered>
        <template #title>{{ t("music.widgets.stats") }}</template>
        <div class="widget-stats">
            <div class="widget-stats__grid">
                <tooltip v-for="tile in compactTiles" :key="tile.key" :text="tile.hint" class="widget-stats__cell">
                    <span class="widget-stats__value">{{ tile.value }}</span>
                    <span class="widget-stats__label">{{ tile.label }}</span>
                </tooltip>
            </div>
            <tooltip :text="playtimeTile.hint" class="widget-stats__cell widget-stats__cell--wide">
                <span class="widget-stats__value">{{ playtimeTile.value }}</span>
                <span class="widget-stats__label">{{ playtimeTile.label }}</span>
            </tooltip>
        </div>
    </widget>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

// Full-width column: the compact-tile grid, then the full-width playtime row.
.widget-stats {
    display: flex;
    flex-direction: column;

    gap: 0.75rem;
}

// As many ~7rem tiles as fit, stretched to fill. No full-row-spanning item lives
// in this grid, so auto-fit collapses spare tracks instead of leaving a gap.
.widget-stats__grid {
    display: grid;

    grid-template-columns: repeat(auto-fit, minmax(7rem, 1fr));

    gap: 0.75rem;
}

// Each tile is the Tooltip's root span (class merged onto it); as a grid/flex item
// its inline-flex is blockified, so we only set the column direction + centring.
.widget-stats__cell {
    align-items: center;
    justify-content: center;
    flex-direction: column;

    padding: map.get(s.$c-widget, "cell-padding");
    gap: 0.15rem;

    background-color: map.get(c.$c-widget, "cell-background");
    border-radius: map.get(s.$c-widget, "cell-radius");

    text-align: center;
}

.widget-stats__value {
    color: map.get(c.$c-widget, "surface");

    font-size: 1.8rem;
    font-weight: 700;
    line-height: 1.1;

    // the wide playtime value is a long phrase — dial it down so it doesn't shout.
    .widget-stats__cell--wide & {
        font-size: 1.2rem;
    }
}

.widget-stats__label {
    color: map.get(c.$c-widget, "footer-surface");

    font-size: 0.8rem;
}
</style>
