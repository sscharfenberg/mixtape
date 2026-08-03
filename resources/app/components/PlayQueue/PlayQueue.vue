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
 * CLICKING A ROW PLAYS THAT TRACK — anywhere in it, not just the cover. The row
 * stays an <li> holding one <button> whose hit area is stretched across it by a
 * pseudo-element, because a <button> may contain neither a link nor another
 * button and the row holds both. So the semantics are unchanged (one control, one
 * accessible name, "Play <track>") while the target is the full row. Two things
 * sit above that overlay and keep their own behaviour: the title, a real <Link>
 * to the song's page so "open this song" is still reachable by keyboard, and the
 * remove button. Everything else in the row — the artist line, the padding, the
 * gaps — plays. See `__load::after` in the styles for why a position, not merely
 * a z-index, is what lifts those two.
 *****************************************************************************/
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import PlayQueueMenu from "Components/PlayQueue/PlayQueueMenu.vue";
import Icon from "Components/UI/Icon.vue";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { usePlayQueuePanel } from "Composables/usePlayQueuePanel";
import { formatClock } from "Utils/formatting";

const { t } = useI18n();
const { tracks, currentIndex, isEmpty, totalDuration, jumpTo, remove } = usePlayerQueue();
const { play } = usePlayerAudio();
const { isOpen } = usePlayQueuePanel();

/**
 * Load the clicked row into the player AND start it.
 *
 * Both halves, because the row's label says "play this" — and because a click is a
 * user gesture, which is the only moment the browser will let playback begin. Loading
 * without playing would leave the listener pressing a second button for something
 * they already asked for, and by then the gesture is gone.
 *
 * `jumpTo` alone is enough when something is already playing (the player follows the
 * queue's pointer), so this exists for the paused case — and for the row that is
 * ALREADY loaded, where nothing changes for the player to react to.
 */
const playRow = (index: number): void => {
    jumpTo(index);
    play();
};

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
                        @click="playRow(index)"
                    >
                        <cover-image :src="track.coverUrl" :title="track.name" size="tiny" decorative />
                    </button>
                    <span class="play-queue__meta">
                        <!-- The title stays a real link, so the queue is also a way BACK to a
                             song's page — the row's own click is "play this", which cannot
                             double as "show me this". It sits ABOVE the load button's overlay
                             (see `__name` in the styles), which is the only reason it is still
                             clickable at all. -->
                        <Link :href="track.href" prefetch class="play-queue__name">{{ track.name }}</Link>
                        <span v-if="track.artist" class="play-queue__artist">{{ track.artist }}</span>
                    </span>
                    <button
                        type="button"
                        class="play-queue__remove"
                        v-tooltip="t('player.queue.removeHint')"
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

/* Clip-free room for the current row's glow, and the negative margin that pays
   for it — see `&__list`. Named once here because it appears twice and the two
   uses must never drift apart. */
$glow-room: map.get(s.$c-play-queue, "padding");

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

    /* THE WHOLE ROW PLAYS THE TRACK, and this pseudo-element is what makes it so.
       The button itself only wraps the 24px cover, which is a poor target for the
       primary action in the panel; a stretched `::after` grows the hit area to the
       full row without changing the markup's semantics — still one button with one
       accessible name, so a screen reader hears "Play <track>" exactly as before.

       It has to be done this way round rather than by wrapping the row in a
       <button>: the row also holds a real link (the title) and a second button
       (remove), and a <button> may contain neither. The overlay inverts the
       problem — one big transparent target, with the two genuine controls lifted
       above it.

       `inset: 0` resolves against `&__row`, which is the nearest positioned
       ancestor. That is what the row's `position: relative` is for. */
    &__load::after {
        position: absolute;
        inset: 0;

        content: "";
    }

    /* …and these two are lifted back above it. A POSITION IS REQUIRED, not just a
       z-index: an absolutely positioned pseudo-element paints above every
       non-positioned descendant of the same stacking context regardless of DOM
       order, so without this the overlay would silently swallow both the title
       link and the remove button — the row would play the track and nothing else
       in it would work.

       Deliberately NOT extended to `__meta` or `__artist`: the artist line should
       play the track like the rest of the row, so it stays under the overlay. */
    &__name,
    &__remove {
        position: relative;
        z-index: 1;
    }

    /* The list scrolls, not the panel: the header (with the clear button) has to
       stay reachable however long the queue gets.

       THE PADDING / NEGATIVE-MARGIN PAIR IS WHAT LETS THE CURRENT ROW GLOW AT
       ALL. A scroll container clips on BOTH axes: `overflow-y: auto` forces the
       other axis to `auto` as well (a lone `visible` is not honoured next to a
       scrolling one), so `overflow-x: visible` cannot be asked for and an outer
       box-shadow has nowhere to go. It survived only as fragments at the row's
       corners, with the first row's halo cut off flat against the top edge —
       which is the bug this pair fixes.

       The room has to be INSIDE the clip box, so the padding provides it and a
       negative margin of exactly the panel's own padding reclaims it: the list's
       scroll box grows to the panel's inner edge while every row stays precisely
       where it was. That last part is the whole point — at 240px the title is
       already the first thing to ellipsise (see sizes/components/_play-queue.scss),
       so buying glow room with row width is not a trade this panel can afford.

       It reads the panel's `padding` token rather than declaring a size of its
       own because the two are not merely equal, they must stay equal: the margin
       has to cancel that exact padding or the rows shift. */
    &__list {
        overflow-y: auto;

        flex: 1 1 auto;

        padding: $glow-room;

        margin: -$glow-room;

        list-style: none;
    }

    &__row {
        display: flex;
        position: relative;
        align-items: center;

        padding: map.get(s.$c-play-queue, "row", "padding");
        gap: map.get(s.$c-play-queue, "row", "gap");

        border-radius: map.get(s.$c-play-queue, "row", "radius");

        /* One cursor for the whole row, set here because `cursor` INHERITS —
           which is the only reason the artist line needed fixing at all. It is a
           bare <span>, so it fell through to `auto` and drew an I-beam over its
           glyphs, and a caret in the middle of a row you click to play reads as a
           row you can select text in. Declaring it on the row covers the padding,
           the gaps and both text lines in one place; the two buttons keep their
           own declaration, since "a button is clickable" is true on its own and
           should not depend on a rule further up. */
        cursor: pointer;

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
           effect constants, per the note in sizes/components/_button.scss.

           TIGHTER THAN THE DATATABLE'S, deliberately. That one is tuned for a row
           several hundred pixels wide with open page around it; the same 1.5em halo
           on a 240px row in a scrolling panel is both out of proportion and wider
           than any room the clip box can be given (`&__list` above caps it at the
           panel's padding). The outer layer is sized to land just inside that room,
           so the halo fades out on its own instead of being cut off flat. */
        &--current {
            background-color: map.get(c.$c-play-queue, "current-background");
            box-shadow:
                0 0 0.25em 0.04em map.get(c.$c-play-queue, "current-glow"),
                0 0 0.4em 0.08em map.get(c.$c-play-queue, "current-glow");
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
