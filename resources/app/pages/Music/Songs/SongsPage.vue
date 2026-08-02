<script setup lang="ts">
/******************************************************************************
 * SongsPage
 * The Music → Songs sub-section (ALL songs), reached at /music/songs (route
 * `music.songs`) and linked from the SongsWidget footer. Renders the full song
 * listing in the server-driven DataTable: sort / search / pagination all live in
 * the URL, so the controller (SongsController) owns the state and this page just
 * declares the columns and hands over the `table` response. Read-only for now
 * (`has-actions="false"`).
 *
 * Rows are clickable: SongsController puts the song's detail URL on every row as
 * `href`, which DataTable visits on a row click / card tap. The title cell renders
 * that same URL as a real <Link> as well — that's the keyboard and
 * open-in-new-tab path, neither of which a click handler on a <tr> can offer (see
 * DataTable/README.md → Accessibility).
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
import { formatClock } from "Utils/formatting";

/** One song row as shaped by SongsController's rowMapper (duration in raw seconds). */
interface SongRow {
    id: string;
    name: string;
    artist: string | null;
    album: string | null;
    genre: string | null;
    /** Playing time in seconds — clocked to m:ss by the `cell-duration` slot. */
    duration: number | null;
    /** The song's detail page — makes the row clickable and backs the title link. */
    href: string;
}

defineProps<{
    /** The server-driven table payload (rows + pagination + sort + search state). */
    table: TableResponse<SongRow>;
}>();

const { t } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
setBreadcrumbs([
    { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
    { labelKey: "music.widgets.songs", icon: "song" }
]);

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
    <headline glow>
        <icon name="song" :size="3" />
        {{ t("music.widgets.songs") }}
    </headline>
    <container>
        <data-table :columns="columns" :response="table" base-url="/music/songs" :has-actions="false">
            <template #cell-name="{ row }">
                <Link :href="row.href" class="songs__title">{{ row.name }}</Link>
            </template>
            <template #cell-duration="{ row }">{{ formatClock(row.duration) }}</template>
            <template #empty>
                <p>{{ t("components.datatable.no_results") }}</p>
            </template>
        </data-table>
    </container>
</template>

<style scoped lang="scss">
/* The title link deliberately does NOT look like a link: the whole row is the
   click target and already signals that (pointer cursor + hover halo), so a blue
   underlined title on every row would be noise. It stays a real <a> for the
   keyboard, the screen reader and ⌘-click. No colour token needed — `inherit`
   keeps the cell's own themed text colour.

   Nothing changes on hover: the row's halo already answers "is this clickable?",
   and a second, narrower highlight inside it only added noise. Focus is the
   exception and keeps its underline — a keyboard user gets no halo to read, so
   the link has to say where the caret is on its own. */
.songs__title {
    color: inherit;

    text-decoration: none;

    &:focus-visible {
        text-decoration: underline;
    }
}
</style>
