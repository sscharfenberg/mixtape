<script setup lang="ts">
/******************************************************************************
 * HeaderNavigation
 * groups the header's two navigations — SiteMenu (top-level areas) and UserMenu
 * (account + preferences) — plus the play queue's toggle, and pushes the group to
 * the trailing edge of the header row. The "push right" used to live on UserMenu
 * itself; it belongs here now that they travel together.
 *
 * The toggle is last on purpose and hides itself twice over: it renders nothing
 * with an empty queue, and nothing at all from `landscape` up, where the queue is
 * a permanent column with nothing to toggle. So on a desktop this row is exactly
 * what it was.
 *****************************************************************************/
import SiteMenu from "Components/Landmarks/Header/SiteMenu/SiteMenu.vue";
import UserMenu from "Components/Landmarks/Header/UserMenu/UserMenu.vue";
import PlayQueueToggle from "Components/PlayQueue/PlayQueueToggle.vue";
</script>

<template>
    <div class="header-navigation">
        <site-menu />
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
