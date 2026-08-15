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
 * IT OVERLAYS, AND IT IS TOGGLED, AT EVERY WIDTH — the same behaviour on a phone and on a
 * desktop. Standing permanently open from `landscape` up, with the content inset to clear it,
 * is the obvious alternative and the dashboard settles it: that page's headings are
 * RIGHT-aligned, so there is no trailing room to give, and the panel ends up over the content
 * there and beside it everywhere else. Two behaviours for one control is worse than either.
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
import { computed, onMounted, onUnmounted, ref, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import PlayQueueMenu from "Components/PlayQueue/PlayQueueMenu.vue";
import QueueList from "Components/PlayQueue/QueueList.vue";
import Icon from "Components/UI/Icon.vue";
import { usePlayerQueue } from "Composables/usePlayerQueue";
import { notePlayQueuePanel, usePlayQueuePanel } from "Composables/usePlayQueuePanel";
import { formatClock } from "Utils/formatting";

const { t } = useI18n();
const { tracks, isEmpty, totalDuration, restoreNonce } = usePlayerQueue();
const { isOpen, open, close, setOpen } = usePlayQueuePanel();

// TELL THE HEADER THERE IS SOMETHING TO OPEN. The toggle and the `Q` shortcut are both hidden
// where no panel is rendered — the guest share space — and this is what they read. Registered
// on MOUNT rather than in setup, and dropped on unmount, so a layout swap lands right way up
// (notePlayQueuePanel explains both orderings).
onMounted(() => notePlayQueuePanel(true));
onUnmounted(() => notePlayQueuePanel(false));

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

/**
 * Whether THIS opening is a peek — the reactive half of `peekTimer`, for the styles.
 *
 * THE PANEL HAS TWO ENTRANCES AND THEY MEAN DIFFERENT THINGS. A press of the header toggle is a
 * request: the reader asked, so the panel should arrive and get out of the way. A peek is an
 * ANNOUNCEMENT — nobody asked, it appeared to say something was added — and there a little more
 * motion is doing real work rather than decorating. So the wipe is the same either way and the
 * peek adds a light down the panel's inner edge (see the styles).
 *
 * A ref rather than reading `peekTimer` directly, because a plain `let` is not reactive and a
 * class bound to it would never update.
 */
const peeking = ref(false);

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
    // Once the reader has touched it, this is their panel and not an announcement any more.
    peeking.value = false;
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
 *
 * A RESTORE IS NOT A GROWTH, even though the length says otherwise. Signing in repopulates the
 * queue from storage in one step under a layout that never unmounts, so 0 → N reaches this
 * watcher looking exactly like an enqueue — and the panel slid open at every sign-in,
 * announcing something nobody had just done. `restoreNonce` is what separates the two.
 */
let seenRestore = restoreNonce.value;
watch(
    () => tracks.value.length,
    (now, before) => {
        if (restoreNonce.value !== seenRestore) {
            seenRestore = restoreNonce.value;
            return;
        }
        if (now <= before) return;
        if (isOpen.value && peekTimer === undefined) return;

        peeking.value = true;
        open();
        clearTimeout(peekTimer);
        peekTimer = setTimeout(() => {
            peekTimer = undefined;
            peeking.value = false;
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
 * The queue's running time as a clock, for the panel header.
 *
 * A total rather than a per-row duration because at 280px a row has no space for
 * one — see the width note in sizes/components/_play-queue.scss.
 */
const totalClock = computed(() => formatClock(totalDuration.value));

/**
 * The list, so opening the panel can bring the loaded row into view.
 *
 * The panel is `display: none` while shut on a phone, and scrolling a hidden element does
 * nothing — so by the time it is opened, several tracks may have gone by unscrolled. Only this
 * component knows it has just opened; only QueueList holds the <ol>. Hence the reach across,
 * which is the smaller seam of the two available.
 */
const rows = useTemplateRef<InstanceType<typeof QueueList>>("rows");

watch(
    isOpen,
    open => {
        if (open) rows.value?.scrollCurrentIntoView();
    },
    { flush: "post" }
);
</script>

<template>
    <div
        v-if="!isEmpty"
        ref="layer"
        class="play-queue-layer"
        :class="{ 'play-queue-layer--peek': peeking }"
        popover="auto"
        @toggle="handleToggle"
    >
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
            <queue-list ref="rows" layout="panel" />

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

    /* THE LAYER ITSELF NEVER MOVES — it is a coordinate system, not a surface, and animating a
       full-viewport box would animate nothing anybody can see. These two exist only so the PANEL
       inside it survives its own exit: closing a popover yanks it out of the top layer and sets
       `display: none` on the same frame, which cuts the wipe off mid-gesture. `allow-discrete`
       holds both until the transition below has finished.

       Declared here rather than under `:popover-open`, because a discrete transition has to be
       described in the state being left as well as the one being entered.

       Same pairing the popover's own content uses (styles/components/popover/_content.scss),
       split across two elements: the discrete half on the element whose display toggles, the
       visual half on the one that is actually seen. */
    @media (prefers-reduced-motion: no-preference) {
        transition:
            display map.get(ti.$c-play-queue-panel, "wipe") allow-discrete,
            overlay map.get(ti.$c-play-queue-panel, "wipe") allow-discrete;
    }
}

/* Open. `@starting-style` is what gives the wipe a from-value at all: the panel is not merely
   hidden while the popover is shut, it is NOT RENDERED (the UA's `[popover]:not(:popover-open)`
   is `display: none`), so without it the first style the panel ever has is the finished one and
   nothing transitions. The exit needs no equivalent — the base rule above is the from-value going
   back. */
.play-queue-layer:popover-open .play-queue {
    @media (prefers-reduced-motion: no-preference) {
        clip-path: inset(0);

        @starting-style {
            clip-path: inset(0 0 0 100%);
        }
    }
}

/* THE PEEK'S EXTRA: a light running down the panel's inner edge, once. It is the difference
   between the two entrances — a press of the toggle is a request and should get out of the way,
   a peek is an announcement that nobody asked for and has something to say. Only the peek gets
   it, so the deliberate open stays calm.

   A gradient bar translated down, rather than an animated gradient with `@property`: one
   composited transform beats repainting a gradient sixty times a second, on an element that
   appears this often. `pointer-events: none` because it sits over the rows. */
.play-queue-layer--peek:popover-open .play-queue::after {
    @media (prefers-reduced-motion: no-preference) {
        position: absolute;
        inset-inline-start: 0;
        inset-block: 0;
        z-index: 1;

        width: map.get(s.$c-play-queue, "sweep");

        background: linear-gradient(
            to bottom,
            transparent,
            map.get(c.$c-play-queue, "current-glow"),
            transparent
        );

        content: "";

        pointer-events: none;

        animation: play-queue-sweep map.get(ti.$c-play-queue-panel, "sweep") ease-out;
    }
}

@media (prefers-reduced-motion: no-preference) {
    @keyframes play-queue-sweep {
        0% {
            opacity: 0;

            transform: translateY(-100%);
        }

        25% {
            opacity: 1;
        }

        100% {
            opacity: 0;

            transform: translateY(100%);
        }
    }
}

/* The panel itself, pinned to the layer's trailing edge, and FULL HEIGHT AT EVERY
   WIDTH — header to player bar, which is the span the layer already describes.

   Sizing it to its contents is the alternative, and it reads fine for two or three
   tracks and steadily worse as the queue grows: the bottom edge lands at whatever
   height the list happens to reach, so it moves every time something is queued or
   removed, and the panel stops looking like a fixture of the layout and starts
   looking like a dropdown that failed to close. A constant edge is worth more than
   the strip of page a short queue would give back — nothing is behind it anyway,
   since Container already insets content for the full height. */
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

    /* A WIPE, NOT A SLIDE. The panel is revealed from the edge it is pinned to rather than
       travelling in from off-screen: nothing translates, the rows are already in place as the
       clip uncovers them, and there is no long journey to sit through on the twentieth repeat —
       which matters here more than anywhere, because the peek opens this panel by itself every
       time the queue grows.

       `inset(0 0 0 100%)` insets the LEFT edge fully, leaving zero width at the right — so the
       panel grows leftwards out of the trailing edge it lives on. Physical rather than logical
       because `inset()` has no logical form; in an RTL locale this would want the mirror, and
       this app ships de and en.

       Not a `slide` and not `scaleX`: scaling squashes every glyph in the panel on the way in,
       which is the cheap-CSS tell. */
    @media (prefers-reduced-motion: no-preference) {
        clip-path: inset(0 0 0 100%);

        transition: clip-path map.get(ti.$c-play-queue-panel, "wipe") ease-out;
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
}
</style>
