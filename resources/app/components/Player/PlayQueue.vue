<script setup lang="ts">
/******************************************************************************
 * PlayQueue
 * The queue panel: what is lined up to play, in order, with the loaded track
 * marked. Mounted ONCE in FullLayout alongside the PlayerBar — the queue has to
 * outlive the page, and a component inside a page would be torn down and rebuilt
 * on every Inertia navigation.
 *
 * It renders NOTHING while the queue is empty, which is the whole reason the
 * layout asks `isEmpty` rather than letting this decide: an empty grid column
 * would still reserve its 240px, and the page would sit off-centre for a queue
 * that isn't there.
 *
 * Two shapes, one component. On a wide screen it is a column beside the content,
 * stuck under the header with its own scroll — a queue that scrolls off the top
 * of a long album page is a queue you cannot reach. Below the `landscape` step
 * there is no width to give it, so it becomes a bottom sheet over the lower half
 * of the viewport instead, sitting above <main> but under the PlayerBar (which
 * shares its z-rung and follows it in the DOM).
 *
 * A row is a <button>, not a link: clicking it loads that track into the player
 * rather than navigating anywhere. The title is separately a real <Link> to the
 * track's page, so "open this song" is still reachable — by keyboard too.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import Icon from "Components/UI/Icon.vue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { formatClock } from "Utils/formatting";

const { t } = useI18n();
const { tracks, currentIndex, isEmpty, totalDuration, jumpTo, remove, clear } = usePlayerQueue();

/**
 * The queue's running time as a clock, for the panel header.
 *
 * A total rather than a per-row duration because at 240px a row has no space for
 * one — see the width note in sizes/components/_play-queue.scss.
 */
const totalClock = computed(() => formatClock(totalDuration.value));
</script>

<template>
    <aside v-if="!isEmpty" class="play-queue" :aria-label="t('player.queue.label')">
        <header class="play-queue__header">
            <h2 class="play-queue__title">
                <icon name="playlist" :size="1" />
                {{ t("player.queue.label") }}
            </h2>
            <button
                type="button"
                class="play-queue__clear"
                v-tooltip="t('player.queue.clear')"
                :aria-label="t('player.queue.clear')"
                @click="clear"
            >
                <icon name="delete" :size="1" />
            </button>
        </header>
        <!-- Count and running time on their own line rather than beside the title:
             at 240px a single row cannot hold the word "Warteschlange", two numbers
             and a button without ellipsising the one part that names the panel. -->
        <p class="play-queue__summary">{{ t("player.queue.summary", tracks.length) }} · {{ totalClock }}</p>

        <ol class="play-queue__list">
            <li
                v-for="(track, index) in tracks"
                :key="`${track.id}-${index}`"
                class="play-queue__row"
                :class="{ 'play-queue__row--current': index === currentIndex }"
                :aria-current="index === currentIndex ? 'true' : undefined"
            >
                <button
                    type="button"
                    class="play-queue__load"
                    :aria-label="t('player.queue.load', { name: track.name })"
                    @click="jumpTo(index)"
                >
                    <cover-image :src="track.coverUrl" :title="track.name" size="tiny" decorative />
                </button>
                <span class="play-queue__meta">
                    <!-- The title stays a real link, so the queue is also a way BACK to a
                         song's page — the row's own click is "play this", which cannot
                         double as "show me this". -->
                    <Link :href="track.href" prefetch class="play-queue__name">{{ track.name }}</Link>
                    <span v-if="track.artist" class="play-queue__artist">{{ track.artist }}</span>
                </span>
                <button
                    type="button"
                    class="play-queue__remove"
                    :aria-label="t('player.queue.remove', { name: track.name })"
                    @click="remove(index)"
                >
                    <icon name="close" :size="1" />
                </button>
            </li>
        </ol>
    </aside>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

/* Mobile first, and the two forms are genuinely different boxes rather than one
   box with a different width. Narrow: a bottom sheet fixed over the lower half of
   the viewport, sitting on top of the page because there is nowhere to put it
   beside. Wide (`landscape` and up): an ordinary grid column that sticks under the
   header and scrolls internally.

   `--app-player-height` is published by PlayerBar; the fallback matters because the
   bar only exists once a track is loaded, and the queue can be open before then. */
