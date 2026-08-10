<script setup lang="ts">
/******************************************************************************
 * QueueList
 * The queue's rows — the one definition of them, shared by the panel that slides in from the
 * header and by the Now Playing page's fourth row.
 *
 * IT WAS TWO COMPONENTS FOR ABOUT AN HOUR, and the argument for that was wrong in a way worth
 * recording: the panel is 280px and its row is tuned for that width, so a second presentation
 * looked like the honest answer. What it actually produced was two rows that had to be kept in
 * step by hand, and they were not — the page's copy had a plain close glyph where the panel used
 * `playlist_remove`, which is the first thing the owner noticed. One row, two containers.
 *
 * THE CONTAINER IS THE ONLY DIFFERENCE, and it is a real one, which is why `layout` exists:
 *
 *   - `panel` SCROLLS. The header (with the clear menu) has to stay reachable however long the
 *     queue gets, so the list is the scroll box and the panel is not. That brings the
 *     padding/negative-margin pair with it — see the styles.
 *   - `page` does NOT scroll; the page does. Instead it lays the rows out in a GRID that becomes
 *     two columns when there is room for two of at least the panel's own width, and never more
 *     than two: a queue is read down, and four columns of it is a table nobody asked for. Those
 *     two columns are filled DOWNWARDS — first half left, second half right — which is what
 *     `rowsPerColumn` is for.
 *
 * Everything else — the row, its three controls, the drag, the keyboard move, the scroll-to-
 * current — is identical in both, and now identical by construction rather than by matching two
 * files.
 *
 * `useQueueReorder` takes the list element as an argument rather than being a singleton, so the
 * panel and the page each get their own Sortable over their own <ol> when both are mounted. Both
 * go through the one `reorder()`, which is what keeps them agreeing.
 *
 * THE CLASS NAMES STAY `play-queue__*` even on the page. They are what a dozen E2E specs already
 * name, and renaming them to something layout-neutral would be a large diff whose only effect is
 * to make those specs wrong.
 *****************************************************************************/
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import Icon from "Components/UI/Icon.vue";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { useQueueReorder } from "./useQueueReorder";

withDefaults(
    defineProps<{
        /**
         * Which container the rows sit in — see the banner. `panel` is the scrolling sidebar,
         * `page` the two-column grid on Now Playing.
         */
        layout?: "panel" | "page";
    }>(),
    { layout: "panel" }
);

const { t } = useI18n();
const { tracks, currentIndex, jumpTo, remove } = usePlayerQueue();
const { play } = usePlayerAudio();

/** The <ol>. Held to find the loaded row, to publish its height, and for Sortable to mount on. */
const list = ref<HTMLOListElement | null>(null);

const { onRowKeydown, onGripPointerdown, shortcutLabel } = useQueueReorder(list);

/**
 * How many rows a column holds where the page draws two of them — half the queue, rounded up.
 *
 * IT IS WHAT MAKES THE COLUMNS READ DOWNWARDS. A two-column grid deals its items ACROSS by default,
 * so tracks 1 and 2 sat side by side and the running order zig-zagged — which is exactly how the
 * owner described it reading wrong. `grid-auto-flow: column` fills the first column before starting
 * the second, but it needs to be told where a column ENDS, and CSS cannot count the children: hence
 * this number, published as a custom property and consumed inside the two-column media query only.
 * At one column it is simply unused.
 *
 * Never below one, because `repeat(0, auto)` is invalid CSS — and an invalid `grid-template-rows`
 * would be dropped altogether, leaving `auto-flow: column` to lay the whole queue out as one row.
 */
const rowsPerColumn = computed(() => Math.max(1, Math.ceil(tracks.value.length / 2)));

/**
 * Load the clicked row into the player AND start it.
 *
 * Both halves, because the row's label says "play this" — and because a click is a user gesture,
 * which is the only moment the browser will let playback begin. `jumpTo` alone is enough when
 * something is already playing (the player follows the queue's pointer), so this exists for the
 * paused case — and for the row that is ALREADY loaded, where nothing changes for the player to
 * react to.
 */
