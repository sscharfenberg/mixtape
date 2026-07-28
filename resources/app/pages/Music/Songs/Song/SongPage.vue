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
 * The body is every stored fact about the file as one label/value list: tags,
 * position in the album, the technical stream fields, size / mtime / path. The
 * controller sends raw values and the formatting happens here, with the active
 * locale — sizes, rates and dates all read differently per language.
 *
 * Still a scaffold in what it *does*: the player, cover art, play history and the
 * clone list ("also appears in N other places") come later — see
 * docs/app-rewrite.md.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import LabelledLink from "Components/UI/LabelledLink.vue";
import type { SongDetail } from "Types/music";
import { formatClock, formatDateTime, formatDecimals, formatFileSize } from "Utils/formatting";

const props = defineProps<{
    /** The song being shown, as SongController shaped it — every value raw. */
    song: SongDetail;
}>();

const { t, locale } = useI18n();

/** One row of the facts list: a label, its formatted value, and whether it needs the full width. */
type Fact = { key: string; label: string; value: string | null; wide?: boolean };

/**
 * A position within its set, as "2/8" — or the bare number when there is no
 * trustworthy total. Some rips number tracks straight through a multi-disc set,
 * so a track can sit past its own disc's count; "17/8" would read as a bug in the
 * app rather than as sloppy tags, so the denominator is dropped instead (the same
 * guard the legacy song page had).
 */
const position = (index: number | null, total: number | null): string | null => {
    if (index === null) return null;

    return total !== null && index <= total ? `${index}/${total}` : `${index}`;
};

/**
 * The bit-rate reading, e.g. "320 kbit/s" — stored in bits per second, shown in
 * kbit/s because that is the number on every encoder dialog. VBR is appended
 * rather than given its own row: on a VBR file the figure IS the average, so the
 * flag only means anything next to it.
 */
const bitRate = (): string | null => {
    if (props.song.bitRate === null) return null;

    const reading = `${formatDecimals(props.song.bitRate / 1000, locale.value)} ${t("music.song.units.bitRate")}`;

    return props.song.vbr ? `${reading} (${t("music.song.vbr")})` : reading;
};

/**
 * The facts list, as label/value pairs. A `computed` so the (already-translated,
 * already-locale-formatted) rows follow a locale switch, and so an untagged field
 * simply drops out instead of rendering a row with an empty value — a page that
 * shows "Genre: —" a dozen times reads as broken rather than as sparse tags.
 *
 * Order is deliberate: what the song IS (tags, place in the album), then how it
 * was encoded, then the file on disk — widening from listener-facing to
 * server-facing, so the interesting rows are never below the fold.
 */
const facts = computed<Fact[]>(() => {
    const song = props.song;

    return [
        { key: "artist", label: t("music.columns.artist"), value: song.artist },
        { key: "album", label: t("music.columns.album"), value: song.album },
        { key: "year", label: t("music.columns.year"), value: song.year === null ? null : String(song.year) },
        { key: "genre", label: t("music.columns.genre"), value: song.genre },
        {
            key: "disc",
            label: t("music.song.labels.disc"),
            // Only a real multi-disc set earns a row: "1/1" on a single-CD album
            // is noise, and the whole point of the total is telling sets apart.
            value: song.discTotal !== null && song.discTotal > 1 ? position(song.disc, song.discTotal) : null
        },
        { key: "track", label: t("music.song.labels.track"), value: position(song.track, song.trackTotal) },
        { key: "duration", label: t("music.columns.duration"), value: formatClock(song.duration) },
        { key: "composer", label: t("music.song.labels.composer"), value: song.composer },
        { key: "publisher", label: t("music.song.labels.publisher"), value: song.publisher },
        { key: "codec", label: t("music.song.labels.codec"), value: song.codec },
        { key: "bitRate", label: t("music.song.labels.bitRate"), value: bitRate() },
        {
            key: "sampleRate",
            label: t("music.song.labels.sampleRate"),
            value:
                song.sampleRate === null
                    ? null
                    : `${formatDecimals(song.sampleRate, locale.value)} ${t("music.song.units.sampleRate")}`
        },
        {
            key: "channel",
            label: t("music.song.labels.channel"),
            value: song.channel === null ? null : t(`music.channel.${song.channel}`)
        },
        // A boolean, so it is never absent — and "no" is worth knowing: it is why
        // an album falls back to its Folder.jpg for artwork.
        { key: "cover", label: t("music.song.labels.cover"), value: song.cover ? t("common.yes") : t("common.no") },
        {
            key: "size",
            label: t("music.song.labels.size"),
            value: song.sizeBytes === null ? null : formatFileSize(song.sizeBytes, locale.value)
        },
        {
            key: "modifiedAt",
            label: t("music.song.labels.modifiedAt"),
            value: formatDateTime(song.modifiedAt, locale.value)
        },
        { key: "addedAt", label: t("music.song.labels.addedAt"), value: formatDateTime(song.addedAt, locale.value) },
        // Last, and the full width of the list: a path is the longest value here
        // and the one most likely to be copied somewhere else.
        { key: "path", label: t("music.song.labels.path"), value: song.path, wide: true }
    ].filter(fact => fact.value !== null && fact.value !== "");
});
</script>

<template>
    <Head :title="song.name" />
    <headline glow>{{ song.name }}</headline>
    <container>
        <dl class="song__facts">
            <template v-for="fact in facts" :key="fact.key">
                <dt class="song__label">{{ fact.label }}</dt>
                <dd class="song__value" :class="{ 'song__value--wide': fact.wide }">{{ fact.value }}</dd>
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
@use "Abstracts/typography" as t;

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

/* The path row: spans the whole list (so it starts on its own line under its
   label) and breaks anywhere, since a path has no spaces to wrap at and would
   otherwise push the grid wider than the viewport on a phone. */
.song__value--wide {
    grid-column: 1 / -1;

    overflow-wrap: anywhere;

    font-family: map.get(t.$p-song, "path");
}

.song__back {
    margin-top: map.get(s.$p-song, "facts-column-gap");
}
</style>
