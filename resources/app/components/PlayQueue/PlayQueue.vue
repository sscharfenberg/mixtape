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
 * would still reserve its 280px, and the page would sit off-centre for a queue
 * that isn't there.
 *
 * IT OVERLAYS, AND IT IS TOGGLED, AT EVERY WIDTH — the same behaviour on a phone and on
 * a desktop since 2026-08-08. It used to stand permanently open from `landscape` up with
 * the content inset to clear it, and the dashboard is what settled that: its headings are
 * RIGHT-aligned, so there is no trailing room to give, and the panel ended up over the
 * content there and beside it everywhere else. Two behaviours for one control is worse
 * than either.
 *
 * The overlay is the half worth keeping. Taking a column narrows <main>, and the app's
 * headings are drawn as tabs with one side deliberately open, hidden past the edge of the
 * screen — a <main> that stops short of the window leaves that opening in plain view on all
 * 21 pages that use one. Nothing inside a page should have to know the queue exists, and
 * now nothing does: no inset is published, and no page reserves room.
 *
 * PlayQueueToggle (in the header) is the only way it OPENS, which is also why 280px of a
 * phone being most of the screen stopped being a special case.
 *
 * IT LIGHT-DISMISSES, because it is a native `[popover]` — the same mechanism the menus use
 * (PopOver). That one attribute buys three behaviours the app would otherwise have to
 * hand-roll, and hand-roll consistently: a click anywhere outside closes it, Escape closes
 * it, and on Android the back gesture closes it instead of leaving the page (Chrome routes
 * all three through CloseWatcher). A panel that overlays the content is a panel a reader
 * wants out of the way with the gesture they already use for everything else.
 *
 * Two consequences worth knowing rather than discovering. Opening ANOTHER auto popover —
 * the site menu, the user menu, the player's volume or settings — closes this one, because
 * that is what light dismiss means when the two are not nested; the queue's OWN menu is a
 * descendant, so it nests and both stay up. And pressing the player bar's transport closes
 * the panel too, the bar being outside it.
 *
 * It is `auto`, not `manual`, and non-modal either way: the page behind stays live, which it
 * must, since navigating with the queue open is the whole point of the panel outliving the
 * page.
 *
 * CLICKING A ROW PLAYS THAT TRACK — anywhere in it. The row is an <li> holding an
 * EMPTY <button> stretched across the whole of it, because a <button> may not
 * contain another button and the row holds two more controls. So the semantics are
 * unchanged (one control, one accessible name, "Play <track>") while the target is
 * the full row. Exactly TWO things sit above that overlay and keep their own
 * behaviour: the grip and the remove button. Everything else — the title, the artist
 * line, the padding, the gaps — plays.
 *
 * REORDERING, and what it cost. The grip is the cover with the drag glyph beneath
 * it: a 24px-wide strip that costs the title not one pixel, which matters at 280px
 * where the title is already the first thing to ellipsise. The gesture logic is in
 * useQueueReorder beside this file (SortableJS + Alt+↑/↓ — read its banner). The
 * price is that the COVER no longer plays the track: it belongs to the grip now, so
 * it has to sit above the play overlay rather than inside it. Deliberate — the other
 * ~90% of the row still plays, and a 16px glyph on its own is too small a thing to
 * aim a drag at, on a phone especially.
 *
 * NOTHING HERE NAVIGATES. The title was a <Link> to the song's page until it was
 * put to a real listener: in a panel where every other pixel plays the track, the
 * one word you aim at was the one that took you somewhere else. Losing the queue's
 * only route to a song page was weighed and accepted — the listings get you there —
 * so this is a settled trade, not an oversight. If the route is ever wanted back it
 * belongs in a per-row menu, never on the title.
 *****************************************************************************/
