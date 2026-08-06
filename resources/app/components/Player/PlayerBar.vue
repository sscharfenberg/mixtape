<script setup lang="ts">
/******************************************************************************
 * PlayerBar
 * The transport bar that takes the footer's place once the queue has a track
 * loaded: cover, what is playing, the timeline, and the controls for moving through
 * the queue. Mounted ONCE in FullLayout, and that is not a style choice — the
 * <audio> element below has to survive an Inertia page swap, and anything inside a
 * page is destroyed and rebuilt on every navigation. A player in a page would stop
 * the music every time you opened an album.
 *
 * IT OWNS THE ELEMENT, usePlayerAudio owns the behaviour. The element lives in this
 * template (rather than a bare `new Audio()`) because a real DOM element is what iOS
 * treats as a first-class media element and what a browser test can inspect; every
 * decision about it — auto-advance, Media Session, what happens at a track boundary
 * — is in the composable, which is where it can be reasoned about and tested.
 *
 * It shows on "the queue has a current track", NOT on "audio is playing". A paused
 * player has to stay on screen — a bar that vanished when you hit pause would take
 * the play button with it.
 *
 * The bar is FIXED rather than sitting where the footer sat in the flow: you have to
 * be able to reach pause from halfway down a long album. That is also why it
 * publishes its height as `--app-player-height` (the mirror of AppHeader's
 * `--app-header-height`) — a fixed bar is out of the flow, so <main> and the queue
 * panel have to reserve the space themselves.
 *
 * TWO SHAPES, one grid. On a phone the timeline gets a line of its own under the
 * cover, title and transport, because a rail worth dragging does not fit beside
 * them; from `landscape` up it moves into the row. The bar's height is therefore not
 * a constant, which is precisely why it is measured and published rather than read
 * off a token.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import PlayerSettings from "Components/Player/PlayerSettings.vue";
import PlayerTimeline from "Components/Player/PlayerTimeline.vue";
import PlayerVolume from "Components/Player/PlayerVolume.vue";
import Icon from "Components/UI/Icon.vue";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import { usePlayerQueue } from "Composables/usePlayerQueue";

const { t } = useI18n();
// `hasNext` / `hasPrevious` come FROM the queue rather than being derived here, and that
// moved when shuffle arrived: under a random order the answers depend on the shuffle walk,
// which is the composable's private state — "is there a track behind this one" is no
// longer "is the index above zero".
const { current, hasNext, hasPrevious, next, previous } = usePlayerQueue();
const { isPlaying, currentTime, duration, buffered, attach, detach, toggle, seek } = usePlayerAudio();

/** The bar element, measured to publish its height. */
const barRef = ref<HTMLElement | null>(null);

/** The one element in the app that makes sound. Handed to usePlayerAudio on mount. */
const audioRef = ref<HTMLAudioElement | null>(null);

/**
 * Publish the bar's rendered height to the document as `--app-player-height`, and
 * hand the audio element over to the player.
 *
 * The height trick is the same one AppHeader uses for `--app-header-height`, and it is
 * needed for the same reason in reverse: the bar is fixed, so it is out of the flow and
 * would sit on top of the last rows of a page. <main> pads its bottom by this, and the
 * queue panel pins its own bottom edge to it.
 *
 * OBSERVED, not measured once, and that is the whole point of the ResizeObserver. The
 * bar's height varies for two reasons now: this app sets a different root font-size per
 * breakpoint (so the bar is 61.6px on a phone and 62.4px one breakpoint up), and the
 * timeline moves into the row at `landscape`, changing the number outright. Published a
 * single time at mount, the value went stale the moment the window was resized across a
 * breakpoint: the queue panel kept pinning its bottom to the old number and left a
 * sliver of page showing between itself and the bar. Sub-pixel, but on a light panel
 * over a light page it reads as a seam.
 */
