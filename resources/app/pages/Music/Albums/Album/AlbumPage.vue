<script setup lang="ts">
/******************************************************************************
 * AlbumPage
 * One album's detail page, at /music/albums/{id} (route `music.albums.show`) —
 * where a row of the Albums listing leads. Nested under the listing's folder for
 * the same reason SongPage is: the detail view lives *inside* the listing it came
 * from, mirroring the URL.
 *
 * Two blocks: the HeroSection identifies the album — its art, its title, and the
 * handful of facts that describe the container rather than any one file — and below it
 * the album's TRACK LISTING in the server-driven DataTable, which is what the page is
 * for. Only the play/queue controls are still missing, and they wait for the player
 * (docs/app-rewrite.md).
 *
 * The track rows are clickable: each carries the song's detail URL as `href`, so a row
 * click / card tap goes to the song, and the title cell renders that same URL as a real
 * <Link> for the keyboard and open-in-new-tab (DataTable/README.md → Accessibility).
 * The default order is the album's own — disc, then track number.
 *
 * The ARTIST cell is the one link that leaves this row's destination: it opens the
 * performer, not the song. The DataTable expects that (its click guard stands down on an
 * anchor), and the cell is styled to look like a link on hover so a reader can tell the
 * two destinations apart before clicking — see the `#cell-artist` slot and its styles.
 *
 * The controller sends raw values (seconds, bytes, an ISO-8601 instant, plain counts)
 * and the formatting happens here with the active locale — the same split every other
 * page here uses (Utils/formatting.ts).
 *****************************************************************************/
