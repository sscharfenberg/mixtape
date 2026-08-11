<script setup lang="ts">
/******************************************************************************
 * ShareTracks
 * What a share link plays, listed for someone with no account: one row per track, the facts
 * that place it, and one button that starts the list there.
 *
 * Page-local rather than shared, like PlaylistTracks and for the same reason: a row here is
 * built around what a GUEST may do with a track, which is exactly one thing. It is that
 * component with everything an account owns taken out — no grip (nothing to reorder), no
 * link on the title (a guest may not visit /music/songs/…, and the server has already
 * pointed every `href` back at this page), no year chip and no per-row menu.
 *
 * ONE BUTTON, AND IT MEANS THE WHOLE SHARE. Pressing play on the fourth row queues
 * everything the link grants and starts at that row — the same rule the playlist's rows
 * follow, and what a listener means by pressing play on a track in a list. Its label says
 * "from here", because a bare play triangle reads as "play this one song".
 *
 * THE ROW IS NOT A LINK, which is the visible difference from every other listing in this
 * app: there is nowhere for a guest to go. So the row lights up on hover the way the others
 * do — it holds a control, and the halo says so — but nothing stretches an overlay across
 * it, and the button therefore needs no lifting back above one.
 *
 * A FACT THAT IS TRUE OF EVERY ROW IS A FACT ABOUT THE SUBJECT, and the hero has already
 * said it — so the artist and album chips appear only when they actually vary down the list.
 * On an album share that drops both (twelve rows reading "Radiohead · OK Computer" under a
 * hero saying exactly that), on an artist share it drops the artist and keeps the album, and
 * on a compilation it keeps both because there the artist is the fact that tells the rows
 * apart. It is the same rule the artist page's own songs table follows by hand — it has no
 * artist column — worked out from the data rather than from the subject kind, so a
 * compilation shared as an album still gets its performers.
 *
 * NOTHING HERE COSTS A REQUEST. Each row already IS a queue entry, shaped by
 * App\Services\Shares\ShareGrant with its three URLs rewritten into the share's own space,
 * so the button acts on the objects in hand and the player learns nothing about shares.
 *****************************************************************************/
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import Icon from "Components/UI/Icon.vue";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import type { QueueTrack } from "Composables/usePlayerQueue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { formatClock } from "Utils/formatting";

const props = defineProps<{
    /**
     * The granted tracks, in playing order (album, then disc, then track — QueuePayload's
     * order, whatever the subject). Rendered in the order given: it is the order the link
     * plays in, so re-sorting here would only let the page and the player disagree.
     */
    tracks: QueueTrack[];
}>();

const { t } = useI18n();
const { playNow } = usePlayerQueue();
const { play } = usePlayerAudio();

/**
 * How long the track runs, as a clock. Empty for a file whose tags carried no duration,
 * which drops the chip rather than printing "0:00" — `formatClock` is null-in/null-out.
 */
const playingTime = (track: QueueTrack): string => formatClock(track.duration) ?? "";

/**
 * Whether a fact varies down the list, and so tells one row from another.
 *
 * A single distinct value means the hero has already said it — see the banner. An untagged
 * row counts as its own value, deliberately: a list where one file credits nobody is a list
 * where the credit IS worth reading, and hiding it there would leave the odd row out looking
 * identical to the rest.
 */
const varies = (fact: (track: QueueTrack) => string | null): boolean =>
    new Set(props.tracks.map(fact)).size > 1;

/** Show the performer only where the rows differ on one — a compilation, a mixed grant. */
const showArtist = computed<boolean>(() => varies(track => track.artist));

/** Show the album only where the rows differ on one — an artist share, a mixed grant. */
const showAlbum = computed<boolean>(() => varies(track => track.album));

/**
 * Queue everything the link grants and start at this row.
 *
 * `play()` is called explicitly, and it matters: loading a track does not start it, and a
 * browser only allows playback from a user gesture — this click is that gesture, so the call
 * has to happen inside the handler rather than in a watcher a tick later.
 */
function playFrom(index: number): void {
    playNow(props.tracks, index);
    play();
}
</script>

<template>
    <!-- A list, semantically: a screen reader gets "list, N items" before the rows, which is
         the one thing a bare stack of <div>s would say worse. -->
    <ul class="share-tracks" :aria-label="t('share.tracks.label')">
        <li v-for="(track, index) in tracks" :key="track.id" class="share-tracks__item">
            <!-- `xsmall` (32px) rather than the `small` a table row uses: the row's height is
                 set by whatever is tallest in it, and at 48px the artwork takes that job over
                 and makes the list half as tall again for a thumbnail nobody reads.
                 `decorative`, because the title is the next thing in the row and naming the
                 picture too would have a screen reader read every row twice. -->
            <span class="share-tracks__art">
                <cover-image :src="track.coverUrl" :title="track.name" size="xsmall" decorative />
            </span>

            <span class="share-tracks__name">{{ track.name }}</span>

            <!-- One chip per fact, dropped rather than shown empty when the tags don't carry
                 it: a file crediting nobody, one filed under no album. Each has its own
                 breakpoint — see the styles. -->
            <span class="share-tracks__meta">
                <span v-if="showArtist && track.artist" class="share-tracks__fact share-tracks__fact--artist">
                    <icon name="artist" :size="1" />{{ track.artist }}
                </span>
                <span v-if="showAlbum && track.album" class="share-tracks__fact share-tracks__fact--album">
                    <icon name="album" :size="1" />{{ track.album }}
                </span>
                <span v-if="playingTime(track)" class="share-tracks__fact share-tracks__fact--duration">
                    <icon name="duration" :size="1" />{{ playingTime(track) }}
                </span>
            </span>

            <!-- Icon only. What it does is carried by the tooltip on hover and by `aria-label`
                 for everyone who never sees one — and both say "from here", because a bare
                 play triangle on a row reads as "play this one song". -->
            <button
                type="button"
                class="share-tracks__play"
                v-tooltip="t('share.tracks.playHint')"
                :aria-label="t('share.tracks.play', { name: track.name })"
                @click="playFrom(index)"
            >
                <icon name="play" :size="1" />
            </button>
        </li>
    </ul>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* A column of rows, at every width. A share is a running order you read down, and the order
   is information — a grid of cards in a fluid column count reflows it into something a reader
   has to reconstruct. The UA marker and padding go (normalize.css leaves lists alone). */