function playRow(index: number): void {
    jumpTo(index);
    play();
}

/**
 * Bring the loaded track into view, one row clear of the edge it approached.
 *
 * It exists because the pointer moves without anyone touching the list: next/prev, auto-advance
 * at the end of a track, the repeat wrap. Any of those can leave the row that is now playing
 * off-screen, and a queue showing the wrong part of itself is worse than one that scrolls under
 * you.
 *
 * THE ARITHMETIC IS THE BROWSER'S, not ours. `scroll-margin-block` grows the row's scroll box by
 * one row on each edge and `block: "nearest"` then scrolls only when that grown box does not fit
 * — which is both "leave a row of context" and "leave an already-visible row alone", without this
 * function comparing a single rectangle. The margin is MEASURED rather than tokenised because
 * rows are not one height: a track whose file carried no artist has one line, not two. It is
 * published as a custom property so the declaration itself stays in the stylesheet.
 *
 * Smoothness is deliberately absent here — `scroll-behavior` on the list carries it, under the
 * repo's `prefers-reduced-motion: no-preference` guard, so the motion decision sits in CSS with
 * every other one. `scrollIntoView` with no `behavior` honours that computed value.
 *
 * `flush: "post"` because the row has to exist first: queueing a track and jumping to it in one
 * tick would otherwise look for a child the DOM has not been given yet.
 */
function scrollCurrentIntoView(): void {
    const row = list.value?.children.item(currentIndex.value) as HTMLElement | null;

    if (!row) return;

    list.value?.style.setProperty("--queue-row-height", `${row.offsetHeight}px`);
    row.scrollIntoView({ block: "nearest", inline: "nearest" });
}

watch(currentIndex, scrollCurrentIntoView, { flush: "post" });

/*
 * EXPOSED FOR ONE CALLER AND ONE CASE: the panel is `display: none` while shut on a phone, and
 * scrolling a hidden element does nothing — so several tracks may have gone by unscrolled by the
 * time it is opened. Only the panel knows it has just opened, and only this component holds the
 * list, so the verb crosses that seam. The same shape PlaylistTracks uses for its sort.
 */
defineExpose({ scrollCurrentIntoView });
</script>

<template>
    <ol
        ref="list"
        class="play-queue__list"
        :class="`play-queue__list--${layout}`"
        :style="{ '--queue-rows': rowsPerColumn }"
    >
        <li
            v-for="(track, index) in tracks"
            :key="`${track.id}-${index}`"
            class="play-queue__row"
            :class="{ 'play-queue__row--current': index === currentIndex }"
            :aria-current="index === currentIndex ? 'true' : undefined"
            @keydown="onRowKeydown($event, index)"
        >
            <!-- Empty on purpose: this button IS the row's hit area (see the styles), and its
                 accessible name comes from the label rather than from any content. It has nothing
                 inside it because everything visible in the row is either one of the two controls
                 that must stay above it, or text that should play the track when clicked. -->
            <button
                type="button"
                class="play-queue__load"
                :aria-label="t('player.queue.load', { name: track.name })"
                @click="playRow(index)"
            ></button>
            <!-- The drag handle, and the only thing Sortable will start a drag from. The cover is
                 INSIDE it so the grip is a 24px-wide strip rather than a lone 16px glyph.

                 THE HINT SAYS "CLICK IT FIRST", and it has to: the keyboard alternative moves the
                 FOCUSED row, so hovering one and pressing the keys does nothing at all — which is
                 exactly how it read as broken. The keys are named for the keyboard in front of the
                 reader (⌥ on a Mac), while `aria-keyshortcuts` keeps ARIA's canonical spelling,
                 which is what assistive tech expects to parse and announce in its own words. The
                 handler itself sits on the <li> — keydown bubbles, so it works from any of the
                 row's three controls. -->
            <button
                type="button"
                class="play-queue__grip"
                v-tooltip="t('player.queue.moveHint', { keys: shortcutLabel })"
                :aria-label="t('player.queue.move', { name: track.name })"
                aria-keyshortcuts="Alt+ArrowUp Alt+ArrowDown"
                @pointerdown="onGripPointerdown"
            >
                <cover-image :src="track.coverUrl" :title="track.name" size="tiny" decorative />
                <icon name="drag" :size="0" />
            </button>
            <span class="play-queue__meta">
                <!-- Both lines are plain text, deliberately. The title used to be a real <Link> to
                     the song's page, and in a list whose every other pixel plays the track that is
                     a trap: the one spot a listener actually aims for was the one spot that
                     navigated away instead. So it stays under the load button's overlay and plays
                     like the rest of the row. -->
                <span class="play-queue__name">{{ track.name }}</span>
                <span v-if="track.artist" class="play-queue__artist">{{ track.artist }}</span>
            </span>
            <button
                type="button"
                class="play-queue__remove"
                v-tooltip="t('player.queue.removeHint')"
                :aria-label="t('player.queue.remove', { name: track.name })"
                @click="remove(index)"
            >
                <icon name="playlist_remove" :size="1" />
            </button>
        </li>
    </ol>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/colors" as c;
