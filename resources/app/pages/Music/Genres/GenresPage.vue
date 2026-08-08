<script setup lang="ts">
/******************************************************************************
 * GenresPage
 * The Music → Genres sub-section (ALL genres), reached at /music/genres (route
 * `music.genres`) and linked from the GenresWidget footer. The same shape as the other
 * three listings: the server-driven DataTable, with sort / search / pagination in the
 * URL, so GenresController owns the state and this page only declares the columns and
 * hands over the `table` response. Read-only (`has-actions="false"`).
 *
 * Rows are clickable: GenresController puts the genre's detail URL on every row as `href`,
 * which DataTable visits on a row click / card tap. The name cell renders that same URL as
 * a real <Link> as well — the keyboard and open-in-new-tab path, neither of which a click
 * handler on a <tr> can offer (see DataTable/README.md → Accessibility).
 *
 * Every column but the name is an aggregate, and one of them needs reading carefully:
 * ARTISTS counts the artists whose MAIN genre this is, not everyone who ever recorded a
 * song in it (see GenresController / DominantGenre). So the column adds up to the
 * library's artists — and a genre with hundreds of songs can still show 0, meaning it is
 * nobody's main genre rather than that something is missing.
 *****************************************************************************/
import { Head, Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import DataTable from "Components/DataTable/DataTable.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import type { ColumnDef, TableResponse } from "Types/dataTable";
import { formatClock, formatFileSize, formatTimesPlayed } from "Utils/formatting";

/** One genre row as shaped by GenresController's rowMapper — every value raw. */
interface GenreRow {
    id: string;
    /** The genre's name; the card view's heading. */
    name: string;
    /** How many artists have this as their MAIN genre — each artist counted once. */
    artists: number;
    /** How many music tracks are tagged with it. */
    songs: number;
    /** Total playing time of those tracks in seconds — clocked by the slot below. */
    duration: number;
    /** Total size of those files in bytes. */
    size: number;
    /** How many times THE READER has played its songs — 0 where they never have. */
    plays: number;
    /** The genre's detail page — makes the row clickable and backs the name link. */
    href: string;
}

defineProps<{
    /** The server-driven table payload (rows + pagination + sort + search state). */
    table: TableResponse<GenreRow>;
}>();

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
    { labelKey: "music.widgets.genres", icon: "genre" }
]);

/**
 * Column definitions for the genre table. A `computed` so the (already-translated) labels
 * re-evaluate if the locale changes.
 *
 * `name` is the card-view heading; the two counts and the two totals are right-aligned as
 * numbers, which is what lets a reader scan a column of them. Every column is in the card
 * view — there are only five, and each is a number the listing exists to compare.
 */
const columns = computed<ColumnDef<GenreRow>[]>(() => [
    { key: "name", label: t("music.columns.genre"), sortable: true, visibleInCard: true, cardPrimary: true },
    { key: "artists", label: t("music.columns.artists"), sortable: true, visibleInCard: true, align: "right" },
    { key: "songs", label: t("music.columns.songs"), sortable: true, visibleInCard: true, align: "right" },
    { key: "duration", label: t("music.columns.duration"), sortable: true, visibleInCard: true, align: "right" },
    { key: "size", label: t("music.columns.size"), sortable: true, visibleInCard: true, align: "right" },
    { key: "plays", label: t("music.plays.columnLabel"), sortable: true, visibleInCard: true, align: "right" }
]);
</script>

<template>
    <Head :title="t('music.widgets.genres')" />
    <headline glow>
        <icon name="genre" :size="3" />
        {{ t("music.widgets.genres") }}
    </headline>
    <container>
        <data-table :columns="columns" :response="table" base-url="/music/genres" :has-actions="false">
            <template #cell-name="{ row }">
                <Link :href="row.href" class="genres__name">{{ row.name }}</Link>
            </template>
            <!-- Raw seconds and raw bytes from the server, formatted here against the
                 viewer's locale. Neither is nullable: the controller COALESCEs both sums,
                 so a genre whose tracks were all pruned reads "0:00" rather than blank. -->
            <template #cell-duration="{ row }">{{ formatClock(row.duration) }}</template>
            <template #cell-size="{ row }">{{ formatFileSize(row.size, locale) }}</template>
            <!-- A dash rather than "0×" for a genre the reader has never played: on a column
                 where most rows are empty until a library has been lived in, a page of zeroes
                 reads as broken data. The server sends the raw 0, which is what the sort
                 needs; drawing it as nothing is the page's decision. -->
            <template #cell-plays="{ row }">
                {{ row.plays > 0 ? formatTimesPlayed(row.plays) : "—" }}
            </template>
            <template #empty>
                <p>{{ t("components.datatable.no_results") }}</p>
            </template>
        </data-table>
    </container>
</template>

<style scoped lang="scss">
/* The name link deliberately does NOT look like a link — the whole row is the click
   target and already signals that. Identical to the other listings' rule, and for the
   same reasons (see SongsPage): `inherit` keeps the cell's themed colour, and only focus
   draws an underline, because a keyboard user gets no hover halo to read. */
.genres__name {
    color: inherit;

    text-decoration: none;

    &:focus-visible {
        text-decoration: underline;
    }
}
</style>
