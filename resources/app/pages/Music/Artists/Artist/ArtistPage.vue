<script setup lang="ts">
/******************************************************************************
 * ArtistPage
 * One artist's detail page, at /music/artists/{id} (route `music.artists.show`) —
 * where a row of the Artists listing leads, and where the artist tile on a song or
 * album page goes. Nested under the listing's folder for the same reason SongPage and
 * AlbumPage are: the detail view lives *inside* the listing it came from, mirroring
 * the URL.
 *
 * TWO blocks: the hero, holding the artist's name and the facts that describe their
 * catalogue — albums, songs, and what those files add up to in time and on disk — and
 * below it their catalogue itself, split across an ALBUMS and a SONGS tab.
 *
 * Each tab is its own component — the shared Discography and the page-local ArtistSongs — so this file
 * stays the page: the hero, the two tabs, and nothing about how either panel renders. The
 * two are shaped differently because the two sets are different sizes, and the reasoning
 * lives in ArtistController (the short version: 26 albums at the very worst against 406
 * songs). Albums are a plain list, songs the server-driven DataTable — which also means
 * only one thing on this page owns the URL's query params.
 *
 * Which tab is open lives in `?tab=`, so a reload or a shared link reopens it — but it is
 * only ever CLIENT state written into the URL: the controller sends both panels whatever
 * the param says, so switching tabs costs no request and raises no loading state over
 * content that is already on screen. See useTabParam.
 *
 * THE HERO'S COVER IS A FAN OF THEIR OWN SLEEVES, not a photograph. MixTape stores no artist
 * images, so for a long time the slot was left out entirely — deliberately, since HeroSection
 * draws its dashed placeholder only when the slot EXISTS and holds a non-image ("no artwork on
 * file"), while leaving it out says "this kind of page has no artwork". Both statements were
 * true and the result was a hero with nothing on its trailing edge.
 *
 * Three of their records, fanned, says more than either — it is what an artist looks like in a
 * collection — and it is the same CoverSleeves the playlist hero and the genre page's artist
 * cards use. `unframedCover` because a fan brings its own size; the covers arrive already
 * picked and shuffled (ArtistController → FannedCovers), and nothing here re-orders them.
 *
 * The controller sends raw values (seconds, bytes, plain counts) and the formatting
 * happens here against the active locale — the same split every other page here uses
 * (Utils/formatting.ts).
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import CoverSleeves from "Components/Music/CoverSleeves.vue";
import Discography, { type DiscographyAlbum } from "Components/Music/Discography/Discography.vue";
import PlayCountFacts from "Components/Music/PlayCountFacts.vue";
import SubjectMenu from "Components/Music/SubjectMenu.vue";
import FactPair from "Components/UI/Card/FactPair.vue";
import Container from "Components/UI/Container.vue";
import HeroSection from "Components/UI/HeroSection.vue";
import TabbedNavigation, { type TabDefinition } from "Components/UI/TabbedNavigation/TabbedNavigation.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { useTabParam } from "Composables/useTabParam";
import type { TableResponse } from "Types/dataTable";
import { formatClock, formatFileSize } from "Utils/formatting";
import ArtistSongs, { type SongRow } from "./ArtistSongs.vue";

/** One artist as ArtistController shaped it — every value raw. */
interface ArtistDetail {
    id: string;
    name: string;
    /** Albums CREDITED to them — their discography, not the ones they guest on. */
    albums: number;
    /** How many music tracks credit them as the performer. */
    songs: number;
    /** Total playing time of those tracks in seconds — 0, never null (the server COALESCEs). */
    duration: number;
    /** Total size of those files in bytes — 0, never null, for the same reason. */
    size: number;
    /**
     * The genre most of their songs carry — derived, since MixTape tags genre per track
     * and an artist may vary it. Null when they have no tracks, or none with a genre.
     */
    genre: string | null;
    /**
     * That genre's own page. Null exactly when `genre` is — there is nothing to lead to —
     * and then the tile is plain text instead of a link.
     */
    genreUrl: string | null;
}