@use "Abstracts/sizes" as s;
@use "Abstracts/timings" as ti;

/* The panel's own padding, as the amount the list bleeds outward to reach the panel's inner edge
   — always paired with an equal padding that puts the rows back where they were. See
   `--panel` below for what it buys. */
$bleed: map.get(s.$c-play-queue, "padding");

/* WHERE A SECOND COLUMN BECOMES POSSIBLE: two panels' worth of width. Computed from the token
   rather than written as a number, because "at least as wide as the panel" is the rule the owner
   asked for and the panel's width is a token that may move.

   THE GAP IS NOT ADDED, and cannot be: it is a `rem` and the width is `px`, which Sass refuses to
   add — correctly, since it cannot know the root font size. Left out rather than worked around
   with `calc()` in a media query (which is not allowed either): the threshold is then 8px optimistic
   at the default root size, and the columns are `1fr` so they simply share what is there. Being a
   few pixels early to two columns is invisible; being unable to express the rule at all is not. */
$two-columns: map.get(s.$c-play-queue, "width") * 2;

.play-queue__list {
    padding: 0;
    margin: 0;

    list-style: none;

    /* Carries the smoothness for the scroll-to-current above, which passes no `behavior` of its
       own so that this decision lives here with the rest of the motion. `scroll-behavior` affects
       PROGRAMMATIC scrolls only — a wheel or a drag is untouched by it, so nobody's own scrolling
       is being animated. */
    @media (prefers-reduced-motion: no-preference) {
        scroll-behavior: smooth;
    }
}

/* THE PANEL: the list scrolls, not the panel, so the header (with the clear menu) stays reachable
   however long the queue gets.

   THE PADDING / NEGATIVE-MARGIN PAIR IS WHAT LETS THE CURRENT ROW GLOW AT ALL. A scroll container
   clips on BOTH axes: `overflow-y: auto` forces the other axis to `auto` as well (a lone
   `visible` is not honoured next to a scrolling one), so `overflow-x: visible` cannot be asked
   for and an outer box-shadow has nowhere to go. It survived only as fragments at the row's
   corners, with the first row's halo cut off flat against the top edge — which is the bug this
   pair fixes.

   The room has to be INSIDE the clip box, so the padding provides it and a negative margin of
   exactly the panel's own padding reclaims it: the list's scroll box grows to the panel's inner
   edge while every row stays precisely where it was. That last part is the whole point — at 280px
   the title is already the first thing to ellipsise, so buying glow room with row width is not a
   trade this panel can afford.

   It reads the panel's `padding` token rather than declaring a size of its own because the two
   are not merely equal, they must stay equal: the margin has to cancel that exact padding or the
   rows shift. */
