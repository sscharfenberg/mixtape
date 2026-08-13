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
 * SEARCH SITS BETWEEN THE TWO MENUS (2026-08-13, the owner's placement), and hides
 * itself the same way the queue toggle does — off the overlay having registered itself,
 * so it is absent in the guest share space and for guests, who have nothing behind
 * `auth` to search. Between them rather than at either end because that is where the
 * row changes subject: SiteMenu is the library ("where can I go"), search is the
 * library too ("where is this one thing"), and UserMenu and the queue toggle are both
 * about this reader and this session. It also keeps the two round glyphs — search and
 * the queue — from bracketing the row and reading as a pair.
 *****************************************************************************/
import SiteMenu from "Components/Landmarks/Header/SiteMenu/SiteMenu.vue";
import UserMenu from "Components/Landmarks/Header/UserMenu/UserMenu.vue";
import PlayQueueToggle from "Components/PlayQueue/PlayQueueToggle.vue";
import SearchToggle from "Components/Search/SearchToggle.vue";
</script>

<template>
    <div class="header-navigation">
        <site-menu />
        <search-toggle />
        <user-menu />
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
}
</style>
