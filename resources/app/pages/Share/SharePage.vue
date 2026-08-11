<script setup lang="ts">
/******************************************************************************
 * SharePage
 * What somebody with a link and no account sees, at /s/{share} (route `shares.show`,
 * rendered by SharePageController) — the headline use case of an instance that is
 * deliberately reachable from the internet (docs/sharing.md).
 *
 * THE ONLY PAGE IN THIS APP A STRANGER CAN REACH, which decides nearly everything about it.
 * It is NOT the song / album / artist page with parts hidden: those carry an ActionPanel,
 * add-to-playlist, play counts and a download button, every one of which is either
 * meaningless without an account or actively wrong. What is left is the shape a detail page
 * has — a <Headline>, a hero, then the content — filled with three things only: what this
 * is, how long the link lives, and a way to play it.
 *
 * IT ALSO HAS TO LOOK LIKE THE APP, not like a landing page built beside it, because for the
 * person who opens it this page IS MixTape. So every part of it is the app's own: the
 * glowing headline every listing wears, HeroSection, FactPair, CoverImage, the fanned sleeves
 * an artist gets in place of a photograph, and the real player in the layout below.
 *
 * ITS LAYOUT IS ITS OWN (ShareLayout, chosen by `defineOptions` below, which Inertia reads in
 * preference to the app-wide default in main.ts) — FullLayout without the breadcrumb, and
 * with the play queue in the mode that never writes itself down. That second half is the load
 * bearing one: see ShareLayout.
 *
 * AN EXPIRED LINK STILL RENDERS. It keeps its heading — so a reader can say WHAT they are
 * asking to be sent again — and replaces everything else with one card saying the link has
 * expired. The tracks prop is empty in that state, decided by the server, so there is nothing
 * here to be careful with.
 *
 * THE COPY LIVES UNDER `share.*` in the catalogs, while the MINTING side (the button and its
 * modal, which appear on Music pages) is under `music.share.*`. Two halves of one feature in
 * two places, deliberately: the catalogs are grouped by where a reader meets the words, and
 * nobody meets both of these.
 *****************************************************************************/
import { Head } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import ShareLayout from "Components/Layout/ShareLayout.vue";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import CoverSleeves from "Components/Music/CoverSleeves.vue";
import SubjectActions from "Components/Music/SubjectActions.vue";
import Card from "Components/UI/Card/Card.vue";
import FactPair from "Components/UI/Card/FactPair.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import HeroSection from "Components/UI/HeroSection.vue";
import Icon from "Components/UI/Icon.vue";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { formatDateTime, formatDuration } from "Utils/formatting";
import ShareTracks from "./ShareTracks.vue";

/**
 * Which kinds of thing a link can be about — App\Enums\ShareSubject, and nothing else.
 *
 * Exported so this page's tests name the same union rather than a widened `string`, which
 * would let a spec assert a kind the server can never send.
 */
export type ShareKind = "song" | "album" | "artist";

const props = defineProps<{
    /** The link itself: what it is about, when it dies, and whether it already has. */
    share: {
        /** The subject kind, which decides the heading's glyph and how the hero is drawn. */
        kind: ShareKind;
        /** ISO-8601 instant — formatted here, since the server knows neither locale nor timezone. */
        validUntil: string;
        /** True once `validUntil` has passed. The server sends no tracks in that state. */
        expired: boolean;
    };
    /** The thing shared, as one shape for all three kinds, with nulls where a kind has no such fact. */
    subject: {
        /** The song / album / artist name. Data, so it is printed rather than translated. */
        name: string;
        /** Performing artist for a song, album artist for an album, null for an artist share. */
        artist: string | null;
        /** The album a shared SONG sits on, else null. */
        album: string | null;
        /** Release year, or null for an artist share and for an untagged rip. */
        year: number | null;
        /** How many tracks the link grants — the grant's own count, not the subject's. */
        songs: number;
        /** Total playing time in raw seconds, or null when nothing granted carries one. */
        duration: number | null;
        /** The hero image, or null for an artist share and for a subject with no artwork. */
        coverUrl: string | null;
        /** Up to three of an artist's own covers for the fan; empty for the other two kinds. */
        sleeves: string[];
    };
    /**
     * What the link plays, in playing order — ordinary queue entries whose three URLs point
     * into the share's own space, so the player needs to know nothing about shares. Empty
     * for an expired link.
     */
    tracks: QueueTrack[];
}>();

// Not FullLayout: no breadcrumb, and a play queue that persists nothing. Inertia reads a
// page's own `layout` in preference to the default set in main.ts.
defineOptions({ layout: ShareLayout });

const { t, locale } = useI18n();

/**
 * The heading's glyph — the same one the app uses for that kind of thing everywhere else.
 *
 * Named `subjectIcon`, not `icon`: a plain `icon` binding shadows the Icon COMPONENT in the
 * template, since the compiler resolves the `<icon>` tag against the setup bindings and finds
 * the ref first. The same trap SongPage's `songFacts` is named around.
 */
const subjectIcon = computed<string>(() => ({ song: "song", album: "album", artist: "artist" })[props.share.kind]);

/**
 * Whether the hero draws a fan of sleeves rather than one picture.
 *
 * An artist has no artwork in this app — MixTape stores no artist images — so their hero
 * fans a few of their own records, exactly as the artist page does. It also decides
 * `unframedCover`: a fan brings its own fixed size, and the hero's 240px square would
 * otherwise open the page with a band of empty panel.
 */
