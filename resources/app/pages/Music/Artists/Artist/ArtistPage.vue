<script setup lang="ts">
/******************************************************************************
 * ArtistPage
 * One artist's detail page, at /music/artists/{id} (route `music.artists.show`) —
 * where a row of the Artists listing leads, and where the artist tile on a song or
 * album page goes. Nested under the listing's folder for the same reason SongPage and
 * AlbumPage are: the detail view lives *inside* the listing it came from, mirroring
 * the URL.
 *
 * ONE block for now — the hero, holding the artist's name and the facts that describe
 * their catalogue: two album counts, their songs, and what those files add up to in
 * time and on disk. Their SONGS and ALBUMS listings are still to come (owner's call:
 * the page and the links into it first), which is the one way this page is not yet the
 * shape of its two siblings.
 *
 * No `#cover` slot at all, and that is deliberate rather than missing: MixTape stores no
 * artist images, so there is nothing to point an <img> at. HeroSection draws its dashed
 * placeholder only when the slot EXISTS and holds a non-image — i.e. "no artwork on file"
 * — while leaving the slot out says "this kind of page has no artwork", which is the true
 * statement here (see HeroSection's docblock).
 *
 * The controller sends raw values (seconds, bytes, plain counts) and the formatting
 * happens here against the active locale — the same split every other page here uses
 * (Utils/formatting.ts).
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import FactPair from "Components/UI/Card/FactPair.vue";
import Container from "Components/UI/Container.vue";
import HeroSection from "Components/UI/HeroSection.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import { formatClock, formatFileSize } from "Utils/formatting";

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
</script>

<template>
    <Head :title="artist.name" />
    <container>
        <div class="artist">
            <hero-section>
                <!-- The page's h1 lives here rather than in a <Headline>, as on the song
                     and album pages: the hero sets the type, the level is ours. -->
                <template #title
                    ><h1>{{ artist.name }}</h1></template
                >
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
   the same rule, for the same reason, as SongPage's `.song` and AlbumPage's `.album`.
   One block today; the songs and albums listings land under it. */
.artist {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}
</style>
