<script setup lang="ts">
/******************************************************************************
 * Discography
 * A compact list of albums — artwork, name, year, and what the record adds up to —
 * each row linking to that album's own page. It belongs to whatever is showing the
 * albums, not to one page: an artist's own records today, a genre's next, and any
 * other "here are some albums" block after that. See README.md.
 *
 * Deliberately NOT a DataTable, which is what every full album listing in the app
 * is. Two reasons, and the second is the load-bearing one:
 *
 * 1. There is usually nothing to page. The biggest discography in the collection
 *    is 26 albums and the average is 1.5 — a toolbar, a search box and a pager
 *    around a couple of rows is furniture around nothing.
 * 2. It can share a page with a server-driven table, and DataTableService reads
 *    `sort` / `dir` / `page` / `search` UNPREFIXED. On the artist page both tabs
 *    render at once (which tab is open is client-side state), so a second
 *    server-driven table would re-sort and re-paginate the songs table from the
 *    same params. Staying plain leaves a single owner of the query string.
 *
 * That second reason is also the limit worth knowing before reusing this: it shows
 * everything it is handed, so the CALLER must keep the set small. A page that needs
 * paging or sorting over albums wants the DataTable instead.
 *
 * Rows are <Link>s rather than clickable divs, so they are real links: keyboard
 * reachable, middle-clickable, and open-in-new-tab — the affordances the DataTable
 * has to rebuild by hand for its clickable rows, and which come free here because
 * there is only ever one destination per row.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import { formatClock } from "Utils/formatting";

/** One album of the discography, as ArtistController shaped it — every value raw. */
export interface DiscographyAlbum {
    id: string;
    name: string;
    /** Release year, or null for an untagged rip (those sort last). */
    year: number | null;
    /** How many tracks are filed under it. */
    songs: number;
    /** Total playing time in seconds, or null when not one file carried a duration. */
    duration: number | null;
    /** Cover art URL, or null when the album has art from neither source. */
    coverUrl: string | null;
    /** The album's own page — this row's single destination. */
    href: string;
}

const props = defineProps<{
    /** The albums to list, already ordered by the server (oldest first). */
    albums: DiscographyAlbum[];
}>();

const { t } = useI18n();

/**
 * How many songs an album holds, already pluralised — e.g. "4 Songs".
 *
 * Its own chip rather than part of a joined sentence: each fact is a separate pill, so the
 * separator that used to hold "4 Songs · 26:23" together is the chip boundary now.
 */
const songCount = (album: DiscographyAlbum): string => t("music.discography.songCount", album.songs);
</script>

<template>
    <!-- A list, semantically: a screen reader gets "list, 4 items" before the rows, which
         is the one thing the DataTable's <table> would have said better and everything
         else here says worse. -->
    <ul v-if="props.albums.length > 0" class="discography">
        <li v-for="album in props.albums" :key="album.id" class="discography__item">
            <Link :href="album.href" class="discography__link">
                <!-- The artwork twice, one per layout, with the OTHER one display:none at any
                     given width. Not a duplicate render for its own sake: a size in CoverImage
                     carries its radius and frame width with it, so the two layouts genuinely
                     need different instances — and reaching in to resize one from here is the
                     trap that component's README documents. A hidden lazy <img> is never
                     fetched, so the cost is a DOM node, which is the same trade DataTable
                     makes rendering its table and its cards together.

                     `decorative`: the album's name is the next thing inside the same link, so
                     naming the artwork too would have a screen reader read every row twice. -->
                <span class="discography__art discography__art--row">
                    <cover-image :src="album.coverUrl" :title="album.name" size="small" decorative />
                </span>
                <span class="discography__art discography__art--card">
                    <cover-image :src="album.coverUrl" :title="album.name" size="xlarge" decorative />
                </span>
                <span class="discography__name">{{ album.name }}</span>
                <!-- One chip per fact. Each is dropped rather than shown empty when the tags
                     don't carry it: an untagged rip has no year, and an album whose files
                     all lack a duration would otherwise claim "0:00". `formatClock` is
                     null-in/null-out, so the guard on `duration` is enough for both. -->
                <span class="discography__meta">
                    <span v-if="album.year !== null" class="discography__fact">{{ album.year }}</span>
                    <span class="discography__fact">{{ songCount(album) }}</span>
                    <span v-if="album.duration !== null" class="discography__fact">{{
                        formatClock(album.duration)
                    }}</span>
                </span>
            </Link>
        </li>
    </ul>
    <p v-else>{{ t("music.discography.empty") }}</p>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* ROWS on a phone — one tile per line, stacked. No rules between them: each tile carries
   its own fill and edge, so a divider would be a third line between two things that are
   already separated. */
