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
 * Two blocks, both shared components this page only fills in: the HeroSection (its
 * cover, title and metadata line handed over as slots), then every stored fact about
 * the file, grouped into cards by what kind of fact it is — the tags, its place in the
 * album, how it was encoded, the file on disk. The controller sends raw values and the
 * formatting happens here, with the active locale — sizes, rates and dates all read
 * differently per language; Facts groups the finished pairs and lays them out.
 *
 * The page has no <Headline>: its title lives in the hero next to the cover, which
 * is what makes that first row read as one unit instead of a caption under a
 * banner.
 *
 * The hero's own controls are the SubjectMenu in its heading (play this song, or
 * queue it) — which replaced a lone "enqueue" Button in #actions on 2026-08-06, since
 * the menu offers both verbs and the button only ever offered one. Play history and the
 * clone list ("also appears in N other places") are still to come — see
 * docs/app-rewrite.md.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import SubjectMenu from "Components/Music/SubjectMenu.vue";
import FactPair from "Components/UI/Card/FactPair.vue";
import Facts, { type Fact } from "Components/UI/Card/Facts.vue";
import Container from "Components/UI/Container.vue";
import HeroSection from "Components/UI/HeroSection.vue";
import { useBreadcrumbs } from "Composables/useBreadcrumbs";
import type { SongDetail } from "Types/music";
import { formatClock, formatDateTime, formatDecimals, formatFileSize, formatPosition } from "Utils/formatting";

const props = defineProps<{
    /** The song being shown, as SongController shaped it — every value raw. */
    song: SongDetail;
    /**
     * How often this song has been listened to: the reader's own listens and everybody
     * else's, counted across every copy of the recording (App\Services\Player\PlayCounts).
     * Raw counts — a zero is something this page leaves unsaid, which is a decision about
     * display and so belongs here.
     */
    plays: { own: number; others: number };
}>();

const { t, locale } = useI18n();
const { setBreadcrumbs } = useBreadcrumbs();
// The song's own crumb is a raw label, not a key — its title is data, and it is
// the page's only heading in the trail (the hero carries it visually instead of
// a Headline, see the note above).
setBreadcrumbs([
    { labelKey: "header.siteMenu.music", href: "/music", icon: "music" },
    { labelKey: "music.widgets.songs", href: "/music/songs", icon: "song" },
    { label: props.song.name }
]);


/**
 * Alt text for the cover: the album it belongs to, or the song when the file is filed
 * under no album. Not "cover of …" — a screen reader already says "image".
 */
const coverAlt = computed(() => props.song.album ?? props.song.name);

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
 * The facts list, as label/value pairs. A `computed` so the rows — translated and
 * locale-formatted right here — are rebuilt on a locale switch. Untagged fields
 * are left in the list as nulls on purpose: Facts drops them, so this stays one
 * flat description of the full row set rather than a pile of conditionals.
 *
 * Every row carries a `group`, which is what Facts turns into cards. The order is
 * deliberate twice over: the groups run from what the song IS to where it lives on
 * the server (so the listener-facing rows are never below the fold), and within a
 * group the rows keep the order they'd be read in.
 *
 * Named `songFacts`, not `facts`: a plain `facts` binding would shadow the Facts
 * component in the template, since the compiler resolves the `<facts>` tag against
 * the setup bindings and would find the ref first.
 */
/**
 * The listening lines, as sentences rather than counted tiles.
 *
 * A COUNT OF ZERO IS NOT SHOWN AT ALL — the owner's rule, and the right one: "others played
 * this song 0 times" is a sentence about nothing, and a page full of zeroes on a fresh
 * library would say only that the feature exists. So a song nobody has played says nothing,
 * and each half appears on its own terms.
 *
 * Pluralised through vue-i18n rather than by interpolating a number into one string, so
 * German can say "einmal" where it wants a word and "{count}-mal" where it wants a figure —
 * which English cannot do with the same sentence.
 */
const playLines = computed<string[]>(() =>
    [
        props.plays.own > 0 ? t("music.song.plays.own", props.plays.own) : null,
        props.plays.others > 0 ? t("music.song.plays.others", props.plays.others) : null
    ].filter((line): line is string => line !== null)
);

