<script setup lang="ts">
/******************************************************************************
 * GenrePage
 * One genre's detail page, at /music/genres/{id} (route `music.genres.show`) — where a
 * row of the Genres listing leads, and where the genre tile on an artist's page goes.
 * Nested under the listing's folder like the other three detail pages: the detail view
 * lives *inside* the listing it came from, mirroring the URL.
 *
 * TWO blocks, the same shape the artist page has: the hero — the genre's name and the four
 * numbers its listing row shows — and below it the genre's contents across an ALBUMS, an
 * ARTISTS and a SONGS tab.
 *
 * The three panels are shaped by their sizes, and only SONGS is a server-driven DataTable:
 * DataTableService reads its params unprefixed and every tab renders at once, so a second
 * table would drive the first from the same query string. Albums are the shared Discography
 * list; artists are cards of their own (GenreArtists), each fanning a few of that artist's
 * covers over the numbers describing them within this genre.
 *
 * Which tab is open lives in `?tab=`, so a reload or a shared link reopens it — but it is
 * only ever CLIENT state written into the URL: the controller sends all three panels
 * whatever the param says, so switching tabs costs no request (see useTabParam).
 *
 * Worth knowing while reading the tabs: ARTISTS and ALBUMS both mean "whose MAIN genre this
 * is" — not everyone who recorded a song in it, and not every record holding one. SONGS keeps
 * the literal reading, since that is a question about tracks. GenreController explains why.
 *
 * No `#cover` slot, and deliberately so: a genre is a name other rows point at, with no
 * artwork of its own anywhere on disk. HeroSection draws its dashed placeholder only when
 * the slot EXISTS and holds a non-image — "no artwork on file" — while leaving the slot
 * out says "this kind of page has no artwork", which is the true statement here.
 *
 * The controller sends raw values (seconds, bytes, plain counts); the formatting happens
 * here against the active locale (Utils/formatting.ts).
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Discography, { type DiscographyAlbum } from "Components/Music/Discography/Discography.vue";
import SubjectMenu from "Components/Music/SubjectMenu.vue";
import FactPair from "Components/UI/Card/FactPair.vue";
import Container from "Components/UI/Container.vue";
import HeroSection from "Components/UI/HeroSection.vue";
import TabbedNavigation, { type TabDefinition } from "Components/UI/TabbedNavigation/TabbedNavigation.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { useTabParam } from "Composables/useTabParam";
import type { TableResponse } from "Types/dataTable";
import { formatClock, formatFileSize } from "Utils/formatting";
import GenreArtists, { type GenreArtist } from "./GenreArtists.vue";
import GenreSongs, { type GenreSongRow } from "./GenreSongs.vue";

/** One genre as GenreController shaped it — every value raw. */
interface GenreDetail {
    id: string;
    name: string;
    /**
     * How many artists have this as their MAIN genre — the genre most of their songs
     * carry. Not everyone who ever recorded a song in it, which is why a genre full of
     * songs can still report a small number here (see DominantGenre).
     */
    artists: number;
    /**
     * How many albums have this as their MAIN genre — the same rows the albums tab lists,
     * so the pip and the tab can never disagree.
     */
    albums: number;
    /** How many music tracks are tagged with it. */
    songs: number;
    /** Total playing time of those tracks in seconds — 0, never null (the server COALESCEs). */
    duration: number;
    /** Total size of those files in bytes — 0, never null, for the same reason. */
    size: number;
}

