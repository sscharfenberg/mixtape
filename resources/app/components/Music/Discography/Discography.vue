<script setup lang="ts">
/******************************************************************************
 * Discography
 * A compact list of albums — artwork, name, year, and what the record adds up to —
 * each row linking to that album's own page. It belongs to whatever is showing the
 * albums, not to one page: an artist's own records today, a genre's next, and any
 * other "here are some albums" block after that. See README.md.
 *
 * It PAGES, but on the client: the caller hands over the whole set and this slices
 * it, reusing the DataTable's own pager for the control. So a page change is a
 * slice rather than a request — instant, and with no loading state over content
 * that is already on screen.
 *
 * That is the shape it is because a server-paged list would be wrong here twice:
 *
 * 1. The data is already on the client. A tabbed page sends EVERY panel on every
 *    request precisely so switching tabs costs nothing (see useTabParam), and
 *    fetching a page of albums would give that back for a set measured in dozens.
 * 2. It can share a page with a real DataTable, and DataTableService reads
 *    `sort` / `dir` / `page` / `search` UNPREFIXED. Both the artist and genre pages
 *    render every tab at once, so a second server-paged thing would drive the songs
 *    table from the same params. Local state leaves one owner of the query string.
 *
 * The sizes it is built for: an artist has at most 26 albums (the average is under
 * two, so most never page at all), while a genre reaches 66 — enough that showing
 * them all at once was too much, nowhere near enough to be worth a round trip.
 *
 * That 66 is measured under the CURRENT rule, where an album belongs to its main
 * genre only. It is unchanged from the looser "holds at least one track" rule that
 * preceded it, and so are the next four genres down: albums in this collection are
 * near-uniformly single-genre, so the two readings only ever diverge on a real
 * compilation. Which is why the one that did — fifteen Pop songs and one each of
 * five other genres — went unnoticed until it turned up under Power Metal.
 * What it still does NOT do is sort or search; a view needing either wants the
 * DataTable instead.
 *
 * Rows are <Link>s rather than clickable divs, so they are real links: keyboard
 * reachable, middle-clickable, and open-in-new-tab — the affordances the DataTable
 * has to rebuild by hand for its clickable rows, and which come free here because
 * there is only ever one destination per row.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import { formatClock } from "Utils/formatting";
import { scrollIntoViewTop } from "Utils/scroll";
import DiscographyPagination from "./DiscographyPagination.vue";

/** One album of the discography, as ArtistController shaped it — every value raw. */
export interface DiscographyAlbum {
    id: string;
    name: string;
    /** Release year, or null for an untagged rip (those sort last, whichever way the years run). */
    year: number | null;
    /**
     * The album-artist, shown only when the caller asks for it via `showArtist`. Optional
     * because a list of ONE artist's records has no use for it — see that prop. Null for a
     * compilation filed under no album-artist, which drops the chip.
     */
    artist?: string | null;
    /** How many tracks are filed under it. */
    songs: number;
    /** Total playing time in seconds, or null when not one file carried a duration. */
    duration: number | null;
    /** Cover art URL, or null when the album has art from neither source. */
    coverUrl: string | null;
    /** The album's own page — this row's single destination. */
    href: string;
}

const props = withDefaults(
    defineProps<{
        /** The albums to list, already ordered by the server (newest first). */
        albums: DiscographyAlbum[];
        /**
         * How many albums a page holds. One of the DataTable's own page sizes (25/50/100),
         * because the pager below offers exactly those and a value outside them would leave
         * its Select showing nothing.
         */
        pageSize?: number;
        /**
         * Show each album's artist as one of its facts. Off by default, because the first
         * caller was an ARTIST's own discography, where the answer is the same on every row
         * and printing it down the list says nothing. A genre's albums are by different
         * people, and there the name is the fact that tells one record from the next.
         */
        showArtist?: boolean;
    }>(),
    { pageSize: 25, showArtist: false }
);

const { t } = useI18n();

/**
 * Which page is showing, and how big it is. Both are LOCAL state, and deliberately not in
 * the URL or on the server — see the banner: the whole set is already here, so a page
 * change is a slice, not a request.
 */
const page = ref(1);
const perPage = ref(props.pageSize);

/** The slice actually rendered. */
const visibleAlbums = computed(() => {
    const start = (page.value - 1) * perPage.value;
    return props.albums.slice(start, start + perPage.value);
});

const list = ref<HTMLElement | null>(null);

/**
 * Set while the page is being reset because the ALBUM SET changed, so the scroll below can
 * tell the two apart. A reset is not a page turn: the reader has just arrived on a new
 * artist or genre at the top of the page, and scrolling them down to the list would be the
 * opposite of helpful.
 */
let resetting = false;

/**
 * Bring the top of the list into view when the reader turns a page — the shared helper,
 * so this pager and the DataTable's behave identically wherever the two sit side by side.
 * See `scrollIntoViewTop` for why it is the list rather than the document top.
 */
watch(page, () => {
    if (resetting) {
        resetting = false;
        return;
    }
    scrollIntoViewTop(list.value);
});

/**
 * Back to page 1 when the SET changes — a different artist or genre arriving in the same
 * mounted component. Without it, following a link from a 60-album genre to a 3-album one
 * while on page 3 would render an empty list.
 */
watch(
    () => props.albums,
    () => {
        // Only arm the flag when the page is actually going to change. Assigning 1 while
        // already on page 1 fires no watcher, so the flag would stay armed and eat the
        // scroll on the reader's NEXT real page turn — which is the common path, since
        // moving between two artists usually happens from page 1.
        if (page.value === 1) return;
        resetting = true;
        page.value = 1;
    }
);

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
    <ul v-if="props.albums.length > 0" ref="list" class="discography">
        <li v-for="album in visibleAlbums" :key="album.id" class="discography__item">
            <Link :href="album.href" class="discography__link" prefetch>
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
                    <!-- Plain text, not a link to the artist: the whole tile is already an
                         <a> to the album, and an anchor inside an anchor is invalid HTML the
                         browser silently un-nests. Reaching the artist from here is a hop
                         through the album, which is the trade for a tile-sized click target
                         (the DataTable can afford both because its rows are not anchors). -->
                    <span v-if="showArtist && album.artist" class="discography__fact">{{ album.artist }}</span>
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
    <!-- Owns whether it draws at all and what a page-size change does to the reader's
         position; this file only reads the two back out and slices. -->
    <discography-pagination v-model:page="page" v-model:page-size="perPage" :total="props.albums.length" />
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

    /* Where a page turn scrolls to (see the watcher). The app header is `position: sticky`
       and publishes its live height as `--app-header-height`, so clearing it by that much
       is what stops the first row landing underneath it. */
    scroll-margin-top: calc(var(--app-header-height, 0px) + #{map.get(s.$c-discography, "gap")});

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
