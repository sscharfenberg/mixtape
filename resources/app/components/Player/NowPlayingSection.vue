<script setup lang="ts">
/******************************************************************************
 * NowPlayingSection
 * The Now Playing block: what the player sounds like, what is either side of the loaded
 * track, and what is lined up behind it — three rows that describe PLAYBACK rather than a
 * page, which is why they live here rather than in `pages/NowPlaying/`.
 *
 * IT IS THE BOTTOM THREE ROWS OF NowPlayingPage, LIFTED OUT UNCHANGED (2026-08-12). That page
 * still renders them and still looks identical; what moved is only where they are defined, so
 * that the guest share page at `/s/{share}` can show the same three without restating the box
 * surface, the gaps and the neighbour grid — which is the drift QueueList's own banner records
 * having paid for once already.
 *
 * IT READS THE PLAYER, IT IS NOT HANDED ONE. The queue and the loaded track live in the browser
 * so playback survives Inertia swapping pages (usePlayerQueue), so this takes exactly one prop —
 * and that prop is the one thing the queue genuinely does not carry.
 *
 * WHAT IT DELIBERATELY DOES NOT HOLD is the hero above it: the loaded track's artwork, its
 * facts and the status pill stay on NowPlayingPage, because a page that already has a hero of
 * its own has no room for a second. The share page is that page — its hero is about the SHARED
 * SUBJECT, which is what the link is — so what it wants from Now Playing is these three rows
 * and not the fourth.
 *****************************************************************************/
import Visualizer from "Components/Player/Visualizer.vue";
import NowPlayingQueue from "Components/PlayQueue/NowPlayingQueue.vue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import NeighbourTrack from "./NeighbourTrack.vue";

const props = withDefaults(
    defineProps<{
        /**
         * Each drawn track's genre, keyed by track id — the one fact the neighbour cards show
         * that `QueueTrack` has no field for, and which therefore has to be handed in.
         *
         * Optional, and empty by default, because only one of the two pages that render this
         * can answer it: Now Playing fetches it per track change (NowPlayingFacts, behind
         * `auth`), while the share page has no such endpoint and no business having one. An
         * absent id reads exactly as a null genre — the card drops the chip — so "nobody
         * asked" and "this rip carried no genre frame" need no distinguishing here.
         */
        genres?: Record<string, string | null | undefined>;
    }>(),
    { genres: () => ({}) }
);

const { nextTrack, previousTrack, next, previous } = usePlayerQueue();

/** One track's genre, or null when it has none, is unknown, or there is no track at all. */
const genreOf = (id: string | undefined): string | null => (id === undefined ? null : (props.genres[id] ?? null));
</script>

<template>
    <div class="now-playing-section">
        <!-- WHAT IT SOUNDS LIKE, ALWAYS (the owner's call, 2026-08-10). It used to be mounted
             only while something was playing, on the argument that a paused EQ is a row of flat
             bars in an empty box; what that actually produced was a page whose four rows became
             three every time you pressed pause, with everything below jumping up a row and back
             down again. A quiet baseline holding its place says "nothing to hear right now",
             which is both true and stationary. The reading itself costs nothing while paused —
             the analyser reads zeros, and `requestAnimationFrame` stops dead as soon as the page
             is hidden. -->
        <div class="now-playing-section__box"><visualizer /></div>

        <!-- WHAT IS EITHER SIDE. Both cards keep their place at the ends of the queue, so the
             queue below does not move as playback advances. -->
        <div class="now-playing-section__neighbours">
            <neighbour-track
                direction="previous"
                :track="previousTrack"
                :genre="genreOf(previousTrack?.id)"
                @step="previous"
            />
            <neighbour-track direction="next" :track="nextTrack" :genre="genreOf(nextTrack?.id)" @step="next" />
        </div>

        <!-- WHAT IS LINED UP, in the same box the visualiser above sits in. -->
        <div class="now-playing-section__box"><now-playing-queue /></div>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;

/* Stacks the three rows and spaces them, taking the CardGroup's own gutter (s.$c-card "gap") so
   the rhythm inside this block matches the rhythm between two cards — and, because the page
   that hosts it uses the same gutter between ITS blocks, so that nesting one flex column in
   the other changes nothing about where any row lands. */
.now-playing-section {
    display: flex;
    flex-direction: column;

    gap: map.get(s.$c-card, "gap");
}

/* Stacked on a phone, side by side from `portrait` up. Equal columns rather than auto, so the two
   cards stay the same width whatever their titles are — a "next" card that grew because its track
   has a long name would make the pair read as a hierarchy.

   AND `minmax(0, …)` IS WHAT MAKES THAT TRUE, rather than the `1fr` this said until 2026-08-10, which
   promised equal columns and did not deliver them. `1fr` is `minmax(auto, 1fr)`, and the `auto` floor
   is min-content — which for `.neighbour__title` (`white-space: nowrap`) is the WHOLE title, however
   long. Measured with a 78-character title: 452px beside 765px at a 1280px window, and 247px of the
   row hanging outside the page at 640px. The queue's own grid carries the same note; the owner found
   it there first, on Burzum's *Filosofem*. */
.now-playing-section__neighbours {
    display: grid;

    grid-template-columns: minmax(0, 1fr);

    gap: map.get(s.$c-card, "gap");

    @include m.mq("portrait") {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }
}

/* THE BOX the visualiser and the queue sit in: the card's own inset, border, corner and fill,
   without the Card component.

   NOT `Card`, and the reason is measurable rather than stylistic. A Card is built to sit in a
   CardGroup's ROW and carries `flex: 1 1 <basis>` to share that row's width — dropped into this
   block's column, that basis becomes a HEIGHT, and the visualiser's panel came out exactly 300px
   tall around a 72px strip. Reading the tokens instead gives the same surface with no opinion
   about how tall it should be. */
.now-playing-section__box {
    padding: map.get(s.$c-card, "padding");
    border: map.get(s.$c-card, "border") solid map.get(c.$c-card, "border");

    background-color: map.get(c.$c-card, "background");
    color: map.get(c.$c-card, "surface");
    border-radius: map.get(s.$c-card, "radius");
}
</style>
