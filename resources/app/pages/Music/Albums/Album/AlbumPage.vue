<script setup lang="ts">
/******************************************************************************
 * AlbumPage
 * One album's detail page, at /music/albums/{id} (route `music.albums.show`) —
 * where a row of the Albums listing leads. Nested under the listing's folder for
 * the same reason SongPage is: the detail view lives *inside* the listing it came
 * from, mirroring the URL.
 *
 * A SCAFFOLD, deliberately at the stage SongPage started from: the HeroSection
 * identifies the album — its art, its title, and the handful of facts that describe
 * the container rather than any one file — and nothing else. The track list, which
 * is the actual point of an album page, and the play/queue controls the hero will
 * grow, both wait for the player (docs/app-rewrite.md); the placeholder paragraph
 * says so rather than leaving the page looking finished.
 *
 * The controller sends raw values (seconds, an ISO-8601 instant, plain counts) and
 * the formatting happens here with the active locale — the same split every other
 * page here uses (Utils/formatting.ts).
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import FactPair from "Components/UI/Card/FactPair.vue";
import Container from "Components/UI/Container.vue";
import HeroSection from "Components/UI/HeroSection.vue";
import Icon from "Components/UI/Icon.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { formatClock, formatDateTime } from "Utils/formatting";

/** One album as AlbumController shaped it — every value raw. */
interface AlbumDetail {
    id: string;
    name: string;
    /** Its album-artist, or null for a compilation filed under none. */
    artist: string | null;
    year: number | null;
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

const props = defineProps<{
    /** The album being shown, as AlbumController shaped it. */
    album: AlbumDetail;
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
</script>

<template>
    <Head :title="album.name" />
    <container>
        <div class="album">
            <hero-section>
                <!-- An <img> when the album has art, and an icon when it does not:
                     HeroSection draws the square as a dashed placeholder around
                     whatever is not an image. Same `music` glyph as the song page,
                     for the same reason — a disc reads as a failed image in the slot
                     where a cover belongs. -->
                <template #cover>
                    <!-- The album's own name as the alt text, not "cover of …" — the
                         same call SongPage makes, and for the same reason: a screen
                         reader already says "image". -->
                    <img v-if="album.coverUrl" :src="album.coverUrl" :alt="album.name" />
                    <icon v-else name="music" :size="5" :aria-label="t('music.album.noCover')" role="img" />
                </template>
                <template #title
                    ><h1>{{ album.name }}</h1></template
                >
                <!-- The facts that belong to the album as a whole. Each is skipped
                     when there is nothing to say: a compilation has no album-artist,
                     an untagged rip no year. The counts always exist. -->
                <template #metadata>
                    <fact-pair
                        v-if="album.artist"
                        icon="artist"
                        :label="t('music.columns.artist')"
                        :value="album.artist"
                    />
                    <fact-pair
                        v-if="album.year !== null"
                        icon="calendar"
                        :label="t('music.columns.year')"
                        :value="String(album.year)"
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
                </template>
            </hero-section>
            <p class="album__pending">{{ t("music.album.tracklistPending") }}</p>
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

.album__pending {
    margin: 0;
}
</style>