const props = defineProps<{
    /** The genre being shown, as GenreController shaped it. */
    genre: GenreDetail;
    /** Every album whose MAIN genre this is, for the albums tab. Carries its album-artist. */
    discography: DiscographyAlbum[];
    /** The artists whose MAIN genre this is, busiest first — the same rows the hero counted. */
    artists: GenreArtist[];
    /** Its songs, as the server-driven table payload (rows + pagination + sort + search). */
    table: TableResponse<GenreSongRow>;
}>();

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
// The genre's own crumb is a raw label, not a key — its name is data. The trail matches
// the listing's so the parent chip is the table this row came from.
setBreadcrumbs([
    { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
    { labelKey: "music.widgets.genres", href: "/music/genres", icon: "genre" },
    { label: props.genre.name }
]);

/**
 * The genre's total playing time as a clock. Never null — the server COALESCEs the sum —
 * so the tile always renders, and the `?? ""` can never fire: it stands in for the album
 * page's `v-if`, which here would be a condition that is never false.
 */
const playingTime = computed(() => formatClock(props.genre.duration) ?? "");

/** What its files add up to on disk, in the viewer's locale. Never null, as above. */
const totalSize = computed(() => formatFileSize(props.genre.size, locale.value));

/**
 * The open tab, mirrored into `?tab=` so a reload or a shared link reopens it. Starts unset
 * when the URL names none, which TabbedNavigation resolves to the first tab (albums).
 * Costs no request to change — see useTabParam.
 */
const { tab: openTab } = useTabParam();

/**
 * The three tabs, with the counts on them so a reader can see how much is behind each
 * before opening it. A `computed` so the labels re-evaluate on a locale switch.
 *
 * Albums leads for the reason it does on the artist page: it is the most structural view of
 * the same material, the shape of the genre before its contents. The counts come from
 * different places on purpose — `discography.length` and `artists.length` are the panels
 * themselves, while songs is the hero's number, since that panel is paginated and its rows
 * are only ever one page of the whole.
 */
const tabs = computed<TabDefinition[]>(() => [
    { id: "albums", label: t("music.columns.albums"), icon: "album", count: props.genre.albums },
    { id: "artists", label: t("music.columns.artists"), icon: "artist", count: props.artists.length },
    { id: "songs", label: t("music.columns.songs"), icon: "song", count: props.genre.songs }
]);
</script>

<template>
    <Head :title="genre.name" />
    <container>
        <div class="genre">
            <hero-section>
                <!-- The page's heading lives here rather than in a <Headline>, as on the other
                     detail pages: the hero sets the type, the level is ours. -->
                <template #title
                    ><h2>{{ genre.name }}</h2></template
                >
                <!-- Play or enqueue the whole subject. Pinned to the far end of the
                     heading line by the hero, not by anything here. -->
                <template #menu><subject-menu subject="genre" /></template>
                <!-- The facts about the genre as a whole. None can be absent — the server
                     sends 0 rather than null for all four — so every tile always renders,
                     and 0 is an answer here rather than missing data. -->
                <template #metadata>
                    <fact-pair icon="artist" :label="t('music.columns.artists')" :value="String(genre.artists)" />
                    <fact-pair icon="album" :label="t('music.columns.albums')" :value="String(genre.albums)" />
                    <fact-pair icon="song" :label="t('music.columns.songs')" :value="String(genre.songs)" />
                    <fact-pair icon="duration" :label="t('music.columns.duration')" :value="playingTime" />
                    <fact-pair icon="file" :label="t('music.columns.size')" :value="totalSize" />
                </template>
            </hero-section>

            <!-- The genre's contents. One tab per way of reading it; the component owns
                 which panel shows and all of the ARIA, so this only supplies the slots. -->
            <tabbed-navigation
                v-model:selected-tab="openTab"
                name="genre"
                :tabs="tabs"
                :label="t('music.genre.tabs.label')"
            >
                <!-- `show-artist`: unlike an artist's own discography, these records are by
                     different people, so the name is the fact that tells one from the next. -->
                <template #albums>
                    <discography :albums="discography" show-artist />
                </template>

                <!-- One card per artist, each fanning a few of their covers. The component
                     owns the whole card, including how a fan of one, two or three sleeves is
                     laid out — which is not a detail this page should know, since half of all
                     artists here have a single album. -->
                <template #artists>
                    <genre-artists :artists="artists" />
                </template>

                <!-- `base-url` is this page, so sorting / paging / searching navigate back
                     here with the state in the URL — the same server-driven contract the
                     listings use. -->
                <template #songs>
                    <genre-songs :table="table" :base-url="`/music/genres/${genre.id}`" />
                </template>
            </tabbed-navigation>
        </div>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

/* Stacks the page's blocks and spaces them, taking the CardGroup's own gutter
   (s.$c-card "gap") so the rhythm down the page matches the rhythm between two cards —
   the same rule, for the same reason, as the other three detail pages. Each tab's own
   styling lives with the component that owns it. */
.genre {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}

</style>
