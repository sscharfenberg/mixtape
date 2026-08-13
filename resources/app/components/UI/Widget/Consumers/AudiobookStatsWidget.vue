<script setup lang="ts">
/******************************************************************************
 * AudiobookStatsWidget
 * The Audiobooks page's "Alle Hörbücher" card — a WIDE Widget holding the six numbers that
 * describe the audiobook collection.
 *
 * A sibling of StatsWidget rather than a mode of it: the two cards answer different questions
 * (an album count against a book count, artists and genres against authors and narrators) and
 * share the thing that is actually worth sharing — StatTiles, which owns the layout and the
 * unbreakable values, and carries the measurements behind both.
 *
 * ITS SEARCH IS SCOPED TO THIS AREA. The field is the shared SearchHub, told to answer with
 * audiobooks alone — a box on the Audiobooks page returning songs would send a reader
 * somewhere they were not browsing. The header's overlay is the one that searches both.
 *
 * Values arrive raw from AudiobooksController and are formatted here: counts locale-aware,
 * size bytes → GB/MB, and playtime seconds → a "months, days, hours, minutes, seconds"
 * breakdown. Months are a flat 30 days (a duration has no calendar), so the parts always sum
 * back to the exact total.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import SearchHub from "Components/Search/SearchHub.vue";
import Icon from "Components/UI/Icon.vue";
import type { StatTile } from "Components/UI/Widget/StatTiles.vue";
import StatTiles from "Components/UI/Widget/StatTiles.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import type { AudiobookStats } from "Types/audiobooks";
import type { SearchKind } from "Types/search";
import { formatDecimals, formatDurationParts, formatFileSize } from "Utils/formatting";

const props = defineProps<AudiobookStats>();

const { t, locale } = useI18n();

/**
 * The one kind this card's field may answer with (the owner's call): audiobooks, never music.
 * It sits on the Audiobooks page, and the header's overlay is the box that searches both.
 */
const AUDIOBOOK_KINDS: SearchKind[] = ["audiobook"];

/**
 * The playtime's units as the pieces a line may break between — "2 Tage,", "3 Stunden,".
 *
 * The comma rides at the END of the piece it follows, because that is where it belongs when
 * the line breaks: "2 Tage," stays whole and the break lands in the space after it. The same
 * arrangement the music card uses, and its docblock carries the rest.
 */
const playtimeParts = computed<string[]>(() => {
    const parts = formatDurationParts(props.playtimeSeconds, (key, count) => t(`common.duration.${key}`, count));

    return parts.map((part, index) => (index === parts.length - 1 ? part : `${part},`));
});

/**
 * The six tiles.
 *
 * The glyphs are the ones this area already uses for the same facts — `audiobook`, `track`,
 * `author`, `narrator`, `duration`, and `file` for the one fact that is about bytes rather
 * than about books — so a reader meets one meaning per icon across the page.
 */
const tiles = computed<StatTile[]>(() => [
    {
        key: "books",
        icon: "audiobook",
        value: [formatDecimals(props.books, locale.value)],
        label: t("audiobooks.columns.books"),
        hint: t("audiobooks.stats.hint.books")
    },
    {
        key: "chapters",
        icon: "track",
        value: [formatDecimals(props.chapters, locale.value)],
        label: t("audiobooks.columns.chapters"),
        hint: t("audiobooks.stats.hint.chapters")
    },
    {
        key: "size",
        icon: "file",
        value: [formatFileSize(props.sizeBytes, locale.value)],
        label: t("music.columns.size"),
        hint: t("audiobooks.stats.hint.size")
    },
    {
        key: "authors",
        icon: "author",
        value: [formatDecimals(props.authors, locale.value)],
        label: t("audiobooks.columns.authors"),
        hint: t("audiobooks.stats.hint.authors")
    },
    {
        key: "narrators",
        icon: "narrator",
        value: [formatDecimals(props.narrators, locale.value)],
        label: t("audiobooks.columns.narrators"),
        hint: t("audiobooks.stats.hint.narrators")
    },
    {
        key: "playtime",
        icon: "duration",
        value: playtimeParts.value,
        label: t("audiobooks.columns.playtime"),
        hint: t("audiobooks.stats.hint.playtime"),
        wide: true
    }
]);
</script>

<template>
    <widget wide>
        <template #title>
            <icon name="audiobook" />
            {{ t("audiobooks.widgets.allAudiobooks") }}
        </template>
        <div class="widget-stats">
            <search-hub name="audiobooks" :only="AUDIOBOOK_KINDS" />

            <stat-tiles :tiles="tiles" />
        </div>
    </widget>
</template>

<style scoped lang="scss">
@use "sass:map";
@use "Abstracts/sizes" as s;

/* A full-height column so the tiles take the card's spare height rather than leaving it as a
   blank strip. `height: 100%`, NOT `flex: 1` — WidgetBody is a plain block (a stretched grid
   item) rather than a flex container, so this column is not a flex ITEM and has no line to
   grow along; the body does stretch to its band, so a percentage resolves. The music card
   carries the same two lines and the fuller reasoning. */
.widget-stats {
    display: flex;
    flex-direction: column;

    height: 100%;

    gap: map.get(s.$c-widget, "cell-gap");
}
</style>
