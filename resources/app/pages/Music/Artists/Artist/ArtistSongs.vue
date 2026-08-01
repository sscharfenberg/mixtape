<script setup lang="ts">
/******************************************************************************
 * ArtistSongs
 * The songs tab of the artist page — every music track crediting this artist, in
 * the server-driven DataTable the listings use (sort / search / paginate all in
 * the URL). Lives beside ArtistPage.vue because it is that page's own part, not a
 * shared component (CLAUDE.md → Pages), and sits next to ArtistDiscography as the
 * other half of the same catalogue.
 *
 * It IS a DataTable where the discography tab deliberately is not, because the two
 * sets are different sizes: an artist can have hundreds of songs (406 is the
 * collection's current worst case, and 42 artists are over one page), so this one
 * needs real paging where a handful of albums does not. The consequence worth
 * knowing is that this component OWNS the page's query params — DataTableService
 * reads them unprefixed, so a second server-driven table on the page would drive
 * this one from the same `sort` / `dir` / `page` / `search`. See ArtistController.
 *
 * No ARTIST column, unlike the album page's track table: every row here is by the
 * artist whose page this is, so the column would repeat one name down the whole
 * table. The ALBUM takes that slot instead, and links to it.
 *
 * The default order is the catalogue's own — newest year first, then album, disc and
 * track — so the tab opens on the most recent record and reads as a catalogue rather
 * than a bag of songs. Only the YEAR reverses: track 1 still precedes track 2 inside
 * each album, which is what makes it readable rather than merely backwards. That is
 * also what makes the disc and track columns worth their width here — they are the
 * reason consecutive rows sit in the order they do. See ArtistController.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import DataTable from "Components/DataTable/DataTable.vue";
import CoverImage from "Components/UI/CoverImage.vue";
import type { ColumnDef, TableResponse } from "Types/dataTable";
import { formatClock, formatFileSize, formatPosition } from "Utils/formatting";

/** One of the artist's songs, as ArtistController's rowMapper shaped it — every value raw. */
export interface SongRow {
    id: string;
    name: string;
    /** Disc number, or null for a rip whose files carry no disc tag. */
    disc: number | null;
    /** How many discs the row's album has — the denominator in "1/2". Null when it has no album. */
    discTotal: number | null;
    /** Track number within its disc, or null when untagged. */
    track: number | null;
    /** How many tracks share the row's disc — the denominator in "3/12". Null as above. */
    trackTotal: number | null;
    /** The album it is filed under, or null for a track belonging to no collection. */
    album: string | null;
    /** That album's release year, or null when the album is untagged or absent. */
    year: number | null;
    /**
     * That album's own page, or null when the track belongs to no collection. A different
     * destination from the row's own `href`, which is what makes the album cell a real link.
     */
    albumUrl: string | null;
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
    table: TableResponse<SongRow>;
    /**
     * Where the table's own navigation goes — the artist page's URL. Passed in rather than
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
 * Reading order is the song, then the album context that places it — which album, from
 * when, and where inside it — then the file's own facts. That middle group is the table's
 * default sort (year, album, disc, track), so the columns a reader would use to explain
 * why the rows are grouped as they are sit together.
 *
 * DISC is out of the CARD view while TRACK is in, the same split AlbumPage makes: the
 * track number is the album's running order and worth having, while most albums are one
 * disc and "CD 1" repeated down every card is noise. The desktop table keeps both, where a
 * narrow numeric column costs nothing.
 */
const columns = computed<ColumnDef<SongRow>[]>(() => [
    { key: "coverUrl", label: t("music.columns.cover"), width: "4rem", align: "center", cardMedia: true },
    { key: "name", label: t("music.columns.title"), sortable: true, visibleInCard: true, cardPrimary: true },
    { key: "album", label: t("music.columns.album"), sortable: true, visibleInCard: true },
    { key: "year", label: t("music.columns.year"), sortable: true, align: "right" },
    { key: "disc", label: t("music.song.labels.disc"), sortable: true, align: "right" },
    { key: "track", label: t("music.song.labels.track"), sortable: true, visibleInCard: true, align: "right" },
    { key: "duration", label: t("music.columns.duration"), sortable: true, visibleInCard: true, align: "right" },
    { key: "size", label: t("music.song.labels.size"), sortable: true, visibleInCard: true, align: "right" }
]);

</script>

<template>
    <!-- `base-url` is the artist page, so sorting / paging / searching navigate back there
         with the state in the URL — the same server-driven contract the listings use. Note
         the open TAB is not in that URL: reloading a sorted songs view lands on the albums
         tab again, which is the trade for keeping tab state out of the query string. -->
    <data-table :columns="columns" :response="table" :base-url="baseUrl" :has-actions="false">
        <!-- `decorative`: the title is in the next cell, so naming the art again makes a
             screen reader read every row twice. -->
        <template #cell-coverUrl="{ row }">
            <cover-image :src="row.coverUrl" :title="row.name" size="small" decorative />
        </template>
        <template #cell-name="{ row }">
            <Link :href="row.href" class="artist-songs__title">{{ row.name }}</Link>
        </template>
        <!-- The one cell leading somewhere OTHER than where its row leads: the row opens the
             song, this opens the album. Which is why it underlines on hover as well as on
             focus — a cell that looks like its neighbours but navigates elsewhere is a trap.
             Plain text when the track belongs to no collection. -->
        <template #cell-album="{ row }">
            <Link v-if="row.albumUrl" :href="row.albumUrl" class="artist-songs__album">{{ row.album }}</Link>
            <template v-else>{{ row.album }}</template>
        </template>
        <!-- Both read as "position in its set" — "1/1", "3/12" — rather than a bare number,
             so a row says how far into the record it sits without the reader holding the
             album's length in their head. The denominator is dropped where it would lie
             (see formatPosition), and the whole cell is blank for an untagged file. -->
        <template #cell-disc="{ row }">{{ formatPosition(row.disc, row.discTotal) }}</template>
        <template #cell-track="{ row }">{{ formatPosition(row.track, row.trackTotal) }}</template>
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
.artist-songs__title {
    color: inherit;

    text-decoration: none;

    &:focus-visible {
        text-decoration: underline;
    }
}

/* The deliberate exception to the rule above, and the same one AlbumPage makes for its
   artist cell: this is the one link that does NOT share its row's destination — the row
   opens the song, this opens the album. So it has to say so BEFORE it is clicked, hence
   the underline on hover as well as on focus. Still `color: inherit`: on a prolific artist
   this cell is filled on every row, and a column of coloured album names would outshout
   the titles the table is actually about. No transition, so no reduced-motion guard is
   needed — the underline appears at once, which is what a pointer affordance should do. */
.artist-songs__album {
    color: inherit;

    text-decoration: none;

    &:hover,
    &:focus-visible {
        text-decoration: underline;
    }
}
</style>