import { Head, Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import DataTable from "Components/DataTable/DataTable.vue";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import PlayCountFacts from "Components/Music/PlayCountFacts.vue";
import SubjectMenu from "Components/Music/SubjectMenu.vue";
import FactPair from "Components/UI/Card/FactPair.vue";
import Container from "Components/UI/Container.vue";
import HeroSection from "Components/UI/HeroSection.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import type { ColumnDef, TableResponse } from "Types/dataTable";
import { formatClock, formatDateTime, formatFileSize, formatPosition } from "Utils/formatting";

/** One album as AlbumController shaped it — every value raw. */
interface AlbumDetail {
    id: string;
    name: string;
    /** Its album-artist, or null for a compilation filed under none. */
    artist: string | null;
    /**
     * That artist's own page, or null when there is no album-artist. Decided server-side
     * like a DataTable row's `href`, so the tile links the name when it is given a URL.
     */
    artistUrl: string | null;
    year: number | null;
    /**
     * Its MAIN genre — the one the genre page's album tab files it under (DominantGenre),
     * not every genre its tracks graze. Null for an album whose tracks carry none.
     */
    genre: string | null;
    /** That genre's own page, or null as above — which makes the tile a plain fact. */
    genreUrl: string | null;
    /** How many tracks are filed under it. */
    songs: number;
    /** How many discs — already floored to 1 for rips that carry no disc tag. */
    discs: number;
    /** Total playing time in seconds. */
    duration: number | null;
    /** The newest track file's mtime, ISO-8601. */
    modifiedAt: string | null;
    /** Cover art URL, or null when the album has art from neither source. */
    coverUrl: string | null;
}

/** One of the album's tracks, as AlbumController's rowMapper shaped it — every value raw. */
interface TrackRow {
    id: string;
    /** Disc number, or null for a rip whose files carry no disc tag. */
    disc: number | null;
    /** How many discs the album has — the denominator in "1/2". */
    discTotal: number;
    /** Track number within its disc, or null when untagged. */
    track: number | null;
    /** How many tracks share this row's disc — the denominator in "3/12". */
    trackTotal: number;
    name: string;
    /** The performing artist — differs per row on a compilation, which is why it is a column. */
    artist: string | null;
    /**
     * That artist's own page, or null when the file credits nobody. A different destination
     * from the row's own `href`, which is what makes the artist cell a real link rather
     * than plain text (see the `#cell-artist` slot).
     */
    artistUrl: string | null;
    /** Playing time in seconds. */
    duration: number | null;
    /** File size in bytes. */
    size: number | null;
    /** The track's OWN embedded art, or null when the file carries none. */
    coverUrl: string | null;
    /** The song's detail page — makes the row clickable and backs the title link. */
    href: string;
}

const props = defineProps<{
    /** The album being shown, as AlbumController shaped it. */
    album: AlbumDetail;
    /** Its tracks, as the server-driven table payload (rows + pagination + sort + search). */
    table: TableResponse<TrackRow>;
    /**
     * How often this record has been listened to: the reader's own listens and everybody
     * else's, as listening events over ITS tracks (App\Services\Player\PlayCounts, which
     * explains why that will not equal the sum of the songs' own figures). Its own prop
     * rather than a member of `album` because PlayCountFacts refreshes exactly this key in
     * place when a track finishes. Raw counts — a zero is something the tiles leave unsaid.
     */
    plays: { own: number; others: number };
}>();

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
// The album's own crumb is a raw label, not a key — its name is data. The trail
// matches the listing's so the parent chip is the table this row came from.
setBreadcrumbs([
    { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
    { labelKey: "music.widgets.albums", href: "/music/albums", icon: "album" },
    { label: props.album.name }
]);

/**
 * The playing time as a clock, or null when not one file carried a duration — in
 * which case the tile is skipped rather than reading "0:00" for a whole album.
 */
const playingTime = computed(() => formatClock(props.album.duration));

/** The newest file's mtime in the viewer's own locale and timezone. */
const modified = computed(() => formatDateTime(props.album.modifiedAt, locale.value));



/**
 * Column definitions for the track table. A `computed` so the (already-translated) labels
 * re-evaluate on a locale switch.
 *
 * `name` is the card heading and the artwork is the card's media (`cardMedia`), the same
 * split the Albums listing uses. The numbers — disc, track, playing time, size — are
 * right-aligned, which is what lets a reader scan a column of them.
 *
 * DISC is deliberately still shown on a single-disc album, matching the song page's "1/1"
 * (the owner's call there): a column that appears and disappears with the album is harder
 * to read across pages than one that always says which disc you are looking at.
 *
 * The two differ in the CARD view, though. The track number goes in — it is the album's
 * running order, which is the whole point of reading a track list — while the disc stays
 * out, because most albums are one disc and "CD 1" repeated down every card is noise. The
 * desktop table keeps both, where a narrow column costs nothing.
 */
const columns = computed<ColumnDef<TrackRow>[]>(() => [
    { key: "coverUrl", label: t("music.columns.cover"), width: "4rem", align: "center", cardMedia: true },
    { key: "disc", label: t("music.song.labels.disc"), sortable: true, align: "right" },
    { key: "track", label: t("music.song.labels.track"), sortable: true, visibleInCard: true, align: "right" },
    { key: "name", label: t("music.columns.title"), sortable: true, visibleInCard: true, cardPrimary: true },
    { key: "artist", label: t("music.columns.artist"), sortable: true, visibleInCard: true },
    { key: "duration", label: t("music.columns.duration"), sortable: true, visibleInCard: true, align: "right" },
    { key: "size", label: t("music.song.labels.size"), sortable: true, visibleInCard: true, align: "right" }
]);
</script>

<template>
    <Head :title="album.name" />
    <container>
        <div class="album">
            <hero-section>
                <!-- The album's own name as the alt text, not "cover of …" — a screen
                     reader already says "image". Not `decorative`: here the artwork IS
                     the subject of the page, unlike a listing row where the title sits
                     in the next cell. CoverImage draws the music glyph when there is no
                     art, and HeroSection frames that in its dashed square. -->
                <template #cover>
                    <cover-image :src="album.coverUrl" :title="album.name" size="xlarge" />
                </template>
                <template #title
                    ><h2>{{ album.name }}</h2></template
                >
                <!-- Play or enqueue the whole subject. Pinned to the far end of the
                     heading line by the hero, not by anything here. -->
                <template #menu><subject-menu subject="album" /></template>
                <!-- The facts that belong to the album as a whole. Each is skipped
                     when there is nothing to say: a compilation has no album-artist,
                     an untagged rip no year. The counts always exist.

                     The album-artist is the one tile that LEADS somewhere — to that
                     artist's page, via the URL the server decided (`artistUrl`), the same
                     way the song page links its album and its artist. -->
                <template #metadata>
                    <fact-pair
                        v-if="album.artist"
                        icon="artist"
                        :label="t('music.columns.artist')"
                        :value="album.artist"
                        :href="album.artistUrl ?? undefined"
                    />
                    <fact-pair
                        v-if="album.year !== null"
                        icon="calendar"
                        :label="t('music.columns.year')"
                        :value="String(album.year)"
                    />
                    <!-- The album's MAIN genre, leading to that genre's page — the second of
                         this hero's two tiles that go somewhere. Filed by the same rule the
                         genre page uses, so following it lands on a genre whose album tab
                         really does list this record. -->
                    <fact-pair
                        v-if="album.genre"
                        icon="genre"
                        :label="t('music.columns.genre')"
                        :value="album.genre"
                        :href="album.genreUrl ?? undefined"
                    />
                    <fact-pair icon="song" :label="t('music.columns.songs')" :value="String(album.songs)" />
                    <fact-pair icon="album" :label="t('music.columns.discs')" :value="String(album.discs)" />
                    <fact-pair
                        v-if="playingTime"
                        icon="duration"
                        :label="t('music.columns.duration')"
                        :value="playingTime"
                    />
                    <fact-pair
                        v-if="modified"
                        icon="calendar"
                        :label="t('music.columns.modifiedAt')"
                        :value="modified"
                    />
                    <!-- Last, and only when there is something to say: what has actually been
                         listened to comes after what the record IS. See PlayCountFacts. -->
                    <play-count-facts :plays="plays" subject="album" />
                </template>
            </hero-section>

            <!-- The album's tracks. `base-url` is this page, so sorting / paging /
                 searching navigate back here with the state in the URL — the same
                 server-driven contract the listings use, on a detail page. -->
            <data-table
                :columns="columns"
                :response="table"
                :base-url="`/music/albums/${album.id}`"
                :has-actions="false"
            >
                <!-- The track's OWN artwork, or the glyph when the file carries none — on
                     an album whose art varies per song that difference is the informative
                     reading. `decorative`: the title is in the next cell, so naming the art
                     again makes a screen reader read every row twice. -->
                <template #cell-coverUrl="{ row }">
                    <cover-image :src="row.coverUrl" :title="row.name" size="small" decorative />
                </template>
                <template #cell-name="{ row }">
                    <Link :href="row.href" class="album__title">{{ row.name }}</Link>
                </template>
                <!-- The one cell on the page that leads somewhere OTHER than where its row
                     leads: the row goes to the song, this goes to the performer. Which is
                     why it is styled as a visible link on hover, unlike the title above —
                     a cell that looks like its neighbours but navigates elsewhere is a
                     trap, and the underline is what distinguishes "this is a different
                     destination" from "this is the row". Plain text when the file credits
                     nobody, so there is no dead link. -->
                <template #cell-artist="{ row }">
                    <Link v-if="row.artistUrl" :href="row.artistUrl" class="album__artist">{{ row.artist }}</Link>
                    <template v-else>{{ row.artist }}</template>
                </template>
                <!-- Position over total, so a reader can place a track without holding the
                     album's length in their head — the same rendering the genre and artist
                     song tabs use. The denominator is dropped where it would lie (see
                     formatPosition), and the cell is blank for an untagged file. -->
                <template #cell-disc="{ row }">{{ formatPosition(row.disc, row.discTotal) }}</template>
                <template #cell-track="{ row }">{{ formatPosition(row.track, row.trackTotal) }}</template>
                <template #cell-duration="{ row }">{{ formatClock(row.duration) }}</template>
                <template #cell-size="{ row }">{{
                    row.size === null ? "" : formatFileSize(row.size, locale)
                }}</template>
                <template #empty>
                    <p>{{ t("components.datatable.no_results") }}</p>
                </template>
            </data-table>
        </div>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