.share-tracks {
    display: flex;
    flex-direction: column;

    padding: 0;
    margin: 0;
    gap: map.get(s.$c-share-tracks, "gap");

    list-style: none;
}

/* ONE ENTRY. Artwork, title, facts, play — the title takes the slack, so the facts and the
   button sit against the trailing edge and the buttons line up down the list as a column
   however long the titles run.

   The frame is the playlist entry's, re-picked from the globals (see the colour partial). */
.share-tracks__item {
    display: flex;
    position: relative; // positioning context for the hover glow
    align-items: center;
    flex-wrap: wrap;
    isolation: isolate; // keep the button's rung contained to this row

    box-sizing: border-box;

    padding: map.get(s.$c-share-tracks, "row-padding");
    border: map.get(s.$c-share-tracks, "border") solid map.get(c.$c-share-tracks, "border");
    gap: map.get(s.$c-share-tracks, "row-gap");

    background-color: map.get(c.$c-share-tracks, "background");
    border-radius: map.get(s.$c-share-tracks, "radius");

    @media (prefers-reduced-motion: no-preference) {
        transition:
            background-color ti.$c-share-tracks ease-out,
            box-shadow ti.$c-share-tracks ease-out;
    }

    /* The house treatment, identical to the playlist row and the artist card behind it: the
       two-layer control-neon halo over a wash that only SHIFTS the row's existing fill.
       `:focus-within` as well as `:hover`, because the row holds a real control and can be
       reached by keyboard without a pointer ever touching it. */
    &:hover,
    &:focus-within {
        background-color: map.get(c.$c-share-tracks, "hover-background");
        box-shadow:
            0 0 0.6em 0.1em map.get(c.$c-share-tracks, "glow"),
            0 0 1.5em 0.25em map.get(c.$c-share-tracks, "glow");
    }
}

/* The artwork earns its place from `landscape` up. Below that the row is a phone's width and
   the title is what a reader picks a track by — a thumbnail there costs a third of the line
   to say what the title already says. */
.share-tracks__art {
    display: none;

    @include m.mq("landscape") {
        display: block;
    }
}

/* The title takes the slack, which is what pushes the facts and the button to the trailing
   edge. `min-width: 0` so a long title wraps inside the row instead of widening it — the trap
   a flex child hits when its content cannot shrink below max-content. */
.share-tracks__name {
    min-width: 0;
    flex: 1 1 auto;
}

/* The facts, as a row of chips that wraps under the title on a narrow row. */
.share-tracks__meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;

    gap: map.get(s.$c-share-tracks, "meta-gap");

    color: map.get(c.$c-share-tracks, "surface-meta");
}

/* One chip. `min-width: 0` again, since an artist or album name is arbitrarily long. */
.share-tracks__fact {
    display: inline-flex;
    align-items: center;

    min-width: 0;
    padding: map.get(s.$c-share-tracks, "meta-padding");
    gap: map.get(s.$c-share-tracks, "fact-icon-gap");

    background-color: map.get(c.$c-share-tracks, "meta-background");
    border-radius: map.get(s.$c-share-tracks, "meta-radius");
}

/* WHAT A ROW SHOWS GROWS WITH THE VIEWPORT, one fact per breakpoint, ordered by how much each
   tells you apart from the title — the same rule the playlist's rows follow. A phone gets the
   title and the button and nothing else. */
.share-tracks__fact--artist {
    display: none;

    @include m.mq("portrait") {
        display: inline-flex;
    }
}

.share-tracks__fact--album {
    display: none;

    @include m.mq("landscape") {
        display: inline-flex;
    }
}

.share-tracks__fact--duration {
    display: none;

    @include m.mq("desktop") {
        display: inline-flex;
    }
}

/* Quiet at rest, neon when live — see the colour partial for why a list of identical glyphs
   must not all shout. The wash is what gives it edges to aim at. */
.share-tracks__play {
    display: inline-flex;
    align-items: center;

    padding: map.get(s.$c-share-tracks, "control-padding");
    border: 0;

    background-color: map.get(c.$c-share-tracks, "control-background");
    color: map.get(c.$c-share-tracks, "control");
    border-radius: map.get(s.$c-share-tracks, "control-radius");

    cursor: pointer;

    @media (prefers-reduced-motion: no-preference) {
        transition:
            background-color ti.$c-share-tracks ease-out,
            color ti.$c-share-tracks ease-out;
    }

    &:hover,
    &:focus-visible {
        background-color: map.get(c.$c-share-tracks, "control-background-active");
        color: map.get(c.$c-share-tracks, "control-active");
    }
}
</style>
