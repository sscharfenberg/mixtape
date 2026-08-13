<script setup lang="ts">
/******************************************************************************
 * HeaderNavigation
 * groups the header's two navigations — SiteMenu (top-level areas) and UserMenu
 * (account + preferences) — plus the play queue's toggle, and pushes the group to
 * the trailing edge of the header row. The "push right" used to live on UserMenu
 * itself; it belongs here now that they travel together.
 *
 * The toggle is last on purpose and hides itself twice over: it renders nothing
 * with an empty queue, and nothing where the layout mounts no panel for it to open
 * (the guest share space, which puts the queue on the page instead). It has no
 * width rule any more — the second clause here used to say "nothing from `landscape`
 * up, where the queue is a permanent column", and that arrangement ended in
 * 2026-08-08; the panel is now opened the same way at every width.
 *
 * SEARCH SITS SECOND TO LAST, immediately before the queue toggle (2026-08-13, the
 * owner's placement), and hides itself the same way the queue toggle does — off the
 * overlay having registered itself, so it is absent in the guest share space and for
 * guests, who have nothing behind `auth` to search. That puts the two round glyphs the
 * reader OPENS side by side at the trailing edge, after the two menus.
 *
 * EVERY CONTROL IN THIS ROW IS THE SAME HEIGHT, and this component is what enforces it —
 * see the rule in the styles below and `s.$c-header` → `control-height` for the number
 * and where it comes from.
 *****************************************************************************/
import SiteMenu from "Components/Landmarks/Header/SiteMenu/SiteMenu.vue";
import UserMenu from "Components/Landmarks/Header/UserMenu/UserMenu.vue";
import PlayQueueToggle from "Components/PlayQueue/PlayQueueToggle.vue";
import SearchToggle from "Components/Search/SearchToggle.vue";
</script>

<template>
    <div class="header-navigation">
        <site-menu />
        <user-menu />
        <search-toggle />
        <play-queue-toggle />
    </div>
</template>

<style scoped lang="scss">
@use "sass:map"; // https://sass-lang.com/documentation/modules/map
@use "Abstracts/mixins" as m;
@use "Abstracts/sizes" as s;

.header-navigation {
    display: flex;
    align-items: center;

    // push the menu group to the trailing edge of the header's flex row.
    margin-inline-start: auto;
    gap: 0.5rem;

    /* THE ROW'S ONE HEIGHT, given to the round buttons as an INVISIBLE BORDER.
       `s.$c-header` → `control-height` carries the number and why 36; this is the half that
       needs explaining here.

       A border rather than a `min-height`, because these are circles: `--rounded` is
       `border-radius: 100vw` over a 32×32 content box, and a height floor alone would have made
       every one of them a 32×36 ellipse. A border grows BOTH axes by the same 4px, so they stay
       round — and it is the same 2px the user menu already carries as `--highlighted`, which is
       what makes all four buttons geometrically identical rather than merely equally tall.

       `transparent`, NOT the background colour spelled out again, and that is the better half of
       the trick: a background paints to the BORDER box by default, so a transparent border shows
       whatever fill the element has — the base variant's gradient (which no `border-color` could
       match), the subtle grey, the hover fill, and every future variant, with the transitions
       following for free because it is literally the same paint.

       `:not(--highlighted)` because that variant has a real, visible border of its own at this
       exact width: without the exclusion this rule's higher specificity would paint it away.

       Scoped to the header on purpose. `.popover-button` is also the DataTable's row-action
       button, the player bar's two triggers and three menus elsewhere — growing all of them 4px
       would add 4px to every table row in the app, which is not what "make the header cleaner"
       asked for. */
    :deep(.popover-button:not(.popover-button--highlighted)) {
        border: map.get(s.$c-popover, "border") solid transparent;
    }
}
</style>
