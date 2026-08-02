<script setup lang="ts">
/******************************************************************************
 * PlayerBar
 * The transport bar that takes the footer's place once the queue has a track
 * loaded: cover, what is playing, and the controls for moving through the queue.
 * Mounted ONCE in FullLayout, and that is not a style choice — the audio element
 * this will eventually own has to survive an Inertia page swap, and anything
 * inside a page is destroyed and rebuilt on every navigation.
 *
 * A SHELL, deliberately. There is no <audio> here and no stream route behind it
 * yet, because the choice between vidstack and a native element is still open and
 * it decides a lot (how seeking works, whether the production CSP needs
 * `media-src blob:`, and how much of a stream endpoint we need). So play/pause is
 * inert; skip forward and back are real, because they are pure queue operations
 * and the queue exists.
 *
 * It shows on "the queue has a current track", NOT on "audio is playing". A paused
 * player has to stay on screen — a bar that vanished when you hit pause would take
 * the play button with it — and it means the bar works before any audio does.
 *
 * The bar is FIXED rather than sitting where the footer sat in the flow: you have
 * to be able to reach pause from halfway down a long album. That is also why it
 * publishes its height as `--app-player-height` (the mirror of AppHeader's
 * `--app-header-height`) — a fixed bar is out of the flow, so <main> and the queue
 * panel have to reserve the space themselves.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed, onMounted, onUnmounted } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import Icon from "Components/UI/Icon.vue";
import { usePlayerQueue } from "Composables/usePlayerQueue";

const { t } = useI18n();
const { tracks, currentIndex, current, next, previous } = usePlayerQueue();

/** Whether stepping back is possible — the first track in the queue has nowhere to go. */
const hasPrevious = computed(() => currentIndex.value > 0);
/** Whether stepping forward is possible. Repeat-all will relax this once it exists. */
const hasNext = computed(() => currentIndex.value > -1 && currentIndex.value < tracks.value.length - 1);

/**
 * Publish the bar's rendered height to the document as `--app-player-height`.
 *
 * The same trick AppHeader uses for `--app-header-height`, and needed for the same
 * reason in reverse: the bar is fixed, so it is out of the flow and would sit on
 * top of the last rows of a page. <main> pads its bottom by this, and the queue
 * panel measures its own height against it.
 *
 * Set and cleared on mount/unmount rather than measured continuously — the bar's
 * height is a token (s.$c-player-bar "height"), not something that reflows — but
 * read from the real element anyway, so the CSS stays the single source of truth.
 */
onMounted(() => {
    const bar = document.querySelector(".player-bar");
    if (bar) {
        document.documentElement.style.setProperty("--app-player-height", `${bar.getBoundingClientRect().height}px`);
    }
});

// Clear it when the queue empties and the footer comes back, or <main> keeps
// reserving room for a bar that is no longer there.
onUnmounted(() => {
    document.documentElement.style.removeProperty("--app-player-height");
});
</script>

<template>
    <div v-if="current" class="player-bar" :aria-label="t('player.bar.label')" role="region">
        <cover-image :src="current.coverUrl" :title="current.name" size="small" decorative />

        <span class="player-bar__meta">
            <Link :href="current.href" prefetch class="player-bar__name">{{ current.name }}</Link>
            <span v-if="current.artist" class="player-bar__artist">{{ current.artist }}</span>
        </span>

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
            <!-- Inert until the audio element exists — see the component note. It is
                 rendered rather than hidden so the bar's shape is the real one, and
                 `disabled` is what says so honestly to a screen reader. -->
            <button
                type="button"
                class="player-bar__control player-bar__control--play"
                disabled
                :aria-label="t('player.bar.play')"
            >
                <icon name="play" :size="2" />
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
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

/* Fixed to the bottom of the viewport, and frosted like the header — the two are
   the same family of affixed chrome, and the blur is why the background is an
   alpha-adjusted grey rather than a solid one. */
.player-bar {
    display: flex;
    position: fixed;
    inset: auto 0 0;
    z-index: z.$c-player-bar;
    align-items: center;

    box-sizing: border-box;
    height: map.get(s.$c-player-bar, "height");
    padding: map.get(s.$c-player-bar, "padding");
    border-top: map.get(s.$c-player-bar, "border") solid map.get(c.$c-player-bar, "border");
    gap: map.get(s.$c-player-bar, "gap");

    background-color: map.get(c.$c-player-bar, "background");
    backdrop-filter: blur(12px);
    color: map.get(c.$c-player-bar, "surface");

    /* Same `min-width: 0` trap as the queue row: without it a long title refuses to
       shrink, pushes the transport controls off the right edge, and never
       ellipsises because nothing overflows. */
    &__meta {
        display: flex;
        flex-direction: column;

        min-width: 0;
        flex: 1 1 auto;
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

    &__transport {
        display: flex;
        align-items: center;

        gap: map.get(s.$c-player-bar, "gap");
    }

    &__control {
        display: inline-flex;
        align-items: center;

        padding: 0;
        border: 0;

        background: none;
        color: map.get(c.$c-player-bar, "control");

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition: color ti.$c-player-bar linear;
        }

        &:hover:not(:disabled) {
            color: map.get(c.$c-player-bar, "control-hover");
        }

        // Both ends of the queue, and the not-yet-wired play button. Muted rather
        // than hidden, so the bar keeps its shape and the control stays where the
        // reader will look for it once it works.
        &:disabled {
            color: map.get(c.$c-player-bar, "muted");

            cursor: default;
        }
    }
}
</style>
