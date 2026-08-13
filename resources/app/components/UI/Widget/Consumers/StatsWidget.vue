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
import { computed, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import SearchField from "Components/Search/SearchField.vue";
import SearchResults from "Components/Search/SearchResults.vue";
import SearchScopeChips from "Components/Search/SearchScopeChips.vue";
import Icon from "Components/UI/Icon.vue";
import Tooltip from "Components/UI/Tooltip/Tooltip.vue";
import Widget from "Components/UI/Widget/Widget.vue";
import { useLibrarySearch } from "Composables/useLibrarySearch";
import type { CollectionStats } from "Types/music";
import { formatDecimals, formatDurationParts, formatFileSize } from "Utils/formatting";

const props = defineProps<CollectionStats>();

const { t, locale } = useI18n();

// No `onNavigate`: opening a result leaves this page anyway, and there is no panel to put away.
const { query, scope, groups, loading, failed, active, tooShort, listboxId, activeOptionId, onKeydown } =
    useLibrarySearch();

/**
 * The anchor tying the results panel to the field.
 *
 * A dashed-ident, because that is what `anchor-name` takes, and bound into the scoped style with
 * `v-bind` — the same arrangement PopOver uses, and for the same reason: `v-bind` in CSS only
 * resolves inside an SFC.
 */
const anchorName = "--all-music-search";

/** One stat tile — a glyph, a label, the formatted value, and a tooltip explaining the calculation. */
type StatTile = {
    key: string;
    icon: string;
    /**
     * The value as the PIECES IT MAY BREAK BETWEEN, drawn one unbreakable span each (the owner's
     * call, 2026-08-13). Almost every tile holds exactly one — a number and its unit are one word
     * to a reader, and "96,00" with "GB" alone on the line below reads as two facts rather than
     * one. Playtime is the whole reason it is a list: its phrase is long enough to need a break and
     * the honest place for one is between its units, never inside "21 Stunden".
     */
    value: string[];
    label: string;
    hint: string;
    /**
     * Span the grid's whole row instead of one track, for a value that is a PHRASE rather than a
     * number. Only playtime is; the cell's own style rule carries the measurements.
     */
    wide?: boolean;
};

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

/** The results panel, so its popover state can be driven from the query. */
const panel = useTemplateRef<HTMLElement>("panel");

/**
 * Show the panel exactly while there is a question, and put it away when there is not.
 *
 * Guarded on the element's own `:popover-open`, because `showPopover()` on one that is already
 * showing — and `hidePopover()` on one that is not — both THROW. `flush: "post"` because the element
 * is `v-if`'d on the same flag: it does not exist yet when the watcher would otherwise run.
 */
watch(
    active,
    hasQuery => {
        const element = panel.value;
        if (!element) return;

        const showing = element.matches(":popover-open");
        if (hasQuery && !showing) element.showPopover();
        if (!hasQuery && showing) element.hidePopover();
    },
    { flush: "post" }
);
</script>

<template>
    <widget wide>
        <template #title>
            <icon name="music" />
            {{ t("music.widgets.allMusic") }}
        </template>
        <div class="widget-stats">
            <!-- The anchor the floating panel is positioned against — see the banner for why it
                 floats at all. -->
            <div class="widget-stats__field">
                <search-field
                    v-model="query"
                    :listbox-id="listboxId"
                    :active-option-id="activeOptionId"
                    :expanded="groups.length > 0"
                    :loading="loading"
                    @keydown="onKeydown"
                />
            </div>

            <div class="widget-stats__grid">
                <tooltip
                    v-for="tile in tiles"
                    :key="tile.key"
                    :text="tile.hint"
                    class="widget-stats__cell"
                    :class="{ 'widget-stats__cell--wide': tile.wide }"
                >
                    <span class="widget-stats__head">
                        <icon :name="tile.icon" :size="1" />
                        <span class="widget-stats__label">{{ tile.label }}</span>
                    </span>
                    <!-- One span per unbreakable piece, with the separating SPACE as a text node
                         outside them: that space is the only place the value may break, which is
                         what keeps "96,00 GB" and "21 Stunden" whole.

                         ALL ON ONE LINE, and the space written as an interpolation rather than as
                         markup, because both halves of that are load-bearing. A newline between the
                         pieces would put a whitespace text node INSIDE the run and Vue's `condense`
                         would drop it; a `<span> </span>` separator loses its space the same way
                         (measured — the tests caught it). An interpolation is a real expression
                         node, so nothing may collapse it. Lose the space and the value still looks
                         right at a glance while reading and copying as "2 Tage,3 Stunden". -->
                    <span class="widget-stats__value"><template v-for="(part, index) in tile.value" :key="part">{{ index > 0 ? " " : "" }}<span class="widget-stats__part">{{ part }}</span></template></span>
                </tooltip>
            </div>
        </div>

        <!-- `v-if` as well as the popover state, so an unsearched page carries no panel at all;
             the watcher above shows and hides it. -->
        <div v-if="active" ref="panel" class="widget-stats__results" popover="manual">
            <search-scope-chips v-model="scope" name="music-search-scope" class="widget-stats__chips" />
            <search-results
                :groups="groups"
                :listbox-id="listboxId"
                :active-option-id="activeOptionId"
                :loading="loading"
                :failed="failed"
                :too-short="tooShort"
            />
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

/* The anchor. It draws nothing and exists so the floating panel has something to be positioned
   against — `anchor-name` has to sit on an element the panel can name, and the field is a child
   component whose root this cannot reach. */
.widget-stats__field {
    anchor-name: v-bind(anchorName);
}

/* WRAPPING FLEX LINES, NOT A TRACK GRID, and the difference is the whole of "no whitespace".

   It was `grid-template-columns: repeat(auto-fit, minmax(7rem, 1fr))`, which decides a track COUNT
   from the width and then leaves any track the tiles do not reach standing empty — measured at
   1280px: six tracks for five number tiles, so every row ended 132px short. And no tile count fixes
   that, because the count that divides evenly changes with the width: a sixth tile squares 1280px
   and leaves 1100px with a row holding one tile and four empty tracks.

   Flex lines have no tracks to leave empty. Each tile is `flex: 1 1 7rem`, so it wraps at about the
   same width as before and then GROWS to fill whatever line it lands on — five across, or three and
   two, always flush to both edges. The cost is that tiles on different lines can differ in width,
   which for six independent facts reads as fine and never as ragged.

   `flex: 1` on the container is what hands the card's spare height to the tiles; `align-content`
   defaults to `stretch` for a wrapping flex container, so that height is shared between the lines
   rather than pooling under the last one. */
.widget-stats__grid {
    display: flex;
    flex-wrap: wrap;

    flex: 1;

    gap: map.get(s.$c-widget, "cell-gap");
}

/* Each tile is the Tooltip's root span (class merged onto it); as a grid/flex item its inline-flex
   is blockified, so we only set the direction and the alignment.

   CENTRED BOTH WAYS (the owner's call, 2026-08-13), reversing the leading alignment this carried
   until today. It takes three properties, not one, because a column flex box centres along each axis
   by a different name: `justify-content` centres the head-and-value PAIR in the tile's height,
   `align-items` centres each of those two boxes across its width, and `text-align` centres the lines
   INSIDE a box that wrapped — which is the playtime phrase on a narrow card, and the one case where
   the first two leave a ragged block sitting dead centre.

   The height half is the one doing the most work now that the tiles stretch: `align-content` on the
   grid above hands them the card's spare height, so a tile is routinely taller than the two lines it
   holds, and without this they would all hang from their top edges. */
.widget-stats__cell {
    align-items: center;
    justify-content: center;
    flex-direction: column;

    min-width: min-content;

    /* Grow to fill the line and wrap at about 7rem — but NEVER shrink past the widest thing in the
       tile that cannot break.

       `min-width: 0` was right while the values wrapped: a long one could reflow onto two lines
       instead of widening its own basis and pushing a sibling onto the next line. Making each value
       one unbreakable run turned that floor into a hole — the tile shrank under its own text, which
       then ran straight out through the padding, so the size tile read "83,27 GB" hard against both
       edges at 1600px (the owner's catch).

       `min-content` here is that unbreakable run PLUS the padding, because box-sizing is border-box
       (layout/_base.scss), so the padding is inside what the tile asks for rather than something it
       gives up first. A line that can no longer hold five tiles now wraps to four rather than
       squeezing all five past their own content. */
    flex: 1 1 7rem;

    padding: map.get(s.$c-widget, "cell-padding");
    gap: 0.15rem;

    background-color: map.get(c.$c-widget, "cell-background");
    border-radius: map.get(s.$c-widget, "cell-radius");

    text-align: center;

    /* THE WHOLE REMAINING ROW FOR A PHRASE, at the same size as every other tile (the owner: "same
       styling and all", then "make it grow so there is no whitespace").

       The measurements, at 1280px where the grid gives ~146px tracks. In ONE track the thirty-character
       value wrapped to six lines and made its tile 305px tall — "Sekunden" alone is wider than one.
       Sharing a line with two others it was two lines with dead space beside it. On a line of its own
       it is one line, full width, and nothing is empty anywhere.

       `flex-basis: 100%` rather than a span count, so it does not have to know how many tiles shared
       the line above it — and so another stat tile can be added without touching this. */
    &--wide {
        flex-basis: 100%;
    }
}

/* The glyph and the label on one line above the number, which is what the icons bought: the label
   no longer has to carry the tile alone, so it can be read at a glance and the number below it
   stays the thing that stands out. */
.widget-stats__head {
    display: flex;
    align-items: center;

    gap: 0.4ch;

    color: map.get(c.$c-widget, "footer-surface");
}

.widget-stats__value {
    color: map.get(c.$c-widget, "surface");

    font-size: 1.8rem;
    font-weight: 700;
    line-height: 1.1;
}

/* ONE PIECE THAT MAY NOT BREAK INSIDE ITSELF (the owner's call, 2026-08-13) — the `<nobr>` job,
   done with the property rather than the element: Vue does not know `nobr` as a native tag, so a
   literal one resolves as a component and warns on every render in dev.

   A number and its unit are one word to a reader. "96,00" with "GB" alone underneath reads as two
   facts, and the year range is a live case rather than a theoretical one — a dash IS a break
   opportunity, so "1965–" could sit at the end of a line with "2024" below it.

   The playtime is the only value made of several of these, and it may break BETWEEN them: those
   spaces are text nodes in the template, outside these spans, so they survive as the value's only
   break points. A trailing space inside the span would not — `nowrap` suppresses the break at a
   space it contains, which would make the whole phrase one unbreakable run and overflow the tile. */
.widget-stats__part {
    white-space: nowrap;
}

/* Bigger than it was (0.8rem), because the tiles now have the room and a label nobody can read is
   a label doing none of its work — the same correction the search pips needed. */
.widget-stats__label {
    font-size: 1rem;
}

/* THE FLOATING PANEL, positioned entirely by the anchor rather than by numbers of its own.
   `position-area: block-end span-inline-end` puts it below the field starting at the field's leading
   edge, and `width: anchor-size(width)` makes it exactly as wide as the box it answers for. Same
   family as this app's other anchored panels (styles/components/popover/_content.scss).

   `height: fit-content` WITH `max-height: stretch` IS THE PAIR THAT MATTERS, and it took three
   attempts. A fixed cap cannot know where the field sits: `min(26rem, 50dvh)` looked right on a
   900px window and ran 66px past the bottom of a 720px one — which is the height the E2E project
   runs at, so the test caught it. `stretch` resolves against the space the position-area actually
   has, so the ceiling is "as far as the window allows" wherever the field is, while `fit-content`
   keeps a two-row answer two rows tall. Measured at 1280×720: content 278px, bottom 704 against a
   720 viewport — the 16px is the block-end margin below, which `stretch` subtracts for us.

   `overflow: hidden` here with the scrolling on the LIST inside (SearchResults flexes and scrolls
   within it) is what keeps the rounded bottom corners while a long answer scrolls. */
.widget-stats__results {
    display: flex;
    position: fixed;
    z-index: z.$c-search;
    flex-direction: column;

    box-sizing: border-box;

    overflow: hidden;
    width: anchor-size(width);
    max-width: none;
    height: fit-content;
    max-height: stretch;
    padding: map.get(s.$c-search, "padding") 0;
    border: map.get(s.$c-search, "border") solid map.get(c.$c-search, "border");

    // Block-end only, and load-bearing rather than decorative: `max-height: stretch` subtracts the
    // margins, so this is what keeps the panel off the bottom edge of the window.
    margin: 0 0 map.get(s.$c-search, "padding");
    gap: map.get(s.$c-search, "gap");

    background-color: map.get(c.$c-search, "background");
    color: map.get(c.$c-search, "surface");

    border-radius: map.get(s.$c-search, "radius");

    position-anchor: v-bind(anchorName);

    // Below the field, starting at its leading edge — see the block comment above.
    position-area: block-end span-inline-end;

    /* The list inside must scroll rather than the panel growing past its cap, which needs the
       flex-child floor removed — a flex item's default `min-height: auto` is its content. Its own
       `max-height` token is switched off here for the same reason: the panel is the cap now. */
    --search-results-height: none;

    :deep(.search-results) {
        min-height: 0;
        flex: 1 1 auto;
    }

    :deep(.search-results__list) {
        min-height: 0;
        flex: 1 1 auto;
    }
}

/* The chips keep the inset the panel gives up so the rows and strips can run edge to edge. */
.widget-stats__chips {
    padding-inline: map.get(s.$c-search, "padding");
}
</style>