onMounted(() => {
    if (audioRef.value) attach(audioRef.value);

    /** Measure the bar and publish it, both at mount and on every resize the observer sees. */
    const setHeightVar = (): void => {
        if (barRef.value) {
            document.documentElement.style.setProperty(
                "--app-player-height",
                `${barRef.value.getBoundingClientRect().height}px`
            );
        }
    };
    setHeightVar();

    const observer = new ResizeObserver(setHeightVar);
    if (barRef.value) observer.observe(barRef.value);

    onUnmounted(() => observer.disconnect());
});

// Clear the height when the queue empties and the footer comes back, or <main> keeps
// reserving room for a bar that is no longer there — and let the element go with it,
// so the composable is not left holding listeners on a detached node.
onUnmounted(() => {
    detach();
    document.documentElement.style.removeProperty("--app-player-height");
});
</script>

<template>
    <div v-if="current" ref="barRef" class="player-bar" :aria-label="t('player.bar.label')" role="region">
        <!-- Renders nothing (a <audio> without `controls` is display:none in every UA
             stylesheet) and is deliberately not hidden with `hidden`, which some
             engines treat as a reason to drop media loading. -->
        <audio ref="audioRef" preload="metadata" />

        <cover-image
            :src="current.coverUrl"
            :title="current.name"
            size="small"
            decorative
            class="player-bar__cover"
        />

        <span class="player-bar__meta">
            <Link :href="current.href" prefetch class="player-bar__name">{{ current.name }}</Link>
            <span v-if="current.artist" class="player-bar__artist">{{ current.artist }}</span>
        </span>

        <player-timeline
            class="player-bar__timeline"
            :current-time="currentTime"
            :duration="duration"
            :buffered="buffered"
            @seek="seek"
        />

        <player-settings class="player-bar__settings" />

        <player-volume class="player-bar__volume" />

        <div class="player-bar__transport">
            <button
                type="button"
                class="player-bar__control"
                :disabled="!hasPrevious"
                :aria-label="t('player.bar.previous')"
                @click="previous"
            >
                <icon name="first-page" :size="1" />
            </button>
            <!-- One button for both states rather than two swapped in and out: the
                 control must not move under a finger that is about to press it again,
                 and a screen reader should hear the same button change its label. -->
            <button
                type="button"
                class="player-bar__control player-bar__control--play"
                :aria-label="isPlaying ? t('player.bar.pause') : t('player.bar.play')"
                @click="toggle"
            >
                <icon :name="isPlaying ? 'pause' : 'play'" :size="2" />
            </button>
            <button
                type="button"
                class="player-bar__control"
                :disabled="!hasNext"
                :aria-label="t('player.bar.next')"
                @click="next"
            >
                <icon name="last-page" :size="1" />
            </button>
        </div>
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

/* Fixed to the bottom of the viewport, and frosted like the header — the two are
   the same family of affixed chrome, and the blur is why the background is an
   alpha-adjusted grey rather than a solid one.

   A GRID, not a flex row, because the timeline has two homes. On a phone it takes a
   line of its own below the rest; from `landscape` up it sits in the row between the
   title and the transport. Named areas rather than order-juggling, so the DOM order
   (title, timeline, transport) can stay the reading order in both shapes.

   `min-height` rather than `height`: on the stacked shape the bar is as tall as its
   two rows need, and the token's meaning is unchanged — the bar is at least as tall as
   the cover it holds plus its padding. */
