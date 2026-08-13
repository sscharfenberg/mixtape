<script setup lang="ts">
/******************************************************************************
 * StatsWidget
 * The Music page's "Alle Musik" card — a WIDE Widget (spans two grid columns for room) holding the
 * page's SEARCH HUB over a grid of collection stat tiles, each an icon, a label and a big number,
 * wrapped in a Tooltip that explains how the number is derived. Music-only, matching the browse
 * widgets; no mode toggle or footer.
 *
 * THE FILE NAME IS NARROWER THAN THE JOB, since 2026-08-13 (docs/search.md → "The Music page"):
 * this was the stats card, and the heading said _Statistik_. It is kept as the search's home
 * rather than a new widget beside it because the tiles are the right neighbours for a search
 * field — they describe what there is to search — and because a reader who came to browse still
 * gets them first.
 *
 * THE RESULTS FLOAT OVER THE CARD; they neither push the tiles down nor replace them (the owner's
 * call, 2026-08-13 — the first version swapped the tiles out, which left the card looking empty
 * mid-search and told a reader who was only checking a number that their page had gone). So the
 * card is never disturbed, and the answer arrives on top of it.
 *
 * WHICH IS WHY THE PANEL IS A `[popover]` RATHER THAN AN ABSOLUTE BOX. Two properties on the card
 * make an ordinary overlay impossible, and both are there for good reasons of their own: Widget
 * sets `overflow: hidden` to clip its title strip to the card's rounded corners, which would cut
 * the panel off at the bottom border it is meant to hang over — and `isolation: isolate` to keep
 * the loader overlay's z-index inside the card, which would paint the panel UNDER the four browse
 * widgets that follow it in the DOM. A showing popover is in the browser's TOP LAYER: no ancestor
 * clips it and no ancestor stacking context contains it. It is anchored to the field with CSS
 * anchor positioning, the same mechanism PopOver and the tooltips use here.
 *
 * `manual`, NOT `auto`, so it does not light-dismiss: the panel is showing exactly while there is a
 * query, and a click on a stat tile should not leave the field full and the answer gone. Escape and
 * the clear button both empty the field, which closes it.
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
import SearchHub from "Components/Search/SearchHub.vue";
import Icon from "Components/UI/Icon.vue";
import StatTiles from "Components/UI/Widget/StatTiles.vue";
import type { StatTile } from "Components/UI/Widget/StatTiles.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import type { CollectionStats } from "Types/music";
import type { SearchKind } from "Types/search";
import { formatDecimals, formatDurationParts, formatFileSize } from "Utils/formatting";

const props = defineProps<CollectionStats>();

const { t, locale } = useI18n();

/**
 * The kinds this card's field may answer with (the owner's call): the music ones, never
 * audiobooks. It sits on the Music page, and a book turning up in it would send a reader to an
 * area they were not browsing — the header's overlay is the box that searches everything.
 */
const MUSIC_KINDS: SearchKind[] = ["artist", "album", "playlist", "song", "genre"];

/**
 * The six tiles that fill the auto-fit grid (formatted via Utils/formatting).
 *
 * The glyphs are the ones the music widgets' pips already use for the same facts, so a reader meets
 * one meaning per icon across the page: `song`, `album`, `artist`, `genre`, `duration`, and `file`
 * for the one fact that is about bytes rather than about music.
 *
 * PLAYTIME IS ONE OF THEM NOW, styled like the rest (the owner's call, 2026-08-13). It was a
 * full-width row of its own below the grid, because its value is a PHRASE rather than a number —
 * "6 Stunden, 38 Minuten, 3 Sekunden", which is what that tile is for: a library's playing time runs
 * to months, so a clock (`4761:23:11`) would be a worse answer than a long one. As a plain cell the
 * card is one set of tiles rather than a set plus an exception.
 */