.play-queue {
    display: flex;
    position: fixed;
    inset: auto 0 var(--app-player-height, 0) 0;
    z-index: z.$c-play-queue;
    flex-direction: column;

    box-sizing: border-box;
    height: map.get(s.$c-play-queue, "sheet-height");
    padding: map.get(s.$c-play-queue, "padding");
    border-top: map.get(s.$c-play-queue, "border") solid map.get(c.$c-play-queue, "border");

    gap: map.get(s.$c-play-queue, "gap");

    background-color: map.get(c.$c-play-queue, "background");
    color: map.get(c.$c-play-queue, "surface");

    @include m.mq("landscape") {
        position: sticky;
        inset: auto;
        top: var(--app-header-height, 0);

        width: map.get(s.$c-play-queue, "width");
        height: auto;

        // Fill what is left between the header and the player, and no more, so the
        // panel's own list is what scrolls rather than the window.
        max-height: calc(100dvh - var(--app-header-height, 0px) - var(--app-player-height, 0px));
        border: map.get(s.$c-play-queue, "border") solid map.get(c.$c-play-queue, "border");

        border-radius: map.get(s.$c-play-queue, "radius");
    }

    &__header {
        display: flex;
        align-items: center;

        gap: map.get(s.$c-play-queue, "header-gap");
    }

    &__title {
        display: flex;
        align-items: center;

        flex: 1 1 auto;

        margin: 0;
        gap: map.get(s.$c-play-queue, "header-gap");

        font-size: 1rem;
    }

    &__summary {
        margin: 0;

        color: map.get(c.$c-play-queue, "muted");

        font-size: 0.85em;
        font-variant-numeric: tabular-nums;
    }

    &__clear,
    &__remove,
    &__load {
        display: inline-flex;
        align-items: center;

        padding: 0;
        border: 0;

        background: none;
        color: map.get(c.$c-play-queue, "control");

        cursor: pointer;

        @media (prefers-reduced-motion: no-preference) {
            transition: color ti.$c-play-queue linear;
        }

        &:hover {
            color: map.get(c.$c-play-queue, "control-hover");
        }
    }

    /* The list scrolls, not the panel: the header (with the clear button) has to
       stay reachable however long the queue gets. */
    &__list {
        overflow-y: auto;

        flex: 1 1 auto;

        padding: 0;

        margin: 0;

        list-style: none;
    }

    &__row {
        display: flex;
        position: relative;
        align-items: center;

        padding: map.get(s.$c-play-queue, "row", "padding");
        gap: map.get(s.$c-play-queue, "row", "gap");

        border-radius: map.get(s.$c-play-queue, "row", "radius");

        @media (prefers-reduced-motion: no-preference) {
            transition:
                background-color ti.$c-play-queue ease-out,
                box-shadow ti.$c-play-queue ease-out;
        }

        &:hover {
            background-color: map.get(c.$c-play-queue, "row-hover");
        }

        /* The loaded track wears the house "this one is live" treatment — the same
           two-layer neon halo the DataTable's hovered row and an open popover use,
           over a low-alpha fill of the same colour. The glow spreads are em-based
           effect constants, per the note in sizes/components/_button.scss. */
        &--current {
            background-color: map.get(c.$c-play-queue, "current-background");
            box-shadow:
                0 0 0.6em 0.1em map.get(c.$c-play-queue, "current-glow"),
                0 0 1.5em 0.25em map.get(c.$c-play-queue, "current-glow");
        }
    }

    /* `min-width: 0` is what lets the two lines below ellipsise. Without it this
       flex item refuses to shrink under its content width, an unbreakable title
       pushes the row wider than the 240px panel, and `text-overflow` never fires
       because nothing is overflowing. Same trap as the breadcrumb's label. */
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
            transition: color ti.$c-play-queue linear;
        }

        &:hover {
            color: map.get(c.$c-play-queue, "current-glow");
        }
    }

    &__artist {
        color: map.get(c.$c-play-queue, "muted");

        font-size: 0.85em;
    }
}
</style>