.discography {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: map.get(s.$c-discography, "gap");

    list-style: none;

    /* CARDS from `landscape` up — artwork over its facts, in as many columns as fit.
       `min()` keeps a column from overflowing a narrow container, which matters because
       this sits inside a tabbed panel rather than the full page width. */
    @include m.mq("landscape") {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(#{map.get(s.$c-discography, "card-min")}, 100%), 1fr));
    }
}

/* The whole tile is the link, so the target is the tile and not just the title — the same
   reach the DataTable's clickable rows give, without the click-guard machinery. */
.discography__link {
    display: flex;
    align-items: center;

    box-sizing: border-box;

    height: 100%;
    padding: map.get(s.$c-discography, "item-padding");
    border: map.get(s.$c-discography, "border") solid map.get(c.$c-discography, "border");
    gap: map.get(s.$c-discography, "row-gap");

    background-color: map.get(c.$c-discography, "background-odd");
    color: inherit;

    /* The panel's own rounding, not a tile-sized one — see the size token. */
    border-radius: map.get(s.$c-discography, "radius");

    text-decoration: none;

    @media (prefers-reduced-motion: no-preference) {
        transition:
            background-color ti.$c-discography ease-out,
            box-shadow ti.$c-discography ease-out;
    }

    /* The DataTable's clickable-row treatment: its two-layer control-neon halo, over a
       wash that only SHIFTS this tile's existing fill. `position: relative` so the glow
       paints above the neighbouring tiles rather than under them. */
    &:hover {
        position: relative;

        background-color: map.get(c.$c-discography, "hover-background");
        box-shadow:
            0 0 0.6em 0.1em map.get(c.$c-discography, "glow"),
            0 0 1.5em 0.25em map.get(c.$c-discography, "glow");
    }

    /* The tile is already the target, so it needs no underline — but it does need a visible
       keyboard focus ring, since nothing else here says which tile is focused. */
    &:focus-visible {
        outline: 2px solid currentcolor;
        outline-offset: -2px;
    }

    /* Artwork on top, facts under it, and the tile fills its grid cell so a row of cards
       shares one height however long the titles run. */
    @include m.mq("landscape") {
        align-items: stretch;
        flex-direction: column;

        gap: map.get(s.$c-discography, "card-gap");
    }
}

/* Alternating fills, so the eye can track ACROSS a wide row. Rows only: see the colour
   partial for why a grid takes one fill instead. */
.discography__item:nth-child(even) .discography__link {
    background-color: map.get(c.$c-discography, "background-even");

    @include m.mq("landscape") {
        background-color: map.get(c.$c-discography, "background-odd");
    }
}

/* One artwork per layout, the other hidden — see the template. `display: flex` on the
   shown one kills the inline baseline gap under an <img>. */
.discography__art--row {
    display: flex;
}

.discography__art--card {
    display: none;
}

@include m.mq("landscape") {
    .discography__art--row {
        display: none;
    }

    .discography__art--card {
        display: flex;
    }
}

/* In a ROW this takes the slack, so the meta block sits hard against the trailing edge and
   the years line up down the list as a column. In a CARD it is simply the line under the
   artwork, and `flex: 1` makes it absorb the height difference between a one-line and a
   two-line title so every card's facts sit on the same baseline. */
.discography__name {
    min-width: 0;
    flex: 1 1 auto;
}

.discography__meta {
    display: flex;
    align-items: center;
    justify-content: flex-end;

    flex-wrap: wrap;

    gap: map.get(s.$c-discography, "meta-gap");

    /* Under the title rather than pinned to the trailing edge, and no longer wrapping to
       the far side of a card that is only a few words wide. */
    @include m.mq("landscape") {
        justify-content: flex-start;
    }
}

/* Each fact as its own chip — the same object the hero's FactPair tiles are, at the size a
   secondary fact deserves. The ink stays one step down from the album name, so the tile
   still has exactly one thing to read first. `tabular-nums` here rather than on the row, so
   the years and clocks line up down a column of cards without also monospacing the
   pluralised song count beside them. */
.discography__fact {
    padding: map.get(s.$c-discography, "meta-padding");

    background-color: map.get(c.$c-discography, "meta-background");
    color: map.get(c.$c-discography, "surface-meta");

    border-radius: map.get(s.$c-discography, "meta-radius");

    font-variant-numeric: tabular-nums;
}
</style>