const songFacts = computed<Fact[]>(() => {
    const song = props.song;
    const tags = t("music.song.groups.tags");
    const album = t("music.song.groups.album");
    const audio = t("music.song.groups.audio");
    const file = t("music.song.groups.file");

    return [
        // Leads to the artist's page, the same `artistUrl` the hero tile uses — so the
        // two tiles for the one fact stay the one thing, filled and clickable in both
        // places (exactly as `album` does further down).
        {
            key: "artist",
            group: tags,
            icon: "artist",
            label: t("music.columns.artist"),
            value: song.artist,
            href: song.artistUrl ?? undefined
        },
        {
            key: "year",
            group: tags,
            icon: "calendar",
            label: t("music.columns.year"),
            value: song.year === null ? null : String(song.year)
        },
        // Leads to the genre's page — the third and last of this page's facts that names
        // something with a page of its own. It appears only here, not in the hero, so
        // unlike the artist and album there is one tile to keep in step rather than two.
        {
            key: "genre",
            group: tags,
            icon: "genre",
            label: t("music.columns.genre"),
            value: song.genre,
            href: song.genreUrl ?? undefined
        },
        {
            key: "composer",
            group: tags,
            // No composer glyph in the sprite; `account` is the only person in it, and a
            // composer is a person credited rather than the performing artist.
            icon: "account",
            label: t("music.song.labels.composer"),
            value: song.composer
        },
        {
            key: "track",
            group: album,
            icon: "track",
            label: t("music.song.labels.track"),
            value: formatPosition(song.track, song.trackTotal)
        },
        {
            key: "duration",
            group: album,
            icon: "duration",
            label: t("music.columns.duration"),
            value: formatClock(song.duration)
        },
        {
            key: "disc",
            group: album,
            // The disc glyph goes to the DISC row, not the album row below it: here it
            // means "which of the set", which is what the picture shows.
            icon: "album",
            label: t("music.song.labels.disc"),
            // Shown even as "1/1" on a single-CD album (owner's call, reversing the
            // earlier "only a real multi-disc set earns a row"): the card is about the
            // release now, and a release with one disc is a fact about it, not noise.
            value: formatPosition(song.disc, song.discTotal)
        },
        // The album's name lives HERE and only here (not up in `tags`): this card is
        // the release the song sits on, so its name heads the facts about it — and the
        // label that put the release out follows it, for the same reason.
        //
        // One of the three facts on this page that LEAD somewhere (the artist and the genre
        // above are the others), so it is a filled tile: `href` comes from the server
        // (`albumUrl`), which is null for a song filed under no album — and then the tile
        // is a plain fact again, with no dead link and no special colour.
        {
            key: "album",
            group: album,
            icon: "music",
            label: t("music.columns.album"),
            value: song.album,
            href: song.albumUrl ?? undefined
        },
        // Who the RELEASE is credited to — read right after its name, and before the label
        // that put it out, because those three are the release's own identity.
        //
        // NOT a duplicate of the artist tile up in `tags`, and the difference is the reason
        // it earns a row: that one is who performed THIS TRACK, this one is whose album it
        // is. On a compilation they diverge on every row ("Various Artists" over a hundred
        // different performers), and on a guest appearance they diverge the other way. The
        // labels have to say which is which, hence a key of its own rather than reusing
        // `music.columns.artist` — two tiles both reading "Künstler" with different names in
        // them is worse than not showing the second at all.
        //
        // Leads to that artist's page like the other credited names do, and only when the
        // server handed over a URL.
        {
            key: "albumArtist",
            group: album,
            icon: "artist",
            label: t("music.song.labels.albumArtist"),
            value: song.albumArtist,
            href: song.albumArtistUrl ?? undefined
        },
        // The one fact with no icon: a record label is a company, and the sprite has no
        // company / tag glyph to stand for one. Better a gap than a misleading picture.
        { key: "publisher", group: album, label: t("music.song.labels.publisher"), value: song.publisher },
        { key: "codec", group: audio, icon: "codec", label: t("music.song.labels.codec"), value: song.codec },
        { key: "bitRate", group: audio, icon: "bitrate", label: t("music.song.labels.bitRate"), value: bitRate() },
        {
            key: "sampleRate",
            group: audio,
            icon: "samplerate",
            label: t("music.song.labels.sampleRate"),
            value:
                song.sampleRate === null
                    ? null
                    : `${formatDecimals(song.sampleRate, locale.value)} ${t("music.song.units.sampleRate")}`
        },
        {
            key: "channel",
            group: audio,
            icon: "channels",
            label: t("music.song.labels.channel"),
            value: song.channel === null ? null : t(`music.channel.${song.channel}`)
        },
        // A boolean, so it is never absent — and "no" is worth knowing: it is why
        // an album falls back to its Folder.jpg for artwork.
        {
            key: "cover",
            group: audio,
            icon: "cover",
            label: t("music.song.labels.cover"),
            value: song.cover ? t("common.yes") : t("common.no")
        },
        {
            key: "size",
            group: file,
            icon: "file",
            label: t("music.song.labels.size"),
            value: song.sizeBytes === null ? null : formatFileSize(song.sizeBytes, locale.value)
        },
        // Both timestamps take the calendar: they are the same kind of fact, and giving
        // one of them a different glyph would imply a difference that isn't there.
        {
            key: "modifiedAt",
            group: file,
            icon: "calendar",
            label: t("music.song.labels.modifiedAt"),
            value: formatDateTime(song.modifiedAt, locale.value)
        },
        {
            key: "addedAt",
            group: file,
            icon: "calendar",
            label: t("music.song.labels.addedAt"),
            value: formatDateTime(song.addedAt, locale.value)
        },
        // Last, and the longest value on the page — and the one most likely to be copied
        // somewhere else, so it is monospaced to stay scannable as a path. `wide` is what
        // makes the whole file card span the grid's full width to hold it.
        {
            key: "path",
            group: file,
            icon: "file",
            label: t("music.song.labels.path"),
            value: song.path,
            wide: true,
            mono: true
        }
    ];
});
</script>

