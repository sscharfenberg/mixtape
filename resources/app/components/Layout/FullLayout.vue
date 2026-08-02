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
import PlayQueue from "Components/Player/PlayQueue.vue";
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

/* One column until there is room for two. Below the `landscape` step the queue is
   a fixed bottom sheet rather than a column, so it is out of the grid entirely and
   this stays a single track at every width — the `--with-queue` modifier only
   means anything once the panel is actually beside the content.

   `align-items: start` so the queue column is its own height and can stick, rather
   than being stretched to match a long page and having nothing left to scroll. */
.app-body {
    display: grid;
    align-items: start;
    grid-template-columns: minmax(0, 1fr);

    flex: 1 1 auto;

    @include m.mq("landscape") {
        &--with-queue {
            grid-template-columns: minmax(0, 1fr) map.get(s.$c-play-queue, "width");

            padding-right: map.get(s.$c-play-queue, "gap");
            gap: map.get(s.$c-play-queue, "gap");
        }
    }
}
</style>
