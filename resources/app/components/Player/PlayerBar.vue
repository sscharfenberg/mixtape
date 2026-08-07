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
import { usePlayerShortcuts } from "Composables/usePlayerShortcuts";
import { usePlayerSpeed } from "Composables/usePlayerSpeed";

const { t } = useI18n();
// `hasNext` / `hasPrevious` come FROM the queue rather than being derived here, and that
// moved when shuffle arrived: under a random order the answers depend on the shuffle walk,
// which is the composable's private state — "is there a track behind this one" is no
// longer "is the index above zero".
const { current, hasNext, hasPrevious, next, previous } = usePlayerQueue();
const { isPlaying, currentTime, duration, buffered, attach, detach, toggle, seek } = usePlayerAudio();
// Bound here rather than in FullLayout, and that placement IS the scoping rule: FullLayout
// renders this bar with `v-if="current"`, so with an empty queue no document listener
// exists at all and Space scrolls the page exactly as it always did.
const { bind: bindShortcuts, unbind: unbindShortcuts } = usePlayerShortcuts();
// The badge reads the EFFECTIVE rate rather than the shortcut's skim flag, so it covers both
// ways of being off normal speed: a 3× chosen in the settings shows just as a held Space does.
// Which is the useful statement — "this is not playing at normal speed" — and the setting is
// the case a reader is more likely to have forgotten about.
const { effectiveRate } = usePlayerSpeed();

/** The bar element, measured to publish its height. */
const barRef = ref<HTMLElement | null>(null);

/**
 * A control's tooltip: what it does, then the key that does the same thing.
 *
 * The two halves come from the catalog separately because only the first is a sentence a
 * translator should be rewriting — "⇧→" is the same on every keyboard this app speaks to.
 * Joining them here rather than storing three combined strings means the parenthesis shape
 * is written once, and adding a key to a control is a call site rather than a new key.
 *
 * A plain function, not a computed: it takes arguments, and `t` is reactive, so the
 * template re-renders these on a locale switch anyway.
 */
const withKey = (label: string, key: string): string => `${label} (${key})`;

/** The one element in the app that makes sound. Handed to usePlayerAudio on mount. */
const audioRef = ref<HTMLAudioElement | null>(null);

/** Last height published, so an observation that changes nothing writes nothing. */
let publishedHeight = -1;

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
    bindShortcuts();

    /**
     * Measure the bar and publish it, only when the number actually moved.
     *
     * Same guard as AppHeader's and for the same reason: this value feeds `AppMain`'s bottom
     * padding and the queue panel's bottom edge, so it is part of the layout this observer
     * watches — re-publishing an unchanged measurement is how the pair starts feeding itself.
     * Not rounded, for the reason AppHeader documents at length (it puts a visible seam
     * between the panel and this bar).
     */
    const setHeightVar = (): void => {
        if (!barRef.value) return;

        const next = barRef.value.getBoundingClientRect().height;
        if (next === publishedHeight) return;

        publishedHeight = next;
        document.documentElement.style.setProperty("--app-player-height", `${next}px`);
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
    // With no bar there is nothing to control, so the document gets its keys back — Space
    // scrolls again the moment the queue is emptied.
    unbindShortcuts();
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

        <player-volume class="player-bar__volume" />

        <player-settings class="player-bar__settings" />

        <!-- The speed readout, shown whenever the player is off normal speed — a setting
             from the popover, or a held Space, or both at once (3× held reads 6×).
             TWO WORDINGS, and the split is the point. On screen it is the bare multiplier,
             because this is a glanceable badge over the page and a sentence at badge size
             reads as an alert rather than a readout. Aloud it is the whole phrase: nothing
             else announces the change at all — no focus moves, no control relabels — so a
             screen-reader user would otherwise get silence and a track suddenly running
             fast. `aria-live="polite"` rather than `assertive`: worth saying, not worth
             interrupting. -->
        <span v-if="effectiveRate !== 1" class="player-bar__rate" role="status" aria-live="polite">
            <span aria-hidden="true">{{ effectiveRate }}×</span>
            <span class="sr-only">{{ t("player.bar.rate", { rate: effectiveRate }) }}</span>
        </span>

        <div class="player-bar__transport">
            <!-- The tooltips name the key beside the label. `v-tooltip` directly on each
                 button rather than the Tooltip wrapper: there is an element to hang it on,
                 so the wrapper would only add a node (see UI/Tooltip's own note). The
                 `aria-label` stays the plain label — a keyboard hint is a visual
                 convenience, and reading "Nächster Titel Shift Pfeil rechts" aloud on every
                 focus is worse than not knowing the shortcut. -->
            <button
                v-tooltip="withKey(t('player.bar.previous'), t('player.bar.keys.shiftLeft'))"
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
                v-tooltip="
                    withKey(
                        isPlaying ? t('player.bar.pause') : t('player.bar.play'),
                        `${t('player.bar.keys.space')} — ${t('player.bar.keys.hold')}`
                    )
                "
                type="button"
                class="player-bar__control player-bar__control--play"
                :aria-label="isPlaying ? t('player.bar.pause') : t('player.bar.play')"
                @click="toggle"
            >
                <icon :name="isPlaying ? 'pause' : 'play'" :size="2" />
            </button>
            <button
                v-tooltip="withKey(t('player.bar.next'), t('player.bar.keys.shiftRight'))"
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
        "cover meta volume settings transport"
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
        grid-template-areas: "cover meta timeline volume settings transport";

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

    /* First of the two popover triggers, immediately after the timeline. */
    &__volume {
        grid-area: volume;
    }

    /* Last thing before the transport, and closest to it (the owner's placement): the two
       settings in it — what order the queue plays in, what happens at its end — are about
       what the skip buttons beside them will DO, so they sit next to those rather than out by
       the timeline. Reading order across the bar: position → level → order → the buttons. */
    &__settings {
        grid-area: settings;
    }

    &__transport {
        display: flex;
        align-items: center;
        grid-area: transport;

        gap: map.get(s.$c-player-bar, "gap");
    }

    /* The 2× skim readout, shown only while Space is held.
       ABSOLUTELY POSITIONED, and that is the whole design of it rather than a shortcut. It
       appears and disappears on a keypress, several times in a row while someone hunts
       through a track — so it must not be a grid item. This grid is laid out with named
       areas, so an unplaced child lands in an implicit row and grows the bar; even placed,
       it would shift the transport under a finger that is about to press play again, which
       is the same thing the play/pause button is one-button-for-both-states to avoid.
       Floating it clear of the bar's top edge costs the layout nothing at all.
       `pointer-events: none` because it is a readout: it sits over the page, and a badge
       that swallowed a click on whatever is behind it would be its own small bug.
       No transition on purpose — it is feedback for a key that is down RIGHT NOW, so it
       has to be there the instant the speed changes and gone the instant it does not.
       (Which also means no reduced-motion guard is needed.)
       The colours are the play button's own measured pair, read from the same map rather
       than minted here: the badge says the same thing that button does, and its fill was
       already proven for contrast against its ink. */
    &__rate {
        position: absolute;
        inset-block-end: calc(100% + #{map.get(s.$c-player-bar, "rate-offset")});
        inset-inline-end: map.get(s.$c-player-bar, "padding");

        padding: map.get(s.$c-player-bar, "rate-padding");

        background-color: map.get(c.$c-player-bar, "play-background");
        color: map.get(c.$c-player-bar, "play-surface");
        border-radius: 100vw;

        font-size: map.get(s.$c-player-bar, "rate-font-size");
        font-variant-numeric: tabular-nums;

        pointer-events: none;
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
