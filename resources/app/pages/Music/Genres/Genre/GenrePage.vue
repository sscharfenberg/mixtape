<script setup lang="ts">
/******************************************************************************
 * GenrePage
 * One genre's detail page, at /music/genres/{id} (route `music.genres.show`) — where a
 * row of the Genres listing leads, and where the genre tile on an artist's page goes.
 * Nested under the listing's folder like the other three detail pages: the detail view
 * lives *inside* the listing it came from, mirroring the URL.
 *
 * ONE block for now — the hero, holding the genre's name and the four numbers its listing
 * row shows: how many artists call it their main genre, how many songs carry it, and what
 * those files add up to in time and on disk. The ARTISTS and SONGS listings that belong
 * under it are still to come, exactly as on the artist page.
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
import FactPair from "Components/UI/Card/FactPair.vue";
import Container from "Components/UI/Container.vue";
import HeroSection from "Components/UI/HeroSection.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { formatClock, formatFileSize } from "Utils/formatting";

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
</script>

<template>
    <Head :title="genre.name" />
    <container>
        <div class="genre">
            <hero-section>
                <!-- The page's h1 lives here rather than in a <Headline>, as on the other
                     detail pages: the hero sets the type, the level is ours. -->
                <template #title
                    ><h1>{{ genre.name }}</h1></template
                >
                <!-- The facts about the genre as a whole. None can be absent — the server
                     sends 0 rather than null for all four — so every tile always renders,
                     and 0 is an answer here rather than missing data. -->
                <template #metadata>
                    <fact-pair icon="artist" :label="t('music.columns.artists')" :value="String(genre.artists)" />
                    <fact-pair icon="song" :label="t('music.columns.songs')" :value="String(genre.songs)" />
                    <fact-pair icon="duration" :label="t('music.columns.duration')" :value="playingTime" />
                    <fact-pair icon="file" :label="t('music.columns.size')" :value="totalSize" />
                </template>
            </hero-section>
        </div>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

/* Stacks the page's blocks and spaces them, taking the CardGroup's own gutter
   (s.$c-card "gap") so the rhythm down the page matches the rhythm between two cards —
   the same rule, for the same reason, as the other three detail pages. One block today;
   the artists and songs listings land under it. */
.genre {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}
</style>