.play-queue__list--panel {
    /* NO `display: flex` HERE, and that is load-bearing rather than an omission. The rows carry
       `content-visibility: auto` with an intrinsic-size estimate, and as flex items their skipped
       height is resolved differently — the list's `scrollHeight` came out 6,788px wrong on a
       2,000-track queue, which is a scrollbar lying about how much queue there is. Plain
       block-level <li>s stack correctly and the estimate holds. Caught by
       queue.spec.ts → "skips the rows nobody is looking at". */
    overflow-y: auto;

    flex: 1 1 auto;

    padding: $bleed;

    margin: -$bleed;
}

/* THE PAGE: no scroll box of its own — the page scrolls — and therefore none of the bleed
   machinery above, because there is nothing clipping the glow.

   ONE COLUMN, THEN TWO, AND NEVER MORE. `auto-fit` was the obvious way to say it and is wrong
   here: on a desktop it would give four columns of 280px, and a queue read four abreast is a
   table rather than a running order. Two is the owner's brief, and the breakpoint is exactly
   "there is room for two at the panel's own width" — which is also the fallback to one column on
   anything narrower, since below that threshold this rule is all there is.

   THE TWO COLUMNS ARE READ DOWN, NOT ACROSS (2026-08-10). A grid deals items across its columns by
   default, so tracks 1 and 2 were neighbours and the running order zig-zagged — the owner's words
   were that it "reads weird", and a numbered list you have to zig-zag to follow is exactly that.
   `grid-auto-flow: column` over an explicit row count fills the left column with the first half and
   the right with the second; the count comes from the component (`rowsPerColumn`), because CSS
   cannot count children. `repeat(var(…))` is substituted before the value is parsed, so this is a
   real row template rather than a trick.

   EVERY TRACK IS `minmax(0, 1fr)`, NEVER A BARE `1fr`, and this is the one line here that a long
   title will punish you for getting wrong. `1fr` means `minmax(auto, 1fr)`, and that `auto` floor is
   the track's MIN-CONTENT width — so one unbreakable 180-character title stops being a thing that
   ellipsises and becomes a thing the column has to fit. Measured before the fix, at a 1280px window:
   the two columns went from 586.5px each to **1470.75px and 219.64px**, and the list's scrollWidth
   (1714px) burst out of its box (1197px), taking the alignment of every other row with it. `minmax(0,
   …)` gives the track permission to be narrower than its content, which is what lets the ellipsis in
   `.play-queue__name` do its job. The one-column rule needs it for the same reason — a phone has even
   less room to be pushed out of.

   `align-content: start` so a short queue's rows sit at the top of the grid rather than being
   spread down it. */
.play-queue__list--page {
    display: grid;
    position: relative;
    align-content: start;

    grid-template-columns: minmax(0, 1fr);

    gap: map.get(s.$c-play-queue, "row", "gap");

    @media (min-width: $two-columns) {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        grid-template-rows: repeat(var(--queue-rows, 1), auto);
        grid-auto-flow: column;

        column-gap: map.get(s.$c-play-queue, "page", "column-gap");

        /* THE DIVIDER, and ONLY here — at one column there is nothing to divide, and a rule down
           the middle of a single list would cut it in half.

           A pseudo-element rather than a `column-rule` (that is multi-column's, not grid's) and
           rather than a border on the rows (no row knows which column it landed in). Absolutely
           positioned, which also keeps it OUT of the grid: an in-flow `::after` would take a cell
           and push the last track into a column of its own. `50%` is exactly the middle of the gap
           whenever the two columns are equal — the maths cancels: half of `2col + gap` is
           `col + gap/2`. */
        &::after {
            position: absolute;
            inset-inline-start: 50%;
            inset-block: 0;

            width: map.get(s.$c-play-queue, "page", "divider");

            transform: translateX(-50%);

            background-color: map.get(c.$c-play-queue, "border");

            content: "";
        }
    }
}