import { computed, ref, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import CoverImage from "Components/Music/CoverImage/CoverImage.vue";
import PlayQueueMenu from "Components/PlayQueue/PlayQueueMenu.vue";
import Icon from "Components/UI/Icon.vue";
import { usePlayerAudio } from "Composables/usePlayerAudio";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { usePlayQueuePanel } from "Composables/usePlayQueuePanel";
import { formatClock } from "Utils/formatting";
import { useQueueReorder } from "./useQueueReorder";

const { t } = useI18n();
const { tracks, currentIndex, isEmpty, totalDuration, jumpTo, remove } = usePlayerQueue();
const { play } = usePlayerAudio();
const { isOpen, open, close, setOpen } = usePlayQueuePanel();

/**
 * How long a peek lasts.
 *
 * The same 3000ms the enqueue toast is given (SubjectMenu), so the two announcements of one
 * action appear and leave together rather than staggering. A plain constant rather than a
 * timing token: the `timings/` group is CSS durations, and this is a behavioural delay — the
 * same call useToast makes for its own DEFAULT_DURATION.
 */
const PEEK_MS = 3000;

/** Pending auto-close. Its presence is also what marks the panel as "open because of a peek". */
let peekTimer: ReturnType<typeof setTimeout> | undefined;

/** The popover element itself, so its state can be driven and read. */
const layer = useTemplateRef<HTMLElement>("layer");

/**
 * Keep the element and the shared flag in step, in both directions.
 *
 * DOWN: the header's toggle writes the flag, and this shows or hides the popover to match.
 * Guarded on the element's own `:popover-open`, because `showPopover()` on a popover that is
 * already showing — and `hidePopover()` on one that is not — both THROW, and the mirror below
 * means this watcher regularly runs when the element is already where it should be.
 *
 * UP: `handleToggle` adopts whatever the browser did on its own. That is the half that makes
 * light dismiss work as more than a visual: without it the flag would still read "open" after
 * a click outside, and the header would keep offering a close icon for a panel that is gone.
 */
function apply(open: boolean): void {
    const el = layer.value;
    if (!el) return;

    const showing = el.matches(":popover-open");
    if (open && !showing) el.showPopover();
    if (!open && showing) el.hidePopover();
}

/**
 * Cancel a pending auto-close: once the reader has touched the panel, it is theirs.
 *
 * Without this a peek would pull the panel out from under someone who moved onto it to drag a
 * row or press a remove — three seconds is long enough to start something and far too short to
 * finish it. Bound to `pointerenter` and `focusin` on the panel rather than the layer, because
 * the layer passes clicks through (`pointer-events: none`) and so gets no pointer events of its
 * own.
 */
function keepOpen(): void {
    clearTimeout(peekTimer);
    peekTimer = undefined;
}

/**
 * PEEK: show the panel for a moment whenever the queue GROWS, then put it away.
 *
 * On growth rather than on any change, because "something was added" is the event worth
 * showing — a removal or a reorder is something the reader is already looking at, and a
 * `playNow` that replaces a long queue with a short one has added nothing.
 *
 * A panel the reader opened themselves is LEFT ALONE: the timer's own existence is the test
 * for whether the panel is open because of a peek, so an enqueue while it is deliberately open
 * neither restarts nor schedules an auto-close. Enqueueing again mid-peek does restart it.
 */
watch(
    () => tracks.value.length,
    (now, before) => {
        if (now <= before) return;
        if (isOpen.value && peekTimer === undefined) return;

        open();
        clearTimeout(peekTimer);
        peekTimer = setTimeout(() => {
            peekTimer = undefined;
            close();
        }, PEEK_MS);
    }
);

/**
 * Adopt the element's state — fired for a light dismiss, Escape, the back gesture, and our own
 * calls.
 *
 * A CLOSE ALSO CANCELS A PENDING PEEK, which is not tidiness: without it, dismissing a peeking
 * panel and reopening it within the three seconds left the original timer running, and it shut
 * the panel again under a reader who had just asked for it. Any close means the auto-close has
 * nothing left to do.
 */
function handleToggle(event: ToggleEvent): void {
    const open = event.newState === "open";
    if (!open) keepOpen();
    setOpen(open);
}

watch(isOpen, apply);

/*
 * The ELEMENT is watched, not just the flag, and it has to be: the panel is `v-if`d on the
 * queue having anything, and this component mounts with an empty one — so the element does not
 * exist yet when the component does. It appears later, possibly with the flag already true
 * (queue emptied while the panel was open, then filled again), and adopting the flag as it
 * appears is what keeps that case behaving as it did before the element became a popover.
 *
 * The `toggle` listener is bound in the TEMPLATE for the same reason, rather than added here
 * on mount: Vue re-attaches it every time the element is created, where an `onMounted`
 * `addEventListener` ran once against nothing and silently never fired. That was this
 * change's first bug — Escape closed the panel and the header went on showing a close icon.
 */
watch(layer, el => {
    if (el) apply(isOpen.value);
});

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
 * A total rather than a per-row duration because at 280px a row has no space for
 * one — see the width note in sizes/components/_play-queue.scss.
 */
const totalClock = computed(() => formatClock(totalDuration.value));

/** The scrolling <ol>. Held to find the loaded row and to publish its height. */
const list = ref<HTMLOListElement | null>(null);

// Drag-and-drop by the grip, plus Alt+↑/↓ — both in useQueueReorder beside this file,
// which needs the list element for the same two reasons this component holds it: that
// is what Sortable mounts on, and what a moved row is re-focused through.
const { onRowKeydown, onGripPointerdown, shortcutLabel } = useQueueReorder(list);

/**
 * Bring the loaded track into view, one row clear of the edge it approached.
 *
 * It exists because the pointer moves without anyone touching the list: next/prev,
 * auto-advance at the end of a track, the repeat wrap. Any of those can leave the
 * row that is now playing off-screen, and a queue showing the wrong part of itself
 * is worse than one that scrolls under you.
 *
 * THE ARITHMETIC IS THE BROWSER'S, not ours. `scroll-margin-block` grows the row's
 * scroll box by one row on each edge and `block: "nearest"` then scrolls only when
 * that grown box does not fit — which is both "leave a row of context" and "leave an
 * already-visible row alone", without this function comparing a single rectangle.
 * The margin is MEASURED rather than tokenised because rows are not one height: a
 * track whose file carried no artist has one line, not two. It is published as a
 * custom property so the declaration itself stays in the stylesheet.
 *
 * Smoothness is deliberately absent here — `scroll-behavior` on the list carries it,
 * under the repo's `prefers-reduced-motion: no-preference` guard, so the motion
 * decision sits in CSS with every other one. `scrollIntoView` with no `behavior`
 * honours that computed value.
 */
const scrollCurrentIntoView = (): void => {
    const row = list.value?.children.item(currentIndex.value) as HTMLElement | null;

    if (!row) return;

    list.value?.style.setProperty("--queue-row-height", `${row.offsetHeight}px`);
    row.scrollIntoView({ block: "nearest", inline: "nearest" });
};

// `flush: "post"` because the row has to exist first: queueing a track and it
// becoming current happen in the same tick.
watch(currentIndex, scrollCurrentIntoView, { flush: "post" });

// Opening the panel on a phone, where it is `display: none` while shut — scrolling a
// hidden element does nothing, so several tracks may have gone by unscrolled.
watch(
    isOpen,
    open => {
        if (open) scrollCurrentIntoView();
    },
    { flush: "post" }
);
</script>

<template>
    <div v-if="!isEmpty" ref="layer" class="play-queue-layer" popover="auto" @toggle="handleToggle">
            <aside
                class="play-queue"
                :aria-label="t('player.queue.label')"
                @pointerenter="keepOpen"
                @focusin="keepOpen"
            >
            <header class="play-queue__header">
                <h2 class="play-queue__title">
                    <icon name="playlist" :size="1" />
                    {{ t("player.queue.label") }}
                </h2>
                <play-queue-menu />
            </header>
            <ol ref="list" class="play-queue__list">
                <li
                    v-for="(track, index) in tracks"
                    :key="`${track.id}-${index}`"
                    class="play-queue__row"
                    :class="{ 'play-queue__row--current': index === currentIndex }"
                    :aria-current="index === currentIndex ? 'true' : undefined"
                    @keydown="onRowKeydown($event, index)"
                >
                    <!-- Empty on purpose: this button IS the row's hit area (see the styles),
                         and its accessible name comes from the label rather than from any
                         content. It has nothing inside it because everything visible in the
                         row is either one of the two controls that must stay above it, or
                         text that should play the track when clicked. -->
                    <button
                        type="button"
                        class="play-queue__load"
                        :aria-label="t('player.queue.load', { name: track.name })"
                        @click="playRow(index)"
                    ></button>
                    <!-- The drag handle, and the only thing Sortable will start a drag from.
                         The cover is INSIDE it so the grip is a 24px-wide strip rather than a
                         lone 16px glyph — see the component banner for what that costs.

                         THE HINT SAYS "CLICK IT FIRST", and it has to: the keyboard
                         alternative moves the FOCUSED row, so hovering one and pressing the
                         keys does nothing at all — which is exactly how it read as broken.
                         The keys are named for the keyboard in front of the reader (⌥ on a
                         Mac), while `aria-keyshortcuts` keeps ARIA's canonical spelling,
                         which is what assistive tech expects to parse and announce in its
                         own words. The handler itself sits on the <li> — keydown bubbles,
                         so it works from any of the row's three controls. -->
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
                        <!-- Both lines are plain text, deliberately. The title used to be a
                             real <Link> to the song's page, and in a panel whose every other
                             pixel plays the track that is a trap: the one spot a listener
                             actually aims for was the one spot that navigated away instead.
                             So it stays under the load button's overlay and plays like the
                             rest of the row. -->
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

            <!-- Count and running time LAST, and a sibling of the list rather than a child
                 of it — which is what keeps it out of the scrolling area and on screen for
                 any length of queue. It gets a line of its own instead of sitting beside
                 the title because even at the wider `full` size a single row cannot hold
                 the word "Warteschlange", two numbers and a button without ellipsising the
                 one part that names the panel. -->
            <p class="play-queue__summary">{{ t("player.queue.summary", tracks.length) }} · {{ totalClock }}</p>
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

/* The panel's own padding, as the amount a child bleeds outward to reach the panel's
   inner edge — always paired with an equal padding that puts the content back where
   it was. Two children need it, for different reasons: the list, so the current row's
   glow has clip-free room inside a scroll container (see `&__list`), and the summary,
   so its rule spans the full width instead of stopping short (see `&__summary`).

   One named value rather than two, because it is one decision: bleed exactly as far
   as the panel is padded. Any drift and the content it belongs to shifts. */
$bleed: map.get(s.$c-play-queue, "padding");

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
    position: fixed;
    inset: var(--app-header-height, 0) 0 var(--app-player-height, 0) 0;
    z-index: z.$c-play-queue;

    box-sizing: border-box;

    overflow: visible;

    /* THE UA SHEET'S `[popover]` DEFAULTS, NEUTRALISED. A popover ships as a centred,
       content-sized, bordered box (`width/height: fit-content; margin: auto; border: solid;
       padding: 0.25em; overflow: auto; background: Canvas`), which is the right default for a
       menu and wrong for a full-height coordinate system. `width`/`height: auto` matter most:
       left with `fit-content` they would beat the `inset` above and the layer would hug the
       panel instead of spanning the window. */
    width: auto;
    max-width: map.get(s.$c-app, "max");
    height: auto;
    padding: 0;
    border: 0;

    background: none;
    color: inherit;

    /* NO `display` HERE, deliberately. The UA hides `[popover]` until `:popover-open`, and an
       author `display` — of either value — beats that and pins the panel permanently open or
       permanently shut. This is the one property to leave alone now that the element's own
       state, rather than a class, says whether it is showing. */

    pointer-events: none;
    margin-block: 0;
    margin-inline: auto;
}