.player-bar {
    display: grid;
    position: fixed;
    inset: auto 0 0;
    z-index: z.$c-player-bar;
    align-items: center;
    grid-template-areas:
        "cover meta settings volume transport"
        "timeline timeline timeline timeline timeline";
    grid-template-columns: auto minmax(0, 1fr) auto auto auto;

    box-sizing: border-box;
    min-height: map.get(s.$c-player-bar, "height");
    padding: map.get(s.$c-player-bar, "padding");
    border-top: map.get(s.$c-player-bar, "border") solid map.get(c.$c-player-bar, "border");
    gap: map.get(s.$c-player-bar, "gap");

    background-color: map.get(c.$c-player-bar, "background");
    backdrop-filter: blur(12px);
    color: map.get(c.$c-player-bar, "surface");

    @include m.mq("landscape") {
        grid-template-areas: "cover meta timeline settings volume transport";

        /* The timeline gets twice the slack the title does, so the rail grows into a
           wide window instead of leaving a long stretch of empty title beside it. The
           settings and volume columns are `auto` — one icon wide each, and never
           competitors for the slack. */
        grid-template-columns: auto minmax(0, 1fr) minmax(0, 2fr) auto auto auto;
    }

    &__cover {
        grid-area: cover;
    }

    /* Same `min-width: 0` trap as the queue row: without it a long title refuses to
       shrink, pushes the transport controls off the right edge, and never
       ellipsises because nothing overflows. */
    &__meta {
        display: flex;
        flex-direction: column;
        grid-area: meta;

        min-width: 0;
    }

    &__name,
    &__artist {
        overflow: hidden;

        white-space: nowrap;

        text-overflow: ellipsis;
    }

    &__name {
        color: inherit;

        text-decoration: none;

        @media (prefers-reduced-motion: no-preference) {
            transition: color ti.$c-player-bar linear;
        }

        &:hover {
            color: map.get(c.$c-player-bar, "control-hover");
        }
    }

    &__artist {
        color: map.get(c.$c-player-bar, "muted");

        font-size: 0.85em;
    }

    &__timeline {
        grid-area: timeline;
    }

    /* First of the two popover triggers, immediately after the timeline: the settings in
       it are about the QUEUE — what order it plays in, what happens at its end — so they
       belong beside the thing that shows progress through it, ahead of a control that is
       only about how loud this one track is. */
    &__settings {
        grid-area: settings;
    }

    /* Between the settings and the transport, so the reading order is position → order →
       level → the buttons that change position. */
    &__volume {
        grid-area: volume;
    }

    &__transport {
        display: flex;
        align-items: center;
        grid-area: transport;

        gap: map.get(s.$c-player-bar, "gap");
    }

    /* Each ENABLED control sits in a filled pill, so the four buttons in this bar read as
       one set — the volume trigger is a PopOver button and came with a fill of its own, and
       three bare glyphs beside it looked like an oversight rather than a choice. The radius
       is `100vw` for the same reason it is on that trigger: a pill, whatever the content's
       size, which matters here because the play button's glyph is twice the others'. */
    &__control {
        display: inline-flex;
        align-items: center;

        padding: map.get(s.$c-player-bar, "control-padding");
        border: 0;

        background-color: map.get(c.$c-player-bar, "control-background");
        color: map.get(c.$c-player-bar, "control");

        border-radius: 100vw;

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition:
                color ti.$c-player-bar linear,
                background-color ti.$c-player-bar linear;
        }

        &:hover:not(:disabled) {
            background-color: map.get(c.$c-player-bar, "control-background-hover");
            color: map.get(c.$c-player-bar, "control-hover");
        }

        /* Both ends of the queue. NO PILL, which is the point: a filled control reads as
           pressable, so the absence of the fill is what says "not now" — `muted` alone was
           doing that job on its own before every other control gained a background, and a
           greyed glyph inside a pill identical to its neighbours' would now read as enabled.
           Still occupying its space rather than hidden, so the bar keeps its shape and the
           control stays where the reader will look for it. */
        &:disabled {
            background-color: transparent;
            color: map.get(c.$c-player-bar, "muted");

            cursor: default;
        }

        /* The one coloured surface in the bar, because it is the one control a listener
           reaches for without looking. `--play` is set on both states of the same button
           (see the template note on why it is one button and not two). */
        &--play {
            background-color: map.get(c.$c-player-bar, "play-background");
            color: map.get(c.$c-player-bar, "play-surface");

            // Its own hover, or the shared one above would repaint the coloured fill grey
            // on the way past — identical specificity, so source order settles it.
            &:hover:not(:disabled) {
                background-color: map.get(c.$c-player-bar, "play-background");
                color: map.get(c.$c-player-bar, "play-surface");
                box-shadow:
                    0 0 0.6em 0.1em map.get(c.$c-player-bar, "play-background"),
                    0 0 1.5em 0.25em map.get(c.$c-player-bar, "play-background");
            }
        }
    }
}
</style>
