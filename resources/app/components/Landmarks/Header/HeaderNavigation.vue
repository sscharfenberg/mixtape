<script setup lang="ts">
/******************************************************************************
 * HeaderNavigation
 * groups the header's two navigations — SiteMenu (top-level areas) and UserMenu
 * (account + preferences) — and pushes the pair to the trailing edge of the
 * header row. The "push right" used to live on UserMenu itself; it belongs here
 * now that the two menus travel together.
 *****************************************************************************/
import SiteMenu from "Components/Landmarks/Header/SiteMenu/SiteMenu.vue";
import UserMenu from "Components/Landmarks/Header/UserMenu/UserMenu.vue";
</script>

<template>
    <div class="header-navigation">
        <site-menu />
        <user-menu />
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
