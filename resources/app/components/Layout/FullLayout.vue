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
 * The body is a plain block and <main> spans the window, as it did before the queue
 * existed. The queue floats ABOVE the page and reserves nothing: it is an overlay at
 * every width now, opened from the header (see PlayQueue's banner for what the
 * dashboard settled), so this layout has no trailing inset to publish and no class to
 * carry. That is also what keeps the app's full-bleed headings — tabs with one side
 * drawn open, meant to be hidden past the edge of the screen — running off the window
 * on all 21 pages that use one.
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
import { usePage } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import AppFooter from "Components/Landmarks/Footer/AppFooter.vue";
import AppHeader from "Components/Landmarks/Header/AppHeader.vue";
import AppMain from "Components/Landmarks/Main/AppMain.vue";
import PlayerBar from "Components/Player/PlayerBar.vue";
import PlayQueue from "Components/PlayQueue/PlayQueue.vue";
import SearchOverlay from "Components/Search/SearchOverlay.vue";
import Breadcrumb from "Components/UI/Breadcrumb.vue";
import Container from "Components/UI/Container.vue";
import ToastContainer from "Components/UI/ToastContainer.vue";
import TooltipLayer from "Components/UI/Tooltip/TooltipLayer.vue";
import type { BreadcrumbItem } from "Composables/useBreadcrumbs";
import { abandonQueue, usePlayerQueue } from "Composables/usePlayerQueue";

defineProps<{
    /** The current page's breadcrumb trail, or undefined on a page that declares none. */
    breadcrumbs?: BreadcrumbItem[];
}>();

const page = usePage();
const { current, hydrate } = usePlayerQueue();

// Restore the stored queue once, here, because this is the one component that
// mounts before any page and never unmounts. It needs Inertia's shared props to
// know whose queue it is reading, which is why it cannot happen at module load.
hydrate();

/** Who the queue belongs to right now — null once the reader signs out. */
const userId = computed(() => page.props.auth.user?.id ?? null);

/**
 * Follow the session: forget the queue when it ends, read it back when one begins.
 *
 * THIS LAYOUT NEVER UNMOUNTS, which is the whole reason the watcher is needed. Logging out
 * is an Inertia visit, so `setup()` does not run again and `hydrate()`'s one-shot guard is
 * still set — the previous reader's queue stayed in memory, the PlayerBar kept offering it,
 * and every row in it pointed at a stream behind `auth`. It looked like a player that had
 * quietly stopped working.
 *
 * ABANDONED UNCONDITIONALLY, because the watcher only fires on a change and a change means
 * whatever is in memory belongs to somebody who is no longer reading this page. Not
 * `clear()`, which persists — see `abandonQueue`'s note on why writing here is the one thing
 * it must not do. The player, the queue panel and the header's toggle then vanish because
 * the queue is EMPTY, not because anything gates on `auth`: a guest on a share link has a
 * legitimate player, and a gate here would be a gate there too.
 *
 * THEN READ THE NEW READER'S OWN, which `abandonQueue` has just re-armed `hydrate()` for.
 * Signing in is a visit as well, so without this the queue would not come back until the
 * next full page load. `playerState` only rides down on one of those, but the reader's
 * localStorage copy was left untouched and is what answers here.
 */
watch(userId, now => {
    abandonQueue();

    if (now !== null) hydrate();
});
</script>

<template>
    <app-header />
    <!-- The search overlay hangs from the header and is mounted HERE rather than inside it, which
         is what keeps it out of the guest share space: ShareLayout renders the same AppHeader and
         deliberately mounts no search. It registers itself, and the header's trigger and the two
         shortcuts read that registration — the same arrangement the queue panel uses. -->
    <search-overlay />
    <div class="app-body">
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
/* A plain block, and now nothing more than that. <main> spans the window exactly as it
   did before the queue existed, and that is the point of the whole arrangement: the
   app's headings are tabs with one side drawn open, meant to be hidden past the edge of
   the screen, and a <main> that stops short leaves that opening in plain view on all 21
   pages that use one.

   THIS USED TO PUBLISH `--content-inset-end` from `landscape` up, so Container could
   keep the page's trailing column clear of a permanently-open panel — and it widened at
   `full` to match the panel's own step, two files holding one decision. All of it is
   gone with the panel becoming an overlay everywhere (PlayQueue's banner says why), and
   with it the class of bug where the two numbers drift: there is no number. */
.app-body {
    flex: 1 1 auto;
}
</style>
