<script setup lang="ts">
/******************************************************************************
 * ArtistDiscography
 * The albums tab of the artist page — one row per album they are credited with,
 * each linking to that album's own page. Lives beside ArtistPage.vue because it
 * is that page's own part, not a shared component (CLAUDE.md → Pages).
 *
 * Deliberately NOT a DataTable, which is what every other album listing in the
 * app is. Two reasons, and the second is the load-bearing one:
 *
 * 1. There is nothing to page. The biggest discography in the collection is 26
 *    albums and the average is 1.5 — a toolbar, a search box and a pager around
 *    a couple of rows is furniture around nothing.
 * 2. The songs tab beside this one IS a DataTable, and DataTableService reads
 *    unprefixed `sort` / `dir` / `page` / `search`. Both tabs render at once
 *    (which tab is open is client-side state), so a second server-driven table
 *    here would re-sort and re-paginate the songs table from the same params.
 *    Keeping this one plain leaves a single owner of the query string.
 *
 * Rows are <Link>s rather than clickable divs, so they are real links: keyboard
 * reachable, middle-clickable, and open-in-new-tab — the affordances the
 * DataTable has to rebuild by hand for its clickable rows, and which come free
 * here because there is only ever one destination per row.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import Icon from "Components/UI/Icon.vue";
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
 * Albums whose thumbnail failed to load, so the row falls back to the placeholder glyph.
 * The same guard the listings carry, for the same reason: `coverUrl` rests on scan-time
 * state, so a file re-tagged or deleted since the last `app:update` is still advertised
 * and then 404s.
 */
const failedCovers = ref(new Set<string>());

/** Remember an album whose <img> errored, which swaps it to the placeholder glyph. */
const onCoverError = (id: string) => {
    // A new Set rather than .add(): a Set mutated in place is not a reactive change.
    failedCovers.value = new Set(failedCovers.value).add(id);
};

/**
 * One album's secondary line: how many songs, and how long it plays.
 *
 * Built as one string so the row has exactly two things to read — name, then facts — and
 * drops the playing time entirely when no file carried a duration, rather than claiming
 * "0:00" for a whole album.
 */
const albumMeta = (album: DiscographyAlbum): string => {
    const songs = t("music.artist.discography.songCount", album.songs);
    const clock = formatClock(album.duration);
    return clock === null ? songs : `${songs} · ${clock}`;
};
</script>

<template>
    <!-- A list, semantically: a screen reader gets "list, 4 items" before the rows, which
         is the one thing the DataTable's <table> would have said better and everything
         else here says worse. -->
    <ul v-if="props.albums.length > 0" class="discography">
        <li v-for="album in props.albums" :key="album.id" class="discography__item">
            <Link :href="album.href" class="discography__link">
                <!-- alt="": the album's name is the next thing in the same link, so naming
                     the artwork too would have a screen reader read every row twice. -->
                <img
                    v-if="album.coverUrl && !failedCovers.has(album.id)"
                    :src="album.coverUrl"
                    alt=""
                    class="discography__cover"
                    loading="lazy"
                    @error="onCoverError(album.id)"
                />
                <icon
                    v-else
                    name="music"
                    :size="2"
                    class="discography__cover-placeholder"
                    :aria-label="t('music.album.noCover')"
                    role="img"
                />
                <span class="discography__name">{{ album.name }}</span>
                <span class="discography__meta">
                    <span v-if="album.year !== null">{{ album.year }}</span>
                    <span>{{ albumMeta(album) }}</span>
                </span>
            </Link>
        </li>
    </ul>
    <p v-else>{{ t("music.artist.discography.empty") }}</p>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

.discography {
    padding: 0;
    margin: 0;

    list-style: none;
}

/* A rule between rows rather than around each: the list reads as one block, and the last
   row does not need a floor under it. */
.discography__item + .discography__item {
    border-top: map.get(s.$c-artist-discography, "border") solid map.get(c.$c-artist-discography, "border");
}

/* The whole row is the link, so the target is the row and not just the title — the same
   reach the DataTable's clickable rows give, without the click-guard machinery. */
.discography__link {
    display: flex;
    align-items: center;

    padding: map.get(s.$c-artist-discography, "row-padding");
    gap: map.get(s.$c-artist-discography, "row-gap");

    color: inherit;

    border-radius: map.get(s.$c-artist-discography, "radius");

    text-decoration: none;

    @media (prefers-reduced-motion: no-preference) {
        transition: background-color ti.$c-artist-discography linear;
    }

    &:hover {
        background-color: map.get(c.$c-artist-discography, "row-hover");
    }

    /* The row is already the target, so it needs no underline — but it does need a visible
       keyboard focus ring, since nothing else here says which row is focused. */
    &:focus-visible {
        outline: 2px solid currentcolor;
        outline-offset: -2px;
    }
}

/* Identical rules to the listings' row thumbnails, reading the same hero-section tokens —
   it is the same artwork at the same size, so the tabs have no business looking different.
   `border-box` and `display: block` are both load-bearing (see AlbumsPage: the frame and
   the inline baseline gap would each make the row taller). */
.discography__cover {
    display: block;

    box-sizing: border-box;

    width: map.get(s.$c-hero-section, "cover-thumbnail");
    height: map.get(s.$c-hero-section, "cover-thumbnail");
    border: map.get(s.$c-hero-section, "cover-thumbnail-border") solid map.get(c.$c-hero-section, "cover-border");

    border-radius: map.get(s.$c-hero-section, "cover-thumbnail-radius");

    object-fit: cover;
}

.discography__cover-placeholder {
    color: map.get(c.$c-hero-section, "cover-placeholder-icon");
}

/* Takes the slack so the meta block sits hard against the trailing edge, which is what
   lets the years line up down the list and be scanned as a column. */
.discography__name {
    min-width: 0;
    flex: 1 1 auto;
}

.discography__meta {
    display: flex;
    justify-content: flex-end;

    flex-wrap: wrap;

    gap: map.get(s.$c-artist-discography, "meta-gap");

    color: map.get(c.$c-artist-discography, "surface-meta");

    font-variant-numeric: tabular-nums;
}
</style>
