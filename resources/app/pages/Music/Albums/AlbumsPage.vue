<script setup lang="ts">
/******************************************************************************
 * AlbumsPage
 * The Music → Albums sub-section (ALL albums), reached at /music/albums (route
 * `music.albums`) and linked from the AlbumsWidget footer. The same shape as
 * SongsPage: the server-driven DataTable, with sort / search / pagination in the
 * URL, so AlbumsController owns the state and this page only declares the columns
 * and hands over the `table` response. Read-only (`has-actions="false"`).
 *
 * Rows are clickable: AlbumsController puts the album's detail URL on every row as
 * `href`, which DataTable visits on a row click / card tap. The album-name cell
 * renders that same URL as a real <Link> as well — the keyboard and
 * open-in-new-tab path, neither of which a click handler on a <tr> can offer (see
 * DataTable/README.md → Accessibility).
 *
 * Where it differs from the songs table: the leading column is ARTWORK, and four of
 * the columns are aggregates of the album's tracks rather than fields of its own
 * (songs, discs, playing time, and the newest file's mtime). All of them arrive raw
 * and are formatted here against the viewer's locale and timezone.
 *****************************************************************************/
import { Head, Link } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import DataTable from "Components/DataTable/DataTable.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import type { ColumnDef, TableResponse } from "Types/dataTable";
import { formatClock, formatDateTime } from "Utils/formatting";

/** One album row as shaped by AlbumsController's rowMapper — every value raw. */
interface AlbumRow {
    id: string;
    /** The album's own name; the card view's heading, and the cell that links. */
    name: string;
    /** Its album-artist, or null for a compilation filed under none. */
    artist: string | null;
    year: number | null;
    /** How many tracks are filed under it. */
    songs: number;
    /** How many discs — already floored to 1 for rips that carry no disc tag. */
    discs: number;
    /** Total playing time in seconds — clocked by the `cell-duration` slot. */
    duration: number | null;
    /** The newest track file's mtime, ISO-8601 — the album's "last changed". */
    modifiedAt: string | null;
    /** Cover art URL, or null when the album has art from neither source. */
    coverUrl: string | null;
    /** The album's detail page — makes the row clickable and backs the name link. */
    href: string;
}

defineProps<{
    /** The server-driven table payload (rows + pagination + sort + search state). */
    table: TableResponse<AlbumRow>;
}>();

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
    { labelKey: "music.widgets.albums", icon: "album" }
]);

/**
 * Albums whose thumbnail failed to load, so the row can fall back to the same
 * placeholder a coverless album gets.
 *
 * Needed because `coverUrl` is a PROMISE, not a guarantee: the server decides it from
 * `tracks.cover` (a scan-time flag) plus one directory read, so a file re-tagged or
 * deleted since the last `app:update` can still be advertised and then 404. Without
 * this, that row renders the browser's broken-image glyph with the alt text wrapped
 * beside it, which is both ugly and taller than every other row — the failure looks
 * like a layout bug rather than like missing art.
 */
const failedCovers = ref(new Set<string>());

/** Remember a row whose <img> errored, which swaps it to the placeholder glyph. */
const onCoverError = (id: string) => {
    // A new Set rather than .add(): a Set mutated in place is not a reactive change.
    failedCovers.value = new Set(failedCovers.value).add(id);
};

/**
 * Column definitions for the album table. A `computed` so the (already-translated)
 * labels re-evaluate if the locale changes.
 *
 * `name` is the card-view heading; the counts and the playing time are right-aligned
 * as numbers. The artwork column is NOT sortable (there is nothing to sort by) and is
 * `cardMedia` rather than `visibleInCard`: in the narrow layout it belongs BESIDE the
 * heading as artwork, not in the label/value list as "Cover: <img>". Both layouts
 * render it through the one `#cell-coverUrl` slot below.
 */
