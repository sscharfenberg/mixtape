<script setup lang="ts">
/******************************************************************************
 * StatsWidget
 * The Music page's "Deine Musik" card — a WIDE Widget (spans two grid columns for room) holding
 * the page's SEARCH HUB over a grid of collection stat tiles (big number + label), each wrapped in
 * a Tooltip that explains how the number is derived. Music-only, matching the browse widgets; no
 * mode toggle or footer.
 *
 * THE FILE NAME IS NARROWER THAN THE JOB, since 2026-08-13 (docs/search.md → "The Music page"):
 * this was the stats card, and the heading said _Statistik_. It is kept as the search's home
 * rather than a new widget beside it because the tiles are the right neighbours for a search
 * field — they describe what there is to search — and because a reader who came to browse still
 * gets them first.
 *
 * THE RESULTS REPLACE THE TILES, they do not push them down. The widget is a fixed thing in a
 * subgrid, and a block that grows by 300px shoves the four browse widgets off the fold; clearing
 * the field puts the tiles back. The result area is additionally capped lower here than in the
 * header overlay, through `--search-results-height` — see the token's note.
 *
 * ITS SEARCH IS ITS OWN. useLibrarySearch is per-instance, not a singleton, so the query here and
 * the query in the header overlay are two questions — which is what stops opening the overlay
 * re-running whatever was left in this field.
 *
 * Values arrive raw from MusicController (CollectionStats) and are formatted here: counts
 * locale-aware, size bytes → GB/MB, and playtime seconds → a human "months, days, hours, minutes,
 * seconds" breakdown. Months are a flat 30 days (a duration has no calendar), so the parts always
 * sum back to the exact total.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import SearchField from "Components/Search/SearchField.vue";
import SearchResults from "Components/Search/SearchResults.vue";
import SearchScopeChips from "Components/Search/SearchScopeChips.vue";
import Tooltip from "Components/UI/Tooltip/Tooltip.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import { useLibrarySearch } from "Composables/useLibrarySearch";
import type { CollectionStats } from "Types/music";
import { formatDecimals, formatDuration, formatFileSize } from "Utils/formatting";

const props = defineProps<CollectionStats>();

const { t, locale } = useI18n();

// No `onNavigate`: opening a result leaves this page anyway, and there is no panel to put away.
const { query, scope, groups, loading, failed, active, tooShort, listboxId, activeOptionId, onKeydown } =
    useLibrarySearch();

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
        <template #title>{{ t("music.widgets.yourMusic") }}</template>
        <div class="widget-stats">
            <search-field
                v-model="query"
                :listbox-id="listboxId"
                :active-option-id="activeOptionId"
                :expanded="groups.length > 0"
                :loading="loading"
                @keydown="onKeydown"
            />

            <!-- The chips appear WITH the results rather than living above an empty field: six of
                 them are a row of noise on a page nobody has searched yet, and narrowing is only a
                 question once there is something to narrow. -->
            <search-scope-chips v-if="active" v-model="scope" name="music-search-scope" />

            <search-results
                v-if="active"
                :groups="groups"
                :listbox-id="listboxId"
                :active-option-id="activeOptionId"
                :loading="loading"
                :failed="failed"
                :too-short="tooShort"
            />

            <!-- The tiles are the search's alternative, not its neighbour — see the banner. -->
            <template v-else>
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
            </template>
        </div>
    </widget>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

// Full-width column: the search field, then either the results or the tiles.
.widget-stats {
    display: flex;
    flex-direction: column;

    gap: 0.75rem;

    // What the results may grow to HERE, which is lower than the header overlay's ceiling and a
    // fixed length rather than a share of the screen — the widget sits in a grid whose other four
    // cards must stay on the fold. SearchResults reads this through `--search-results-height`.
    --search-results-height: #{map.get(s.$c-search, "results-height-inline")};
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
