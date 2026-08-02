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
 * It OVERLAYS at every width rather than taking a column, and <main> keeps the full
 * window. That is not a visual preference: the app's headings are drawn as tabs with
 * one side deliberately open, hidden past the edge of the screen, and a <main> that
 * stops short of the window leaves that opening in plain view on all 21 pages that
 * use one. Nothing inside the page should have to know the queue exists — so the
 * queue floats above it and Container carries the inset that keeps content clear.
 *
 * What changes with width is only whether it is on screen: from `landscape` up it is
 * always there, and below that PlayQueueToggle (in the header) opens it, because
 * 240px of a phone is most of the screen and a queue you carry around open is one
 * permanently in the way.
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
    <div v-if="!isEmpty" class="play-queue-layer" :class="{ 'play-queue-layer--open': isOpen }">
            <aside class="play-queue" :aria-label="t('player.queue.label')">
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
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;
@use "Abstracts/z-indexes" as z;

/* THE LAYER is what makes the panel line up, and it is why there is an element
   here that draws nothing. The panel has to end exactly where the app's content
   cage ends — the same line the header's inner row finishes on — and the obvious
   way to say that, `right: max(0px, (100vw - cage) / 2)`, is wrong by half the
   scrollbar: `100vw` counts it, the cage does not. That is the misalignment this
   layout has already been caught out by once.

   A FIXED element's containing block is the layout viewport, which excludes the
   scrollbar. So a fixed layer with `left: 0; right: 0; max-width: cage;
   margin-inline: auto` centres itself on exactly the same line Container does, and
   the panel simply pins to its trailing edge. No viewport arithmetic anywhere.

   It spans header to player bar (`--app-header-height` / `--app-player-height`,
   published by those two components) and passes clicks through, since it is a
   coordinate system rather than a surface. It sits on the "sticky" rung, above
   <main> ("raised"), and before PlayerBar in the DOM so the bar paints over it. */
.play-queue-layer {
    display: none;
    position: fixed;
    inset: var(--app-header-height, 0) 0 var(--app-player-height, 0) 0;
    z-index: z.$c-play-queue;

    box-sizing: border-box;
    max-width: map.get(s.$c-app, "max");
    margin-inline: auto;

    pointer-events: none;

    // Below `landscape` the panel is opened by the header's toggle; from there up
    // it is simply always there. `display` rather than a transform, so a closed
    // panel costs no paint and is out of the tab order.
    &--open {
        display: block;
    }

    @include m.mq("landscape") {
        display: block;
    }
}

/* The panel itself, pinned to the layer's trailing edge. Full height on a narrow
   screen — it is an overlay with nothing else to share the space with — and only
   as tall as the queue needs from `landscape` up, where it sits beside the page. */
.play-queue {
    display: flex;
    position: absolute;
    inset: 0 0 0 auto;
    flex-direction: column;

    box-sizing: border-box;
    width: map.get(s.$c-play-queue, "width");
    padding: map.get(s.$c-play-queue, "padding");

    /* SIDES ONLY, and no rounding. As a full-height overlay the panel meets the
       header and the player bar — a top or bottom edge would draw a second line a
       pixel from theirs, which reads as a seam rather than a frame, and a rounded
       corner against either would be a notch showing the page through. The
       `landscape` rule below puts the bottom one back, where the panel ends in
       open space instead. */
    border-inline: map.get(s.$c-play-queue, "border") solid map.get(c.$c-play-queue, "border");

    gap: map.get(s.$c-play-queue, "gap");

    background-color: map.get(c.$c-play-queue, "background");
    color: map.get(c.$c-play-queue, "surface");

    pointer-events: auto;

    @include m.mq("landscape") {
        // Only as tall as its contents, up to the space the layer gives it; the
        // panel's own list is then what scrolls, rather than the window.
        inset: 0 0 auto auto;

        max-height: 100%;

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
