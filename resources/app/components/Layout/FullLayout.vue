<script setup lang="ts">
/******************************************************************************
 * FullLayout
 * The app's default page layout (wired in main.ts as `layout: () => FullLayout`,
 * so every page renders inside it). Holds the persistent chrome — AppHeader, the
 * AppMain content landmark that wraps the page via <slot />, the PlayQueue beside
 * it and either AppFooter or the PlayerBar below — plus the single
 * ToastContainer, TooltipLayer and Breadcrumb.
 *
 * "Persistent" is the load-bearing word for the player half. Inertia swaps the
 * PAGE component on navigation and keeps this one, so anything that must survive
 * a click — the queue, and the audio element that will eventually live in the
 * PlayerBar — belongs here and nowhere else. A player inside a page would stop
 * the music every time you opened an album.
 *
 * The body is a two-column grid: the page on the left, the queue on the right.
 * The queue column exists only while the queue does — `grid-template-columns` is
 * driven off the same `isEmpty` that stops PlayQueue rendering, so an empty queue
 * leaves the page its full width instead of a 240px hole.
 *
 * The footer and the PlayerBar are alternatives, not neighbours: once a track is
 * loaded the bar takes the footer's place. It shows on "there is a current track"
 * rather than "audio is playing", so pausing cannot make the controls disappear.
 *
 * The Breadcrumb is wrapped in a Container because <main> is full-bleed in this
 * app (see AppMain / Container): without it the trail would start at the window
 * edge instead of lining up with the page content below it. It renders nothing
 * when the page hasn't declared a trail, so the wrapper collapses on those pages.
 *
 * `breadcrumbs` arrives as an Inertia LAYOUT prop, not from a store: the page
 * publishes it via useBreadcrumbs, Inertia spreads it onto this component, and
 * — the reason it works that way — Inertia clears it at the component swap
 * rather than when the request starts, so the trail never blinks out mid-visit.
 *****************************************************************************/
import AppFooter from "Components/Landmarks/Footer/AppFooter.vue";
import AppHeader from "Components/Landmarks/Header/AppHeader.vue";
import AppMain from "Components/Landmarks/Main/AppMain.vue";
import PlayerBar from "Components/Player/PlayerBar.vue";
import PlayQueue from "Components/PlayQueue/PlayQueue.vue";
import Breadcrumb from "Components/UI/Breadcrumb.vue";
import Container from "Components/UI/Container.vue";
import ToastContainer from "Components/UI/ToastContainer.vue";
import TooltipLayer from "Components/UI/Tooltip/TooltipLayer.vue";
import type { BreadcrumbItem } from "Composables/useBreadcrumbs";
import { usePlayerQueue } from "Composables/usePlayerQueue";

defineProps<{
    /** The current page's breadcrumb trail, or undefined on a page that declares none. */
    breadcrumbs?: BreadcrumbItem[];
}>();

const { isEmpty, current, hydrate } = usePlayerQueue();

// Restore the stored queue once, here, because this is the one component that
// mounts before any page and never unmounts. It needs Inertia's shared props to
// know whose queue it is reading, which is why it cannot happen at module load.
hydrate();
</script>

<template>
    <app-header />
    <div class="app-body" :class="{ 'app-body--with-queue': !isEmpty }">
        <app-main>
            <container><breadcrumb :crumbs="breadcrumbs ?? []" /></container>
            <slot />
        </app-main>
        <play-queue />
    </div>
    <player-bar v-if="current" />
    <app-footer v-else />
    <toast-container />
    <tooltip-layer />
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;

/* One column until there is room for two. Below the `landscape` step the queue is a
   fixed OVERLAY rather than a column — out of the flow entirely, floating over the
   content when the header's toggle opens it — so this stays a single track there and
   the page keeps its full width. Nothing to reserve for it either, which is the
   point: the bottom sheet this replaced had to have half a viewport of padding kept
   free below the page so its last rows could still be scrolled to.

   `align-items: start` so the queue column is its own height and can stick, rather
   than being stretched to match a long page and having nothing left to scroll.

   WITH THE QUEUE OPEN THE WHOLE BODY IS CAGED — capped to the app's max width and
   centred, exactly as Container is. That is the fix for a genuinely odd effect: the
   grid used to span the window while the page's own Container centred itself inside
   the cage, so on any screen wider than 1440px, enqueuing one song yanked the page
   content leftward by half the slack while the panel stayed out at the far edge.
   Caging the grid keeps both halves in the same box the header's inner row uses, so
   the content does not move at all and the panel ends where the app ends.

   Below the cage width there is no slack, so the body is the full window and the
   panel sits flush against the right edge — in line with the header bar and <main>,
   which reach it too. An earlier version added the cage's inline PADDING here on
   top, which was wrong in a way that hid itself: under 1440px the centring is zero,
   so that padding was the entire inset — a flat 12px that never moved with width
   and just held the panel off the edge for no reason.

   Only the `--with-queue` case is caged. Without the panel <main> stays full-bleed,
   which it has to: the glowing-border headings reach past the Container so their
   seam runs off-screen (see Container / Headline). With the queue open they end at
   the cage edge instead, on a window wide enough for that to be visible — the price
   of keeping the two columns in one box, and the reason this is scoped to the
   modifier rather than set on .app-body outright. */
$cage: map.get(s.$c-app, "max");

.app-body {
    display: grid;
    align-items: start;
    grid-template-columns: minmax(0, 1fr);

    flex: 1 1 auto;

    @include m.mq("landscape") {
        &--with-queue {
            grid-template-columns: minmax(0, 1fr) map.get(s.$c-play-queue, "width");

            /* `width: 100%` is load-bearing, not belt-and-braces. #app is a COLUMN
               flex container, so width is the cross axis and this item normally
               fills it by `align-self: stretch` — but an auto margin on the cross
               axis cancels stretch outright, and the body collapsed to its
               fit-content width (~760px of a 975px window) with the whole layout
               shrunk inside it. Stating the width puts the fill back and leaves the
               auto margins doing only the centring they are here for. */
            width: 100%;
            max-width: $cage;

            gap: map.get(s.$c-play-queue, "gap");
            margin-inline: auto;
        }
    }
}
</style>
