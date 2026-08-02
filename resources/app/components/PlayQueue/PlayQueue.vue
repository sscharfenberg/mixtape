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
 * One shape, two behaviours. From the `landscape` step up it is a column beside the
 * content, stuck under the header with its own scroll — a queue that scrolls off
 * the top of a long album page is a queue you cannot reach. Below that there is no
 * room to put a 240px column beside anything, so the same panel, in the same place,
 * floats OVER the content instead, and is shown only while PlayQueueToggle (in the
 * header) says so.
 *
 * Overlaying rather than pushing is what keeps the page usable at that width: a
 * column would leave a phone barely 150px of content, and a bottom sheet — which
 * this was first — permanently ate half the viewport and had to be scrolled past.
 * As an overlay it is on screen exactly while it is wanted and gone otherwise.
 *
 * A row is a <button>, not a link: clicking it loads that track into the player
 * rather than navigating anywhere. The title is separately a real <Link> to the
 * track's page, so "open this song" is still reachable — by keyboard too.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import PlayQueueMenu from "Components/PlayQueue/PlayQueueMenu.vue";
import Icon from "Components/UI/Icon.vue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { usePlayQueuePanel } from "Composables/usePlayQueuePanel";
import { formatClock } from "Utils/formatting";

const { t } = useI18n();
const { tracks, currentIndex, isEmpty, totalDuration, jumpTo, remove } = usePlayerQueue();
const { isOpen } = usePlayQueuePanel();

/**
 * The queue's running time as a clock, for the panel header.
 *
 * A total rather than a per-row duration because at 240px a row has no space for
 * one — see the width note in sizes/components/_play-queue.scss.
 */
const totalClock = computed(() => formatClock(totalDuration.value));
</script>

<template>
    <aside
        v-if="!isEmpty"
        class="play-queue"
        :class="{ 'play-queue--open': isOpen }"
        :aria-label="t('player.queue.label')"
    >
        <header class="play-queue__header">
            <h2 class="play-queue__title">
                <icon name="playlist" :size="1" />
                {{ t("player.queue.label") }}
            </h2>
            <play-queue-menu />
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

/* One box, positioned the same way at every width — top-right, under the header,
   240px wide, scrolling internally. What changes below `landscape` is only whether
   it takes part in the layout: there it is `fixed`, floating over the content and
   hidden until the header's toggle opens it, where from `landscape` up it is
   `sticky` and simply occupies its grid column.

   `--app-header-height` and `--app-player-height` are published by AppHeader and
   PlayerBar. The player fallback matters: the bar only exists once a track is
   loaded, and the queue can be open before then.

   It sits on the "sticky" rung, above <main> ("raised") so the overlay covers the
   page, and before PlayerBar in the DOM so the bar stays reachable over it. */
.play-queue {
    display: none;
    position: fixed;
    top: var(--app-header-height, 0);
    right: 0;

    /* A pixel PAST the bar rather than exactly against it. The bar's height is
       fractional — a 48px cover plus rem padding on this app's fluid root font
       size works out to 61.59px — so an exact join lands mid-device-pixel, and the
       two fixed elements get snapped to different sides of it: the panel's side
       borders stopped a hair short and a sliver of the page showed through the
       seam. Overlapping is free here because the bar comes later in the DOM on the
       same z-rung, so it paints over what it covers. */
    bottom: var(--app-player-height, 0);
    z-index: z.$c-play-queue;
    flex-direction: column;

    box-sizing: border-box;
    width: map.get(s.$c-play-queue, "width");
    padding: map.get(s.$c-play-queue, "padding");

    /* SIDES ONLY, and no rounding. As an overlay the panel spans the whole gap
       between the header and the player bar, meeting both — a top or bottom edge
       would draw a second line a pixel from theirs, which reads as a seam rather
       than a frame, and a rounded corner against either would be a notch showing
       the page through. `border-inline` states that directly instead of setting
       four borders and unsetting two. The `landscape` rule below puts the bottom
       one back, where the panel ends in open space. */
    border-inline: map.get(s.$c-play-queue, "border") solid map.get(c.$c-play-queue, "border");

    gap: map.get(s.$c-play-queue, "gap");

    background-color: map.get(c.$c-play-queue, "background");
    color: map.get(c.$c-play-queue, "surface");

    // Open on a narrow screen: the toggle's doing. `display` rather than a
    // transform, so a closed panel costs no paint and is out of the tab order.
    &--open {
        display: flex;
    }

    @include m.mq("landscape") {
        display: flex;
        position: sticky;
        right: auto;
        bottom: auto;

        // Fill what is left between the header and the player, and no more, so the
        // panel's own list is what scrolls rather than the window.
        max-height: calc(100dvh - var(--app-header-height, 0px) - var(--app-player-height, 0px));

        /* A bottom edge again, and the corners to go with it. Here the panel is only
           as tall as the queue needs, so it ends in open space partway down the page
           rather than against the player bar — an edge there is the box closing
           itself, not a duplicate of somebody else's. The top stays open for the
           reason given above: it is still stuck to the header. */
        border-bottom: map.get(s.$c-play-queue, "border") solid map.get(c.$c-play-queue, "border");

        border-radius: 0 0 map.get(s.$c-play-queue, "radius") map.get(s.$c-play-queue, "radius");
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