.play-queue__remove,
.play-queue__grip,
.play-queue__load {
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

/* THE WHOLE ROW PLAYS THE TRACK, and this transparent overlay button is what makes it so. It is
   out of the row's flex flow and sized to the whole of it, which is both a far better target than
   any glyph and the reason the markup can stay honest: still ONE button with one accessible name,
   so a screen reader hears "Play <track>" exactly as before.

   It has to be done this way round rather than by wrapping the row in a <button>: the row holds
   two more buttons (the grip and remove), and a <button> may contain neither. The overlay inverts
   the problem — one big transparent target, with the two genuine controls lifted above it.

   `inset: 0` resolves against `.play-queue__row`, which is the nearest positioned ancestor. That
   is what the row's `position: relative` is for. */
.play-queue__load {
    position: absolute;
    inset: 0;

    border-radius: map.get(s.$c-play-queue, "row", "radius");
}

/* …and the two real controls are lifted back above it. A POSITION IS REQUIRED, not just a
   z-index: the overlay is positioned, so it paints above every non-positioned descendant of the
   same stacking context regardless of DOM order, and without this it would silently swallow both
   — the row would play the track while nothing could be dragged or removed.

   Everything else stays UNDER the overlay on purpose — the title and artist lines included, so
   that aiming at the words plays the track. */
.play-queue__remove,
.play-queue__grip {
    position: relative;
    z-index: 1;
}

/* THE DRAG HANDLE: the cover with the drag glyph beneath it, as one column. Stacked rather than
   placed beside the cover because at 280px the row has no horizontal room to give — the title
   ellipsises first, and a leading or trailing handle column would take 24px straight out of it.
   This way the grip costs the title nothing and the row grows by a few pixels instead.

   It is a real <button> so the reorder is reachable without a pointer at all: it is the tab stop
   that carries `aria-keyshortcuts`, which is how a keyboard user finds out Alt+↑/↓ moves the row.
   Pressing it does nothing on its own, and that is the honest shape of a handle.

   `grab` / `grabbing` is the whole reason the cursor is declared per control rather than
   inherited from the row: everything else in the row plays the track and says `pointer`; this
   strip moves it. */
.play-queue__grip {
    flex-direction: column;

    gap: map.get(s.$c-play-queue, "row", "grip-gap");

    cursor: grab;

    &:active {
        cursor: grabbing;
    }
}

.play-queue__row {
    display: flex;
    position: relative;
    align-items: center;

    /* OFF-SCREEN ROWS ARE NOT RENDERED AT ALL, which is what lets this list hold a whole genre.
       Measured on a 2,000-track queue: every row was being laid out and painted — 28,000 nodes,
       ~850ms to first paint, a visibly slow scroll — and bulk enqueue made that a thing one click
       can do.

       `content-visibility` RATHER THAN WINDOWING, and the difference is what stays working. A
       virtual list renders a slice and fakes the rest, which breaks everything this list already
       does correctly: SortableJS drags rows that must exist to be dragged, Alt+↑/↓ moves focus
       between rows that must exist to be focused, and `scrollIntoView` finds a row that must
       exist to be found. Skipped content is still in the DOM and still focusable — the browser
       renders it the moment focus, find-in-page or a scroll makes it relevant — so all three keep
       working with no code at all.

       `auto` in the intrinsic size is what keeps the scrollbar honest: the estimate below is used
       until a row has been rendered once, and its real height after. */
    content-visibility: auto;
    contain-intrinsic-size: auto map.get(s.$c-play-queue, "row", "row-estimate");

    /* One row of context above and below when the loaded track is scrolled into view — see the
       watcher, which measures the height and publishes it, because a row with no artist line is
       shorter than one with. The zero fallback makes an unmeasured row behave like a plain
       `scrollIntoView`, never break. */
    scroll-margin-block: var(--queue-row-height, 0);

    padding: map.get(s.$c-play-queue, "row", "padding");
    gap: map.get(s.$c-play-queue, "row", "gap");

    border-radius: map.get(s.$c-play-queue, "row", "radius");

    /* One cursor for the whole row, set here because `cursor` INHERITS — which is the only reason
       the artist line needed fixing at all. It is a bare <span>, so it fell through to `auto` and
       drew an I-beam over its glyphs, and a caret in the middle of a row you click to play reads
       as a row you can select text in. Declaring it on the row covers the padding, the gaps and
       both text lines in one place; the two buttons keep their own declaration, since "a button
       is clickable" is true on its own and should not depend on a rule further up. */
    cursor: pointer;

    @media (prefers-reduced-motion: no-preference) {
        transition:
            background-color ti.$c-play-queue ease-out,
            box-shadow ti.$c-play-queue ease-out;
    }

    &:hover {
        background-color: map.get(c.$c-play-queue, "row-hover");
    }

    /* The loaded track wears the house "this one is live" treatment — the same two-layer neon halo
       the DataTable's hovered row and an open popover use, over a low-alpha fill of the same
       colour. The glow spreads are em-based effect constants, per the note in
       sizes/components/_button.scss.

       TIGHTER THAN THE DATATABLE'S, deliberately. That one is tuned for a row several hundred
       pixels wide with open page around it; the same 1.5em halo on a 280px row in a scrolling
       panel is both out of proportion and wider than any room the clip box can be given (the
       panel layout above caps it at the panel's padding). The outer layer is sized to land just
       inside that room, so the halo fades out on its own instead of being cut off flat. */
    &--current {
        background-color: map.get(c.$c-play-queue, "current-background");
        box-shadow:
            0 0 0.25em 0.04em map.get(c.$c-play-queue, "current-glow"),
            0 0 0.4em 0.08em map.get(c.$c-play-queue, "current-glow");
    }

    /* THE ROW IN YOUR HAND — the clone that follows the pointer during a drag (`dragClass`).
       Sortable builds it with `cloneNode`, so it keeps this component's scope attribute and all of
       its classes; what it does not keep is its place in the list, because it is appended to
       <body> to stay clear of the clipping and of the player bar painting over it. The two things
       it was inheriting from the panel — surface colour and background — therefore have to be
       restated here, or the row is drawn in whatever colours the page happens to use. The shadow
       is what says "lifted"; its offsets are em-based effect constants, per the note on the glow
       above. */
    &--dragging {
        background-color: map.get(c.$c-play-queue, "background");
        color: map.get(c.$c-play-queue, "surface");
        box-shadow: 0 0.25em 0.75em 0 map.get(c.$c-play-queue, "drag-shadow");

        cursor: grabbing;
    }

    /* THE GAP IT LEFT — the real <li>, still in the list, which Sortable moves around to show
       where a drop would land (`ghostClass`). Faded rather than hidden: collapsing it would make
       the list jump by a row the moment a drag started, and the whole point of the gap is that it
       shows the destination. */
    &--ghost {
        opacity: 0.4;

        background-color: map.get(c.$c-play-queue, "row-hover");
    }
}

/* `min-width: 0` is what lets the two lines below ellipsise. Without it this flex item refuses to
   shrink under its content width, an unbreakable title pushes the row wider than the 280px panel,
   and `text-overflow` never fires because nothing is overflowing. Same trap as the breadcrumb's
   label. */
.play-queue__meta {
    display: flex;
    flex-direction: column;

    min-width: 0;
    flex: 1 1 auto;
}

.play-queue__name,
.play-queue__artist {
    overflow: hidden;

    white-space: nowrap;

    text-overflow: ellipsis;
}

.play-queue__artist {
    color: map.get(c.$c-play-queue, "muted");

    font-size: 0.85em;
}
</style>