<template>
    <Head :title="song.name" />
    <container>
        <div class="song">
            <hero-section>
                <!-- The artwork, or the music glyph when the file carried none —
                     CoverImage picks between them, and HeroSection frames whatever is not
                     an <img> in its dashed square. Not `decorative`: on this page the
                     artwork IS the subject, unlike a listing row where the title sits in
                     the next cell. -->
                <template #cover>
                    <cover-image :src="song.coverUrl" :title="coverAlt" size="xlarge" />
                </template>
                <!-- The page's heading lives here rather than in a <Headline>: beside the cover
                     it reads as one unit with the artwork instead of a caption under a
                     banner. HeroSection sets the type; the level is ours to choose. -->
                <template #title
                    ><h2>{{ song.name }}</h2></template
                >
                <!-- Play or enqueue the whole subject. Pinned to the far end of the
                     heading line by the hero, not by anything here. -->
                <template #menu><subject-menu subject="song" /></template>
                <!-- The same three facts the cards below repeat, as the hero's own tiles:
                     up here they are what identifies the song, down there they are part of
                     its full tag set. FactPair is the facts' own tile, so the two agree by
                     construction rather than by matching styles. Each is skipped when the
                     file carried no such tag.

                     The artist and album tiles both LEAD somewhere — to that artist's page
                     and that album's page — via the URLs the server decided; the facts
                     cards below link the same two facts to the same two places, so a
                     reader gets the link wherever they happen to be looking. -->
                <template #metadata>
                    <fact-pair
                        v-if="song.artist"
                        icon="artist"
                        :label="t('music.columns.artist')"
                        :value="song.artist"
                        :href="song.artistUrl ?? undefined"
                    />
                    <fact-pair
                        v-if="song.album"
                        icon="album"
                        :label="t('music.columns.album')"
                        :value="song.album"
                        :href="song.albumUrl ?? undefined"
                    />
                    <fact-pair
                        v-if="song.year !== null"
                        icon="calendar"
                        :label="t('music.columns.year')"
                        :value="String(song.year)"
                    />
                </template>
            </hero-section>
            <!-- What this song's listening amounts to, in one or two sentences. Its own
                 block rather than a facts tile: these are statements about the reader and
                 the household rather than tags read off the file, and a "3×" in a labelled
                 tile beside CODEC and BITRATE would read as one more technical field.
                 Rendered only when there is something to say (see playLines). -->
            <p v-if="playLines.length > 0" class="song__plays">
                <span v-for="line in playLines" :key="line">{{ line }}</span>
            </p>
            <facts :facts="songFacts" wide-groups />
        </div>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;

/* The hero is one panel and the facts are a row of cards; the page only stacks its
   blocks and spaces them. It takes the CardGroup's own gutter (s.$c-card "gap") rather
   than a token of its own, so the rhythm down the page cannot drift from the rhythm
   between two cards. */
.song {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}

/* One line per sentence, and quieter than the cards around it: this is a footnote about the
   song, not a fact from its tags. `flex` with a wrap rather than two <p>s so the two lines
   sit together as one block with the page's own rhythm between them — and so a single line
   costs no empty second row. The colour is the body's own muted ink; nothing here is worth a
   token of its own, and CLAUDE.md's rule for a page is to read the component's rather than
   mint a duplicate. */
.song__plays {
    display: flex;
    flex-wrap: wrap;

    margin: 0;
    gap: 0 map.get(s.$c-card, "gap");

    color: map.get(c.$c-body, "surface");

    font-size: 0.9em;
}
</style>
