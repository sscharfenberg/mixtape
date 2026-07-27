<script setup lang="ts">
/******************************************************************************
 * SongPage
 * One song's detail page, reached at /music/songs/{uuid} (route
 * `music.songs.show`, rendered by SongController) — the target of a row click in
 * the Songs listing, so it is also the first page in the app you arrive at from a
 * table row.
 *
 * Nested under the listing's own directory (pages/Music/Songs/Song/) to mirror
 * the URL: the detail view lives *inside* the listing it came from, the same way
 * `music.songs.show` sits under `music.songs`.
 *
 * Scaffold: the facts the controller passes, a back link, and nothing else. The
 * player, cover art, play history and the clone list ("also appears in N other
 * places") come later — see docs/app-rewrite.md.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";

/** One song as shaped by SongController (duration pre-formatted to m:ss / h:mm:ss). */
interface Song {
    /** The track's UUID — the id in the page's own URL. */
    id: string;
    /** Track title, straight from the file's tags. */
    name: string;
    /** Performing artist's name, or null for a file whose tags carried none. */
    artist: string | null;
    /** Album (collection) name, or null when the song isn't filed under one. */
    album: string | null;
    /** Genre name, or null when untagged. */
    genre: string | null;
    /** The album's release year, or null when unknown. */
    year: number | null;
    /** Playing time as a clock string, or null when the file carried no duration. */
    duration: string | null;
}

const props = defineProps<{
    /** The song being shown. */
    song: Song;
}>();

const { t } = useI18n();

/**
 * The facts list, as label/value pairs. A `computed` so the (already-translated)
 * labels follow a locale switch, and so an untagged field simply drops out
 * instead of rendering a row with an empty value — a scaffold that shows "Genre:
 * —" four times reads as broken rather than as sparse tags.
 */
const facts = computed(() =>
    [
        { key: "artist", label: t("music.columns.artist"), value: props.song.artist },
        { key: "album", label: t("music.columns.album"), value: props.song.album },
        { key: "year", label: t("music.columns.year"), value: props.song.year },
        { key: "genre", label: t("music.columns.genre"), value: props.song.genre },
        { key: "duration", label: t("music.columns.duration"), value: props.song.duration }
    ].filter(fact => fact.value !== null && fact.value !== "")
);
</script>

<template>
    <Head :title="song.name" />
    <headline glow>{{ song.name }}</headline>
    <container>
        <dl class="song__facts">
            <template v-for="fact in facts" :key="fact.key">
                <dt class="song__label">{{ fact.label }}</dt>
                <dd class="song__value">{{ fact.value }}</dd>
            </template>
        </dl>
        <p class="song__back">
            <labelled-link href="/music/songs">{{ t("music.song.backToList") }}</labelled-link>
        </p>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

/* Layout only — no colour of its own, so the list inherits the page's text
   colour and keeps following the theme. Gutters come from s.$p-song
   (styles/abstracts/sizes/pages/_song.scss). */
.song__facts {
    display: grid;
    grid-template-columns: max-content 1fr;

    gap: map.get(s.$p-song, "facts-row-gap") map.get(s.$p-song, "facts-column-gap");
}

.song__label {
    font-weight: bold;
}

.song__back {
    margin-top: map.get(s.$p-song, "facts-column-gap");
}
</style>
