<script setup lang="ts">
/******************************************************************************
 * ArtistsPage
 * The Music → Artists sub-section (ALL artists), reached at /music/artists (route
 * `music.artists`) and linked from the ArtistsWidget footer. The same shape as the
 * Songs and Albums listings: the server-driven DataTable, with sort / search /
 * pagination in the URL, so ArtistsController owns the state and this page only
 * declares the columns and hands over the `table` response. Read-only
 * (`has-actions="false"`).
 *
 * Rows are clickable: ArtistsController puts the artist's detail URL on every row as
 * `href`, which DataTable visits on a row click / card tap. The name cell renders that
 * same URL as a real <Link> as well — the keyboard and open-in-new-tab path, neither of
 * which a click handler on a <tr> can offer (see DataTable/README.md → Accessibility).
 *
 * Where it differs from the album table: there is no artwork column, because MixTape
 * stores no artist images (nothing on disk to point an <img> at), and EVERY column except
 * the name is an aggregate. The one worth knowing before reading a row is `albums`: it is
 * the artist's own discography — albums credited to them — so a session player who only
 * guests on other people's compilations shows 0 albums beside their songs. That is the
 * reading the column commits to, not missing data (see ArtistsController).
 *
 * ABOVE THE TABLE SITS ArtistsStats — four counts that are each a way INTO it (`?filter=`), two of
 * which the table cannot express: a credit that reads as several artists, and anything about when a
 * file arrived.
 *****************************************************************************/
import { Head, Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import DataTable from "Components/DataTable/DataTable.vue";
import SelectionActions from "Components/Music/SelectionActions.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import type { ColumnDef, TableResponse } from "Types/dataTable";
import type { ArtistStats } from "Types/music";
import { formatClock, formatFileSize, formatTimesPlayed } from "Utils/formatting";
import ArtistsStats from "./ArtistsStats.vue";

/** One artist row as shaped by ArtistsController's rowMapper — every value raw. */
interface ArtistRow {
    id: string;
    /** The artist's name; the card view's heading, and the cell that links. */
    name: string;
    /** Albums CREDITED to them — their discography, not the ones they guest on. */
    albums: number;
    /** How many music tracks credit them as the performer. */
    songs: number;
    /** Total playing time of those tracks in seconds — clocked by the slot below. */
    duration: number;
    /** Total size of those files in bytes. */
    size: number;
    /** How many times THE READER has played their songs — 0 where they never have. */
    plays: number;
    /** The artist's detail page — makes the row clickable and backs the name link. */
    href: string;
}

const props = defineProps<{
    /** The server-driven table payload (rows + pagination + sort + search state). */
    table: TableResponse<ArtistRow>;
    /** The strip above the table: how many rows the listing has, and one actionable count per filter. */
    stats: ArtistStats;
}>();

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
    { labelKey: "music.widgets.artists", icon: "artist" }
]);

/**
 * Column definitions for the artist table. A `computed` so the (already-translated)
 * labels re-evaluate if the locale changes.
 *
 * `name` is the card-view heading; the two counts and the two totals are right-aligned as
 * numbers, which is what lets a reader scan a column of them. Every column is in the card
 * view — there are only five, and each is a number the listing exists to compare.
 */
const columns = computed<ColumnDef<ArtistRow>[]>(() => [
    { key: "name", label: t("music.columns.artist"), sortable: true, visibleInCard: true, cardPrimary: true },
    { key: "albums", label: t("music.columns.albums"), sortable: true, visibleInCard: true, align: "right" },
    { key: "songs", label: t("music.columns.songs"), sortable: true, visibleInCard: true, align: "right" },
    { key: "duration", label: t("music.columns.duration"), sortable: true, visibleInCard: true, align: "right" },
    { key: "size", label: t("music.columns.size"), sortable: true, visibleInCard: true, align: "right" },
    { key: "plays", label: t("music.plays.columnLabel"), sortable: true, visibleInCard: true, align: "right" }
]);
</script>

<template>
    <Head :title="t('music.widgets.artists')" />
    <headline glow>
        <icon name="artist" :size="3" />
        {{ t("music.widgets.artists") }}
    </headline>
    <container>
        <artists-stats v-bind="props.stats" />

        <data-table :columns="columns" :response="table" base-url="/music/artists" :has-actions="false" selectable>
            <template #toolbar-actions>
                <selection-actions subject="artist" />
            </template>
            <template #cell-name="{ row }">
                <Link :href="row.href" class="artists__name">{{ row.name }}</Link>
            </template>
            <!-- Raw seconds and raw bytes from the server, formatted here against the
                 viewer's locale. Neither is nullable: the controller COALESCEs both sums,
                 so an artist with no files of their own reads "0:00" rather than blank —
                 which next to "3 albums" is the informative answer (a credited-only
                 compilation owner), not missing data. -->
            <template #cell-duration="{ row }">{{ formatClock(row.duration) }}</template>
            <template #cell-size="{ row }">{{ formatFileSize(row.size, locale) }}</template>
            <!-- A dash rather than "0×" for an artist the reader has never played. On a
                 column where most rows are empty until a library has been lived in, a page
                 of zeroes reads as broken data; a dash reads as "nothing yet". The server
                 sends the raw 0 — which is what the sort needs — and the decision to draw
                 it as nothing is the page's. -->
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
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

/* The strip and the table below it, spaced by the card gap — a page reads the token of the
   component that already defines it (CLAUDE.md → Design tokens), as the songs and albums pages do. */
.container > * + * {
    margin-block-start: map.get(s.$c-card, "gap");
}

/* The name link deliberately does NOT look like a link — the whole row is the click
   target and already signals that. Identical to the other listings' rule, and for the
   same reasons (see SongsPage): `inherit` keeps the cell's themed colour, and only focus
   draws an underline, because a keyboard user gets no hover halo to read. */
.artists__name {
    color: inherit;

    text-decoration: none;

    &:focus-visible {
        text-decoration: underline;
    }
}
</style>
