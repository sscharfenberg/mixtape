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
 * THE LINK IS IN THE QUEUE BEFORE ANYTHING IS PRESSED (2026-08-12), which is what turned the
 * page from a listing into a player. It used to draw its own list of the granted tracks
 * (ShareTracks) with a play button per row, and that list existed only because the queue was
 * empty until somebody pressed one of them. Filling the queue on arrival makes the list a
 * duplicate of the queue below it — same tracks, same order, one of them live — so the list is
 * gone and NowPlayingSection stands in its place: the visualiser, what is either side, and the
 * queue itself. `enqueue` went with it, and had to: appending a link's tracks to a queue that
 * already holds exactly them is a way to hear everything twice.
 *
 * WHAT THAT COSTS, and why it is worth it: a listener may now remove and reorder rows in a
 * share's queue, which the old list would not let them do. Nothing about it is written down
 * (ShareLayout's ephemeral mode), so the link is one reload away from whole again.
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
import { computed, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import Button from "Components/Form/Button.vue";
import ShareLayout from "Components/Layout/ShareLayout.vue";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import CoverSleeves from "Components/Music/CoverSleeves.vue";
import NowPlayingSection from "Components/Player/NowPlayingSection.vue";
import Card from "Components/UI/Card/Card.vue";
import FactPair from "Components/UI/Card/FactPair.vue";
import Container from "Components/UI/Container.vue";
import Headline from "Components/UI/Headline.vue";
import HeroSection from "Components/UI/HeroSection.vue";
import Icon from "Components/UI/Icon.vue";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { formatDateTime, formatDuration } from "Utils/formatting";

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
const { playNow } = usePlayerQueue();
const { isPlaying, play } = usePlayerAudio();

/**
 * Put the link's tracks in the queue as the page opens — the change that lets everything below
 * the hero be the player rather than a listing.
 *
 * LOADING IS NOT PLAYING, and nothing here starts anything: a browser only allows playback from
 * a user gesture, and a page that began making noise on arrival would be the wrong thing even
 * if it could. What the reader gets is a queue with the link in it, a player bar, and the three
 * rows below already describing what pressing play would do.
 *
 * IT STANDS DOWN FOR A PLAYER THAT IS ALREADY RUNNING, which is the guard the whole feature
 * turns on. `beginEphemeralQueue` deliberately leaves the queue alone precisely so that a reader
 * who was listening keeps listening while they look at a share, and that reader is usually the
 * OWNER, opening a link they minted — replacing their queue unasked would cut their music off
 * mid-track. A paused queue is fair game: nothing under `/s/` is written down, so theirs is
 * restored from storage the moment they leave the space (see endEphemeralQueue).
 *
 * An EXPIRED link sends no tracks, and an empty `playNow` would empty the queue rather than
 * fill it — so it returns instead, leaving whatever was there.
 */
onMounted(() => {
    if (props.tracks.length === 0 || isPlaying.value) return;

    playNow(props.tracks);
});

/**
 * Play the link from its first track.
 *
 * It restates the queue rather than resuming, because that is what a hero's play button means
 * everywhere else in this app — "play this thing", from the top — and the transport in the bar
 * below is the control that means resume. It also covers the one case `onMounted` stood down
 * for: a reader who arrived here with something else playing presses this, and gets the share.
 *
 * `play()` is called explicitly, and inside the handler: loading a track does not start it, and
 * this click is the gesture the browser will allow playback from.
 */
function playShare(): void {
    playNow(props.tracks);
    play();
}

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
 * Whether there is anything to play, and so whether the page has a player half at all.
 *
 * It asks the TRACKS rather than `share.expired`, because the two can differ in one direction
 * that matters: an expired link sends none, but so does a live link whose grant resolves to
 * nothing (a collection with no rows). Three empty boxes and a play button that does nothing
 * read as a page that failed to load, where a hero on its own reads as a link with nothing in
 * it — which is the truth.
 */
const playable = computed<boolean>(() => props.tracks.length > 0);
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
                    <!-- ONE VERB, not the pair the Music heroes wear. The link's tracks are
                         already in the queue (see onMounted), so "enqueue" would append a
                         second copy of everything below — and there is no library here to
                         build a queue out of anyway, which is what that verb is for. -->
                    <template #actions>
                        <Button v-if="playable" variant="primary" no-halo class="share__play" @click="playShare">
                            <!-- `playlist`, not `play`: this fills the queue and starts it,
                                 which is a list operation — a bare play triangle reads as
                                 "play this one thing" (SubjectActions makes the same call). -->
                            <icon name="playlist" :size="1" />
                            <span>{{ t("share.play") }}</span>
                        </Button>
                    </template>
                </hero-section>

                <!-- What the app's own Now Playing page shows below its hero, and for the same
                     reasons — except that here the hero above is about the LINK's subject, which
                     is why only these three rows come across and not a second hero. -->
                <now-playing-section v-if="playable" />
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