const columns = computed<ColumnDef<AlbumRow>[]>(() => [
    { key: "coverUrl", label: t("music.columns.cover"), width: "4rem", align: "center", cardMedia: true },
    { key: "year", label: t("music.columns.year"), sortable: true, visibleInCard: true },
    { key: "name", label: t("music.columns.album"), sortable: true, visibleInCard: true, cardPrimary: true },
    { key: "artist", label: t("music.columns.artist"), sortable: true, visibleInCard: true },
    { key: "songs", label: t("music.columns.songs"), sortable: true, visibleInCard: true, align: "right" },
    { key: "discs", label: t("music.columns.discs"), sortable: true, visibleInCard: true, align: "right" },
    { key: "modifiedAt", label: t("music.columns.modifiedAt"), sortable: true, visibleInCard: true },
    { key: "duration", label: t("music.columns.duration"), sortable: true, visibleInCard: true, align: "right" }
]);
</script>

<template>
    <Head :title="t('music.widgets.albums')" />
    <headline glow>{{ t("music.widgets.albums") }}</headline>
    <container>
        <data-table :columns="columns" :response="table" base-url="/music/albums" :has-actions="false">
            <!-- Artwork, or the same music glyph the song page's hero falls back to,
                 so a coverless album reads as "no picture" rather than as a broken
                 image — and so does an album whose advertised cover 404s (see
                 failedCovers). `loading="lazy"` because a page of 50 rows is 50
                 requests to the cover route, each of which may extract on first hit.

                 `alt=""` deliberately: the album's name sits in the very next cell of
                 the same row, so naming the artwork again only makes a screen reader
                 read every row twice. Decorative here, load-bearing on the album page
                 where the image IS the subject. -->
            <template #cell-coverUrl="{ row }">
                <img
                    v-if="row.coverUrl && !failedCovers.has(row.id)"
                    :src="row.coverUrl"
                    alt=""
                    class="albums__cover"
                    loading="lazy"
                    @error="onCoverError(row.id)"
                />
                <icon
                    v-else
                    name="music"
                    :size="2"
                    class="albums__cover-placeholder"
                    :aria-label="t('music.album.noCover')"
                    role="img"
                />
            </template>
            <template #cell-name="{ row }">
                <Link :href="row.href" class="albums__title">{{ row.name }}</Link>
            </template>
            <template #cell-modifiedAt="{ row }">{{ formatDateTime(row.modifiedAt, locale) }}</template>
            <template #cell-duration="{ row }">{{ formatClock(row.duration) }}</template>
            <template #empty>
                <p>{{ t("components.datatable.no_results") }}</p>
            </template>
        </data-table>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

/* A square thumbnail, taking its size, corners, border and colour from the same partial
   that shapes the hero's cover (s/c.$c-hero-section) — it is the same artwork, one step
   down, so the two differ only where a 48px square genuinely needs different numbers
   than a 240px one (the `-thumbnail-` rungs: a smaller radius and a hairline frame).

   `object-fit: cover` so a non-square scan crops instead of distorting; `border-box` so
   the frame doesn't push the row taller than 48px; and `display: block` kills the inline
   baseline gap that would otherwise do the same. */
.albums__cover {
    display: block;

    box-sizing: border-box;

    width: map.get(s.$c-hero-section, "cover-thumbnail");
    height: map.get(s.$c-hero-section, "cover-thumbnail");
    border: map.get(s.$c-hero-section, "cover-thumbnail-border") solid
        map.get(c.$c-hero-section, "cover-border");

    border-radius: map.get(s.$c-hero-section, "cover-thumbnail-radius");

    object-fit: cover;
}

/* The placeholder is the hero's colour, at the hero's own opacity — a muted glyph
   rather than a second dashed box: at 32px a dashed frame is all frame. */
.albums__cover-placeholder {
    color: map.get(c.$c-hero-section, "cover-placeholder-icon");
}

/* The title link deliberately does NOT look like a link — the whole row is the click
   target and already signals that. Identical to SongsPage's rule, and for the same
   reasons (see the comment there): `inherit` keeps the cell's themed colour, and only
   focus draws an underline, because a keyboard user gets no hover halo to read. */
.albums__title {
    color: inherit;

    text-decoration: none;

    &:focus-visible {
        text-decoration: underline;
    }
}
</style>