const props = defineProps<{
    /** The artist being shown, as ArtistController shaped it. */
    artist: ArtistDetail;
    /** Every album credited to them, for the albums tab. Unpaginated — see Discography. */
    discography: DiscographyAlbum[];
    /**
     * Up to three cover URLs for the hero's fan, picked at random per request and one per
     * album (ArtistController → FannedCovers). Empty when none of their records carries
     * artwork, which the fan renders as a single placeholder.
     */
    covers: string[];
    /** Their songs, as the server-driven table payload (rows + pagination + sort + search). */
    table: TableResponse<SongRow>;
    /**
     * How often this artist has been listened to: the reader's own listens and everybody
     * else's, as listening events (App\Services\Player\PlayCounts). Its own prop rather than
     * a member of `artist` because PlayCountFacts refreshes exactly this key in place when a
     * track finishes. Raw counts — a zero is something the tiles leave unsaid.
     */
    plays: { own: number; others: number };
}>();

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
// The artist's own crumb is a raw label, not a key — their name is data. The trail
// matches the listing's so the parent chip is the table this row came from.
setBreadcrumbs([
    { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
    { labelKey: "music.widgets.artists", href: "/music/artists", icon: "artist" },
    { label: props.artist.name }
]);

/**
 * Their catalogue's total playing time as a clock, e.g. "14:22:07".
 *
 * The tile always renders, unlike the album page's: the server COALESCEs the sum, so an
 * artist with no files of their own reads "0:00" — and beside a non-zero album count that
 * is the informative answer (a compilation owner credited with albums whose songs are
 * filed under the individual performers), not missing data. Which is also why the `?? ""`
 * can never fire: formatClock is null-in/null-out and `duration` is never null. It stands
 * in for the album page's `v-if`, which here would be a condition that is never false.
 */
const playingTime = computed(() => formatClock(props.artist.duration) ?? "");

/** What their files add up to on disk, in the viewer's locale. Never null, as above. */
const totalSize = computed(() => formatFileSize(props.artist.size, locale.value));

/**
 * The open tab, mirrored into `?tab=` so a reload or a shared link reopens it. Starts
 * unset when the URL names none, which TabbedNavigation resolves to the first tab
 * (albums). Costs no request to change — see useTabParam.
 */
const { tab: openTab } = useTabParam();

/**
 * The two tabs, with the counts from the hero on them so a reader can see how much is
 * behind each before opening it. A `computed` so the labels re-evaluate on a locale switch.
 *
 * Albums leads because it is the smaller, structural view of the same catalogue — the shape
 * of an artist's work before its contents — and it matches the legacy app's order, where
 * this page's tabs were Alben then Songs.
 */
const tabs = computed<TabDefinition[]>(() => [
    { id: "albums", label: t("music.columns.albums"), icon: "album", count: props.artist.albums },
    { id: "songs", label: t("music.columns.songs"), icon: "song", count: props.artist.songs }
]);
</script>

<template>
    <Head :title="artist.name" />
    <container>
        <div class="artist">
            <!-- `unframed-cover`: the fan is a fixed size, so the hero's cover square would
                 reserve height it cannot fill — see the prop. -->
            <hero-section unframed-cover>
                <!-- Not a photograph, but where one would be — see the banner. -->
                <template #cover><cover-sleeves :covers="covers" :title="artist.name" scale="hero" /></template>
                <!-- The page's heading lives here rather than in a <Headline>, as on the song
                     and album pages: the hero sets the type, the level is ours. -->
                <template #title
                    ><h2>{{ artist.name }}</h2></template
                >
                <!-- Play or enqueue the whole subject. Pinned to the far end of the
                     heading line by the hero, not by anything here. -->
                <template #menu><subject-menu subject="artist" /></template>
                <!-- The facts about the catalogue rather than about any one file. Only the
                     genre can be absent, and then its tile is skipped rather than reading
                     "unknown" — the counts always exist, 0 included, because 0 is an
                     answer here (see playingTime).

                     The genre leads first: it is the one fact that says what this artist
                     *is*, where the rest say how much of them there is. It is also the one
                     tile that LEADS somewhere — to that genre's page, via the URL the
                     server decided (`genreUrl`) — which is what fills it. -->
                <template #metadata>
                    <fact-pair
                        v-if="artist.genre"
                        icon="genre"
                        :label="t('music.columns.genre')"
                        :value="artist.genre"
                        :href="artist.genreUrl ?? undefined"
                    />
                    <fact-pair icon="album" :label="t('music.columns.albums')" :value="String(artist.albums)" />
                    <fact-pair icon="song" :label="t('music.columns.songs')" :value="String(artist.songs)" />
                    <fact-pair icon="duration" :label="t('music.columns.duration')" :value="playingTime" />
                    <fact-pair icon="file" :label="t('music.columns.size')" :value="totalSize" />
                    <!-- Last, and only when there is something to say: what has actually been
                         listened to comes after what the artist IS. See PlayCountFacts. -->
                    <play-count-facts :plays="plays" subject="artist" />
                </template>
            </hero-section>

            <!-- The catalogue. One tab per way of reading it; the component owns which
                 panel shows and all of the ARIA, so this only supplies the two slots. -->
            <tabbed-navigation
                v-model:selected-tab="openTab"
                name="artist"
                :tabs="tabs"
                :label="t('music.artist.tabs.label')"
            >
                <template #albums>
                    <discography :albums="discography" />
                </template>

                <template #songs>
                    <artist-songs :table="table" :base-url="`/music/artists/${artist.id}`" />
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
   the same rule, for the same reason, as SongPage's `.song` and AlbumPage's `.album`.
   Each tab's own styling lives with the component that owns it — Discography and
   ArtistSongs — so there is nothing here but the page's own vertical rhythm. */
.artist {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}
</style>
