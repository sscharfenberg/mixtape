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
@use "Abstracts/colors" as c;
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

       `transparent`, so that a flat fill — the subtle grey, the hover navy — simply shows through:
       a background paints to the BORDER box, so the ring is literally the same paint, hover
       transitions included, with no colour to keep in step. That holds for a *colour*. It does
       NOT hold for the gradient, which is the second rule below.

       `:not(--highlighted)` because that variant has a real, visible border of its own at this
       exact width: without the exclusion this rule's higher specificity would paint it away.

       Scoped to the header on purpose. `.popover-button` is also the DataTable's row-action
       button, the player bar's two triggers and three menus elsewhere — growing all of them 4px
       would add 4px to every table row in the app, which is not what "make the header cleaner"
       asked for. */
    :deep(.popover-button:not(.popover-button--highlighted)) {
        border: map.get(s.$c-popover, "border") solid transparent;
    }

    /* A GRADIENT DOES NOT SHOW THROUGH A TRANSPARENT BORDER — IT TILES INTO IT (2026-08-14).
       `background-origin` is `padding-box`, `background-clip` is `border-box`: the gradient is
       sized to the 32×32 padding box and then REPEATED to cover the 36×36 border box. So the ring
       above and left of the button paints the tile's bottom-right end (bright c2) and the ring
       below and right paints its top-left end (deep navy c1) — a seam all the way round, reading
       as a hard dark corner where the top-left of the fill meets a bright edge. Reported on the
       logged-out user menu, which for a guest is the only button in this row.

       An OPAQUE border covers the seam outright, and the colour to make it is the one already on
       the button: `button-surface`, the white of the glyph inside it. That also makes the two
       gradient triggers ring the way `--highlighted` does — a border in its own ink — rather than
       being the odd ones out with none.

       `--subtle` is excluded because it wants the paragraph above: a flat fill has no tile and no
       seam, and a white ring on the grey would be a second focal point in a row that gives the
       queue and search glyphs the quiet variant precisely to avoid one. */
    :deep(.popover-button:not(.popover-button--highlighted, .popover-button--subtle)) {
        border-color: map.get(c.$c-popover, "button-surface");
    }
}
</style>
