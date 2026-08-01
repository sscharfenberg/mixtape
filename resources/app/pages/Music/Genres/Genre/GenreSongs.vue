<script setup lang="ts">
/******************************************************************************
 * GenreSongs
 * The songs tab of the genre page — every music track tagged with this genre, in
 * the server-driven DataTable the listings use (sort / search / paginate all in
 * the URL). Lives beside GenrePage.vue because it is that page's own part, not a
 * shared component (CLAUDE.md → Pages).
 *
 * It is the ONE server-driven table on the page, and that is a constraint rather
 * than a coincidence: DataTableService reads `sort` / `dir` / `page` / `search`
 * UNPREFIXED and every tab renders at once, so a second one would drive this from
 * the same params. The albums tab is a plain Discography list for that reason.
 *
 * No GENRE column, for the same reason ArtistSongs has no artist one: every row
 * carries the genre whose page this is. ARTIST and ALBUM take that space, and both
 * link out — on a genre page those are the two facts that tell one row from the
 * next, and they are the row's only cells that lead somewhere other than the song.
 *
 * Alphabetical by default, unlike the artist page's chronological order: a genre
 * is not a career, so its songs share no timeline worth walking. See
 * GenreController.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import DataTable from "Components/DataTable/DataTable.vue";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import type { ColumnDef, TableResponse } from "Types/dataTable";
import { formatClock, formatFileSize } from "Utils/formatting";

/** One of the genre's songs, as GenreController's rowMapper shaped it — every value raw. */
export interface GenreSongRow {
    id: string;
    name: string;
    /** The performer, or null when the file credits nobody. */
    artist: string | null;
    /** That artist's own page, or null as above — which makes the cell plain text. */
    artistUrl: string | null;
    /** The album it is filed under, or null for a track belonging to no collection. */
    album: string | null;
    /** That album's own page, or null as above. */
    albumUrl: string | null;
    /** The album's release year, or null when untagged or absent. */
    year: number | null;
    /** Playing time in seconds. */
    duration: number | null;
    /** File size in bytes. */
    size: number | null;
    /** The track's OWN embedded art, or null when the file carries none. */
    coverUrl: string | null;
    /** The song's detail page — makes the row clickable and backs the title link. */
    href: string;
}

defineProps<{
    /** The songs, as the server-driven table payload (rows + pagination + sort + search). */
    table: TableResponse<GenreSongRow>;
    /**
     * Where the table's own navigation goes — the genre page's URL. Passed in rather than
     * built here, so this component knows nothing about routes and the page keeps ownership
     * of its own address.
     */
    baseUrl: string;
}>();

const { t, locale } = useI18n();

/**
 * Column definitions for the table. A `computed` so the (already-translated) labels
 * re-evaluate on a locale switch.
 *
 * Reading order is the song, then who made it and where it sits, then the file's own
 * facts — the same shape ArtistSongs uses, with ARTIST taking the slot that table gives to
 * disc and track.
 */
const columns = computed<ColumnDef<GenreSongRow>[]>(() => [
    { key: "coverUrl", label: t("music.columns.cover"), width: "4rem", align: "center", cardMedia: true },
    { key: "name", label: t("music.columns.title"), sortable: true, visibleInCard: true, cardPrimary: true },
    { key: "artist", label: t("music.columns.artist"), sortable: true, visibleInCard: true },
    { key: "album", label: t("music.columns.album"), sortable: true, visibleInCard: true },
    { key: "year", label: t("music.columns.year"), sortable: true, align: "right" },
    { key: "duration", label: t("music.columns.duration"), sortable: true, visibleInCard: true, align: "right" },
    { key: "size", label: t("music.song.labels.size"), sortable: true, visibleInCard: true, align: "right" }
]);
</script>

<template>
    <data-table :columns="columns" :response="table" :base-url="baseUrl" :has-actions="false">
        <!-- `decorative`: the title is in the next cell, so naming the art again makes a
             screen reader read every row twice. -->
        <template #cell-coverUrl="{ row }">
            <cover-image :src="row.coverUrl" :title="row.name" size="small" decorative />
        </template>
        <template #cell-name="{ row }">
            <Link :href="row.href" class="genre-songs__title">{{ row.name }}</Link>
        </template>
        <!-- The two cells leading somewhere OTHER than where their row leads: the row opens
             the song, these open its performer and its album. Which is why they underline on
             hover as well as on focus — a cell that looks like its neighbours but navigates
             elsewhere is a trap. Plain text when the file names neither. -->
        <template #cell-artist="{ row }">
            <Link v-if="row.artistUrl" :href="row.artistUrl" class="genre-songs__link">{{ row.artist }}</Link>
            <template v-else>{{ row.artist }}</template>
        </template>
        <template #cell-album="{ row }">
            <Link v-if="row.albumUrl" :href="row.albumUrl" class="genre-songs__link">{{ row.album }}</Link>
            <template v-else>{{ row.album }}</template>
        </template>
        <template #cell-duration="{ row }">{{ formatClock(row.duration) }}</template>
        <template #cell-size="{ row }">{{ row.size === null ? "" : formatFileSize(row.size, locale) }}</template>
        <template #empty>
            <p>{{ t("components.datatable.no_results") }}</p>
        </template>
    </data-table>
</template>

<style scoped lang="scss">
/* The title link deliberately does NOT look like a link — the whole row is the click
   target and already signals it. Same rule as every listing; see SongsPage for the
   reasoning, including why only focus draws an underline. */
.genre-songs__title {
    color: inherit;

    text-decoration: none;

    &:focus-visible {
        text-decoration: underline;
    }
}

/* The deliberate exception, and the same one AlbumPage and ArtistSongs make: these are the
   cells that do NOT share their row's destination, so they have to say so BEFORE being
   clicked — hence the underline on hover as well as on focus. Still `color: inherit`: on a
   genre page both columns are filled on nearly every row, and two columns of coloured names
   would outshout the titles the table is actually about. No transition, so no
   reduced-motion guard is needed — the underline appears at once, which is what a pointer
   affordance should do. */
.genre-songs__link {
    color: inherit;

    text-decoration: none;

    &:hover,
    &:focus-visible {
        text-decoration: underline;
    }
}
</style>