const fanned = computed<boolean>(() => props.share.kind === "artist");

/**
 * How long everything the link grants plays, as a human breakdown ("1 Stunde, 12 Minuten").
 *
 * `formatDuration` rather than `formatClock`, the same call the playlist hero makes: a total
 * is read as an amount of time, not as a position on a timeline. Empty for a subject that
 * plays for no time at all, which drops the tile — "0 Sekunden" says nothing twice.
 */
const playtime = computed<string>(() =>
    props.subject.duration === null || props.subject.duration === 0
        ? ""
        : formatDuration(props.subject.duration, (key, count) => t(`common.duration.${key}`, count))
);

/**
 * When the link stops working, in the reader's own locale and timezone.
 *
 * The one fact on this page that is about the LINK rather than about the music, and it earns
 * a tile beside the others because a recipient plans around it: "I'll listen at the weekend"
 * is a different decision on Tuesday than on Sunday. Empty for an unparseable instant, which
 * drops the tile rather than printing a broken date.
 */
const expires = computed<string>(() => formatDateTime(props.share.validUntil, locale.value) ?? "");

/**
 * Whether the track list is worth drawing.
 *
 * A SONG SHARE HAS NO LIST: the hero has just named the one track, printed its artist, its
 * album and its playing time, and offers the button that plays it — a one-row list under
 * that repeats all of it and reads as a rendering fault. An album or an artist gets the list,
 * which is the whole content of the page.
 */
const listed = computed<boolean>(() => !props.share.expired && props.share.kind !== "song");
</script>

<template>
    <Head :title="subject.name" />
    <!-- The page's heading, in the same glowing frame every listing wears — and OUTSIDE the
         Container on purpose, like theirs: the glowing border has to reach the window edge so
         its seam hides off-screen (see Container). The subject's own name rather than a
         translated word, because it is data. Kept for an expired link too, so a reader can
         say what they are asking to be sent again. -->
    <headline glow>
        <icon :name="subjectIcon" :size="3" />
        {{ subject.name }}
    </headline>
    <container>
        <div class="share">
            <!-- EXPIRED: one card, and nothing else. It says the link has died and what to do
                 about it, which is all this page can honestly offer — the row is still in the
                 table, so the server could tell us this; a REVOKED link never reaches here at
                 all, because revoking deletes the row and binding 404s. -->
            <card v-if="share.expired" :title="t('share.expired.title')">
                <p>{{ t("share.expired.body") }}</p>
            </card>

            <template v-else>
                <hero-section :unframed-cover="fanned">
                    <!-- The artwork. An artist fans up to three of their own sleeves in place
                         of the photograph this app does not store; the other two kinds show
                         one picture, or CoverImage's glyph inside the hero's dashed square
                         when nothing on file carries artwork. Not `decorative`: here the
                         artwork IS the subject. -->
                    <template #cover>
                        <cover-sleeves v-if="fanned" :covers="subject.sleeves" :title="subject.name" scale="hero" />
                        <cover-image v-else :src="subject.coverUrl" :title="subject.name" size="xlarge" />
                    </template>
                    <!-- No #title: the name heads the page in the <Headline> above, as it does
                         on all four Music detail pages. -->
                    <template #metadata>
                        <fact-pair
                            v-if="subject.artist"
                            icon="artist"
                            :label="t('music.columns.artist')"
                            :value="subject.artist"
                        />
                        <fact-pair
                            v-if="subject.album"
                            icon="album"
                            :label="t('music.columns.album')"
                            :value="subject.album"
                        />
                        <fact-pair
                            v-if="subject.year !== null"
                            icon="calendar"
                            :label="t('music.columns.year')"
                            :value="String(subject.year)"
                        />
                        <!-- Always shown, including for a song share where it reads "1": it is
                             what the LINK grants, and a recipient should be able to see that
                             at a glance rather than infer it from the list's length. -->
                        <fact-pair
                            icon="song"
                            :label="t('music.columns.songs')"
                            :value="String(subject.songs)"
                        />
                        <fact-pair
                            v-if="playtime"
                            icon="duration"
                            :label="t('music.columns.duration')"
                            :value="playtime"
                        />
                        <!-- The one tile about the link rather than the music. `description`
                             carries the sentence a tooltip would show, so the readers a
                             tooltip never reaches get it too (FactPair wires it up). -->
                        <fact-pair
                            v-if="expires"
                            icon="calendar"
                            :label="t('share.expires.label')"
                            :value="expires"
                            :description="t('share.expires.description')"
                        />
                    </template>
                    <!-- The two verbs, handed the tracks the page already holds — so neither
                         costs a round trip, and a guest never waits on a partial reload of a
                         page they have no account for. -->
                    <template #actions>
                        <subject-actions :tracks="tracks" />
                    </template>
                </hero-section>

                <share-tracks v-if="listed" :tracks="tracks" />
            </template>
        </div>
    </container>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/sizes" as s;

/* The hero is one panel and the list is a column of rows; the page only stacks its blocks and
   spaces them. It takes the CardGroup's own gutter (s.$c-card "gap") rather than a token of
   its own, exactly as the song page does, so the rhythm down this page cannot drift from the
   rhythm down that one. */
.share {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}
</style>
