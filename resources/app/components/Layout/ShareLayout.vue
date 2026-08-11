<script setup lang="ts">
/******************************************************************************
 * ShareLayout
 * The layout the guest page at /s/{share} renders in — chosen by SharePage itself
 * (`defineOptions({ layout: ShareLayout })`), which is what Inertia reads in preference to
 * the app-wide default set in main.ts.
 *
 * IT IS FullLayout MINUS TWO THINGS, and both absences are the point.
 *
 * NO BREADCRUMB. A trail says where you are in the app, and a share link is not IN the app:
 * there is no listing this page came from and nowhere above it a guest may go. FullLayout's
 * trail would render as a lone home chip pointing at a page that offers a login form.
 *
 * NO PERSISTENCE — the whole reason this is a separate layout rather than a prop on the
 * other one. FullLayout hydrates the stored queue on mount; this deliberately does not, and
 * puts the queue in its ephemeral mode instead (usePlayerQueue → beginEphemeralQueue), so a
 * share's tracks are never written to storage or synced to the server. That is not about the
 * guest, who has no user id and syncs nothing anyway: it is about the OWNER opening a link
 * they minted, whose real queue is keyed to them and would otherwise be overwritten with
 * `/s/…` URLs that stop working in seven days.
 *
 * WHAT IT KEEPS, and why keeping it is right: the whole player. The PlayerBar, the queue
 * panel and the audio element are the same components the app uses, fed by the same
 * composable — a share's tracks are ordinary queue entries whose URLs happen to point into
 * the share's own space (App\Services\Shares\ShareGrant). A second player for guests would
 * be a second set of bugs.
 *
 * The HEADER is kept too, unchanged, because it trims ITSELF: SiteMenu renders nothing
 * without a signed-in user, so a guest gets the wordmark, the language and theme switches
 * they may well need, and a login link they may well want — while the owner, arriving at
 * their own link, keeps their way back into the app.
 *****************************************************************************/
import { onBeforeUnmount } from "vue";
import AppFooter from "Components/Landmarks/Footer/AppFooter.vue";
import AppHeader from "Components/Landmarks/Header/AppHeader.vue";
import AppMain from "Components/Landmarks/Main/AppMain.vue";
import PlayerBar from "Components/Player/PlayerBar.vue";
import PlayQueue from "Components/PlayQueue/PlayQueue.vue";
import ToastContainer from "Components/UI/ToastContainer.vue";
import TooltipLayer from "Components/UI/Tooltip/TooltipLayer.vue";
import { beginEphemeralQueue, endEphemeralQueue, usePlayerQueue } from "Composables/usePlayerQueue";

const { current } = usePlayerQueue();

// In setup rather than onMounted, for the same reason FullLayout hydrates here: the flag has
// to be up before anything on the page can queue a track, and a page's own setup runs after
// its layout's. Navigating BETWEEN two share links keeps this component mounted, so the mode
// is entered once per stay in the space rather than once per link.
beginEphemeralQueue();

// Leaving the share space: persist again, and re-arm the restore so the reader's own queue
// comes back rather than the share's tracks lingering in memory. See endEphemeralQueue for
// why it re-arms instead of clearing — a layout swap may mount the incoming layout first.
onBeforeUnmount(endEphemeralQueue);
</script>

<template>
    <app-header />
    <div class="app-body">
        <app-main>
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
/* The same plain block FullLayout's body is, and for the same reason: <main> spans the
   window so the page's full-bleed heading can run its open side off-screen. The queue floats
   above the page and reserves nothing, so there is no inset to publish here either. */
.app-body {
    flex: 1 1 auto;
}
</style>