/* Stacks the page's blocks and spaces them, taking the CardGroup's own gutter
   (s.$c-card "gap") so the rhythm down the page matches the rhythm between two
   cards — the same rule, for the same reason, as SongPage's `.song`. */
.album {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}

/* The title link deliberately does NOT look like a link — the whole row is the click
   target and already signals it. Same rule as both listings; see SongsPage for the
   reasoning, including why only focus draws an underline. */
.album__title {
    color: inherit;

    text-decoration: none;

    &:focus-visible {
        text-decoration: underline;
    }
}

/* The artist link is the deliberate exception to the rule above, because it is the one
   link on the page that does NOT share its row's destination: the row opens the song, this
   opens the performer. The title can afford to look like plain text precisely because
   clicking it and clicking its row do the same thing; here they don't, so the cell has to
   say so before it is clicked — hence the underline on hover as well as on focus.

   Still `color: inherit` rather than a link colour: on a compilation this cell is filled
   on every row, and 20 coloured names would read as the loudest thing in a table whose
   subject is the titles. No transition, so no reduced-motion guard is needed — the
   underline appears at once, which is what a pointer affordance should do. */
.album__artist {
    color: inherit;

    text-decoration: none;

    &:hover,
    &:focus-visible {
        text-decoration: underline;
    }
}
</style>