const tiles = computed<StatTile[]>(() => [
    {
        key: "songs",
        icon: "song",
        value: [formatDecimals(props.songs, locale.value)],
        label: t("music.stats.label.songs"),
        hint: t("music.stats.hint.songs")
    },
    {
        key: "size",
        icon: "file",
        value: [formatFileSize(props.sizeBytes, locale.value)],
        label: t("music.stats.label.size"),
        hint: t("music.stats.hint.size")
    },
    {
        key: "albums",
        icon: "album",
        value: [formatDecimals(props.albums, locale.value)],
        label: t("music.stats.label.albums"),
        hint: t("music.stats.hint.albums")
    },
    {
        key: "artists",
        icon: "artist",
        value: [formatDecimals(props.artists, locale.value)],
        label: t("music.stats.label.artists"),
        hint: t("music.stats.hint.artists")
    },
    {
        key: "genres",
        icon: "genre",
        value: [formatDecimals(props.genres, locale.value)],
        label: t("music.stats.label.genres"),
        hint: t("music.stats.hint.genres")
    },
    // Only where there is a range to show — see `yearRange`. Spread rather than a `v-if` in the
    // template, so the list stays the one description of what the card holds.
    ...(yearRange.value === null
        ? []
        : [{
            key: "years",
            icon: "calendar",
            value: [yearRange.value],
            label: t("music.stats.label.years"),
            hint: t("music.stats.hint.years")
        }]),
    {
        key: "playtime",
        icon: "duration",
        value: playtimeParts.value,
        label: t("music.stats.label.playtime"),
        hint: t("music.stats.hint.playtime"),
        wide: true
    }
]);

/**
 * The playtime's units as the pieces a line may break between — "2 Tage,", "3 Stunden,", "4 Minuten".
 *
 * i18n is injected into the formatter: each unit resolves to a pluralised label from the shared
 * `common.duration.*` keys. Months are a flat 30 days (a duration has no calendar), so the parts
 * always sum back to the exact total.
 *
 * THE COMMA RIDES AT THE END OF THE PIECE IT FOLLOWS rather than travelling as a separator of its
 * own, because that is where it belongs when the line does break: "2 Tage," stays whole and the
 * break lands in the space after it, never before a comma that then opens a line. The spaces
 * between pieces are the template's, deliberately outside the unbreakable spans — they are the only
 * break opportunities the value has left.
 */
const playtimeParts = computed<string[]>(() => {
    const parts = formatDurationParts(props.playtimeSeconds, (key, count) => t(`common.duration.${key}`, count));

    return parts.map((part, index) => (index === parts.length - 1 ? part : `${part},`));
});

/**
 * The album years as one range — "1965–2024", or a single year for a collection that spans none.
 *
 * Null when no album carries a year at all, which drops the tile: a range with a dash and nothing
 * either side is worse than one fewer fact. An EN DASH without spaces, the typographic form for a
 * span of years in both catalogues.
 *
 * NOT `formatDecimals`, unlike every count on this card, and that is the one thing to be careful of
 * here: a year is not a quantity, so a German locale would render 1994 as "1.994". The album and song
 * widgets print theirs with `String()` for the same reason.
 */
const yearRange = computed<string | null>(() => {
    const { firstYear, lastYear } = props;
    if (firstYear === null || lastYear === null) return null;

    return firstYear === lastYear ? String(firstYear) : `${firstYear}–${lastYear}`;
});

</script>

<template>
    <widget wide>
        <template #title>
            <icon name="music" />
            {{ t("music.widgets.allMusic") }}
        </template>
        <div class="widget-stats">
            <search-hub name="music" :only="MUSIC_KINDS" />

            <stat-tiles :tiles="tiles" />
        </div>

    </widget>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/z-indexes" as z;

/* A full-height column: the field, then the tiles, which take everything left over. The card is a
   subgrid whose body band is as tall as the tallest card in its row, and the tiles used to leave
   that spare height as a blank strip at the bottom (before this, `centered` floated the whole
   column in the middle of it instead).

   `height: 100%`, NOT `flex: 1` — which was the first attempt and did nothing at all, because
   WidgetBody is a plain block (a stretched grid item) rather than a flex container, so this column
   is not a flex ITEM and had no line to grow along. The body does stretch to its band, so a
   percentage resolves here and the `flex: 1` on the grid below finally has something to divide. */
.widget-stats {
    display: flex;
    flex-direction: column;

    height: 100%;

    gap: map.get(s.$c-widget, "cell-gap");
}









</style>
