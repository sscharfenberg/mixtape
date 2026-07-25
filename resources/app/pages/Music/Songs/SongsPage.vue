<script setup lang="ts">
/******************************************************************************
 * SongsPage
 * The Music → Songs sub-section (ALL songs), reached at /music/songs (route
 * `music.songs`) and linked from the SongsWidget footer. Renders the full song
 * listing in the server-driven DataTable: sort / search / pagination all live in
 * the URL, so the controller (SongsController) owns the state and this page just
 * declares the columns and hands over the `table` response. Read-only for now
 * (`has-actions="false"`); a per-song detail page + clickable rows come later.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import DataTable from "Components/DataTable/DataTable.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import type { ColumnDef, TableResponse } from "Types/dataTable";

/** One song row as shaped by SongsController's rowMapper (duration pre-formatted to m:ss). */
interface SongRow {
    id: string;
    name: string;
    artist: string | null;
    album: string | null;
    genre: string | null;
    duration: string | null;
}

defineProps<{
    /** The server-driven table payload (rows + pagination + sort + search state). */
    table: TableResponse<SongRow>;
}>();

const { t } = useI18n();

/**
 * Column definitions for the song table. A `computed` so the (already-translated)
 * labels re-evaluate if the locale changes. `name` is the card-view heading;
 * duration is right-aligned as a numeric value.
 */
const columns = computed<ColumnDef<SongRow>[]>(() => [
    { key: "name", label: t("music.columns.title"), sortable: true, visibleInCard: true, cardPrimary: true },
    { key: "artist", label: t("music.columns.artist"), sortable: true, visibleInCard: true },
    { key: "album", label: t("music.columns.album"), sortable: true, visibleInCard: true },
    { key: "genre", label: t("music.columns.genre"), sortable: true, visibleInCard: true },
    { key: "duration", label: t("music.columns.duration"), sortable: true, align: "right" }
]);
</script>

<template>
    <Head :title="t('music.widgets.songs')" />
    <headline glow>{{ t("music.widgets.songs") }}</headline>
    <container>
        <data-table :columns="columns" :response="table" base-url="/music/songs" :has-actions="false">
            <template #empty>
                <p>{{ t("components.datatable.no_results") }}</p>
            </template>
        </data-table>
    </container>
</template>