/* The panel itself, pinned to the layer's trailing edge, and FULL HEIGHT AT EVERY
   WIDTH — header to player bar, which is the span the layer already describes.

   It used to be only as tall as its contents from `landscape` up. That reads fine
   for two or three tracks and steadily worse as the queue grows: the bottom edge
   lands at whatever height the list happens to reach, so it moves every time
   something is queued or removed, and the panel stops looking like a fixture of
   the layout and starts looking like a dropdown that failed to close. A constant
   edge is worth more than the strip of page a short queue gave back — nothing is
   behind it anyway, since Container already insets content for the full height. */
.play-queue {
    display: flex;
    position: absolute;
    inset: 0 0 0 auto;
    flex-direction: column;

    box-sizing: border-box;
    width: map.get(s.$c-play-queue, "width");
    padding: map.get(s.$c-play-queue, "padding");

    /* Wider from `full` up, where the cage stops growing and the extra 120px comes
       out of space <main> cannot use anyway. FullLayout publishes the content inset
       from the same two tokens and MUST switch at this same breakpoint — miss that
       and the page's last column slides under the panel. */
    @include m.mq("full") {
        width: map.get(s.$c-play-queue, "width-full");
    }

    /* SIDES ONLY, and no rounding — at every width now, which is what made the
       `landscape` override above unnecessary rather than merely different. The
       panel meets the header and the player bar at both ends, so a top or bottom
       edge would draw a second line a pixel from theirs (a seam, not a frame) and
       a rounded corner against either would be a notch showing the page through. */
    border-inline: map.get(s.$c-play-queue, "border") solid map.get(c.$c-play-queue, "border");

    gap: map.get(s.$c-play-queue, "gap");

    background-color: map.get(c.$c-play-queue, "background");
    color: map.get(c.$c-play-queue, "surface");

    pointer-events: auto;

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

    /* A FOOTER RULE, not an underline on some text. It bleeds to the panel's inner
       edges (`$bleed`, the same pairing the list uses) so the line runs the full width
       — stopping short of the sides would read as a border belonging to the paragraph
       rather than as the division between the list and the totals below it.

       The equal inline padding puts the text back in line with the rows above, and the
       top padding keeps it off the rule. Nothing at the bottom: the panel's own padding
       already holds it clear of the player bar. */
    &__summary {
        padding: map.get(s.$c-play-queue, "gap") $bleed 0;

        border-top: map.get(s.$c-play-queue, "border") solid map.get(c.$c-play-queue, "border");

        margin: 0 (-$bleed);

        color: map.get(c.$c-play-queue, "muted");

        font-size: 0.85em;
        font-variant-numeric: tabular-nums;
    }

    &__remove,
    &__grip,
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

    /* THE WHOLE ROW PLAYS THE TRACK, and this transparent overlay button is what
       makes it so. It is out of the row's flex flow and sized to the whole of it,
       which is both a far better target than any glyph and the reason the markup can
       stay honest: still ONE button with one accessible name, so a screen reader
       hears "Play <track>" exactly as before.

       It has to be done this way round rather than by wrapping the row in a
       <button>: the row holds two more buttons (the grip and remove), and a
       <button> may contain neither. The overlay inverts the problem — one big
       transparent target, with the two genuine controls lifted above it.

       It was a stretched `::after` on a button that wrapped the cover, until the
       cover became the drag grip and left it with nothing to wrap. An empty button
       positioned directly is the same hit area with one box instead of two — and it
       has a real bounding box, so its focus ring traces the row (a 0×0 button's
       would be a dot) and a browser test can click it like any other control. The
       radius matches the row's, so that ring follows the rounded corners.

       `inset: 0` resolves against `&__row`, which is the nearest positioned
       ancestor. That is what the row's `position: relative` is for. */
    &__load {
        position: absolute;
        inset: 0;

        border-radius: map.get(s.$c-play-queue, "row", "radius");
    }

    /* …and the two real controls are lifted back above it. A POSITION IS REQUIRED,
       not just a z-index: the overlay is positioned, so it paints above every
       non-positioned descendant of the same stacking context regardless of DOM
       order, and without this it would silently swallow both — the row would play
       the track while nothing could be dragged or removed.

       Everything else stays UNDER the overlay on purpose — the title and artist
       lines included, so that aiming at the words plays the track. */
    &__remove,
    &__grip {
        position: relative;
        z-index: 1;
    }

    /* THE DRAG HANDLE: the cover with the drag glyph beneath it, as one column.
       Stacked rather than placed beside the cover because at 280px the row has no
       horizontal room to give — the title ellipsises first, and a leading or
       trailing handle column would take 24px straight out of it. This way the grip
       costs the title nothing and the row grows by a few pixels instead.

       It is a real <button> so the reorder is reachable without a pointer at all:
       it is the tab stop that carries `aria-keyshortcuts`, which is how a keyboard
       user finds out Alt+↑/↓ moves the row. Pressing it does nothing on its own,
       and that is the honest shape of a handle.

       `grab` / `grabbing` is the whole reason the cursor is declared per control
       rather than inherited from the row: everything else in the row plays the
       track and says `pointer`; this strip moves it. */
    &__grip {
        flex-direction: column;

        gap: map.get(s.$c-play-queue, "row", "grip-gap");

        cursor: grab;

        &:active {
            cursor: grabbing;
        }
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
       where it was. That last part is the whole point — at 280px the title is
       already the first thing to ellipsise (see sizes/components/_play-queue.scss),
       so buying glow room with row width is not a trade this panel can afford.

       It reads the panel's `padding` token rather than declaring a size of its
       own because the two are not merely equal, they must stay equal: the margin
       has to cancel that exact padding or the rows shift. */
    &__list {
        overflow-y: auto;

        flex: 1 1 auto;

        padding: $bleed;

        margin: -$bleed;

        list-style: none;

        /* Carries the smoothness for scrollCurrentIntoView, which passes no `behavior`
           of its own so that this decision lives here with the rest of the motion.
           `scroll-behavior` affects PROGRAMMATIC scrolls only — a wheel or a drag is
           untouched by it, so nobody's own scrolling is being animated. */
        @media (prefers-reduced-motion: no-preference) {
            scroll-behavior: smooth;
        }
    }

    &__row {
        display: flex;
        position: relative;
        align-items: center;

        /* OFF-SCREEN ROWS ARE NOT RENDERED AT ALL, which is what lets this panel hold a
           whole genre. Measured on a 2,000-track queue: every row was being laid out and
           painted — 28,000 nodes, ~850ms to first paint, a visibly slow scroll — and bulk
           enqueue made that a thing one click can do.

           `content-visibility` RATHER THAN WINDOWING, and the difference is what stays
           working. A virtual list renders a slice and fakes the rest, which breaks
           everything this panel already does correctly: SortableJS drags rows that must
           exist to be dragged, Alt+↑/↓ moves focus between rows that must exist to be
           focused, and `scrollIntoView` finds a row that must exist to be found. Skipped
           content is still in the DOM and still focusable — the browser renders it the
           moment focus, find-in-page or a scroll makes it relevant — so all three keep
           working with no code at all.

           `auto` in the intrinsic size is what keeps the scrollbar honest: the estimate
           below is used until a row has been rendered once, and its real height after. */
        content-visibility: auto;
        contain-intrinsic-size: auto map.get(s.$c-play-queue, "row", "row-estimate");

        /* One row of context above and below when the loaded track is scrolled into
           view — see scrollCurrentIntoView, which measures the height and publishes it,
           because a row with no artist line is shorter than one with. The zero fallback
           makes an unmeasured row behave like a plain `scrollIntoView`, never break. */
        scroll-margin-block: var(--queue-row-height, 0);

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
           on a 280px row in a scrolling panel is both out of proportion and wider
           than any room the clip box can be given (`&__list` above caps it at the
           panel's padding). The outer layer is sized to land just inside that room,
           so the halo fades out on its own instead of being cut off flat. */
        &--current {
            background-color: map.get(c.$c-play-queue, "current-background");
            box-shadow:
                0 0 0.25em 0.04em map.get(c.$c-play-queue, "current-glow"),
                0 0 0.4em 0.08em map.get(c.$c-play-queue, "current-glow");
        }

        /* THE ROW IN YOUR HAND — the clone that follows the pointer during a drag
           (`dragClass`). Sortable builds it with `cloneNode`, so it keeps this
           component's scope attribute and all of its classes; what it does not keep
           is its place in the panel, because it is appended to <body> to stay clear
           of the list's clipping and of the player bar painting over it. The two
           things it was inheriting from the panel — surface colour and background —
           therefore have to be restated here, or the row is drawn in whatever
           colours the page happens to use. The shadow is what says "lifted"; its
           offsets are em-based effect constants, per the note on the glow above. */
        &--dragging {
            background-color: map.get(c.$c-play-queue, "background");
            color: map.get(c.$c-play-queue, "surface");
            box-shadow: 0 0.25em 0.75em 0 map.get(c.$c-play-queue, "drag-shadow");

            cursor: grabbing;
        }

        /* THE GAP IT LEFT — the real <li>, still in the list, which Sortable moves
           around to show where a drop would land (`ghostClass`). Faded rather than
           hidden: collapsing it would make the list jump by a row the moment a drag
           started, and the whole point of the gap is that it shows the destination. */
        &--ghost {
            opacity: 0.4;

            background-color: map.get(c.$c-play-queue, "row-hover");
        }
    }

    /* `min-width: 0` is what lets the two lines below ellipsise. Without it this
       flex item refuses to shrink under its content width, an unbreakable title
       pushes the row wider than the 280px panel, and `text-overflow` never fires
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

    &__artist {
        color: map.get(c.$c-play-queue, "muted");

        font-size: 0.85em;
    }
}
</style>
