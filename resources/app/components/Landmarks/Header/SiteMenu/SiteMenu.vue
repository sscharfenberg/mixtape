<script setup lang="ts">
/******************************************************************************
 * SiteMenu
 * the top-level site navigation in the app header. It offers the same areas
 * (see useSiteAreas) in two presentations that swap by viewport width:
 * SiteMenuLinks (an inline row, desktop and up) and SiteMenuPopover (an icon-
 * only popover, below desktop). Only one is ever shown, and each carries its
 * own labelled <nav> landmark, so this wrapper stays semantically neutral.
 *****************************************************************************/
import SiteMenuLinks from "Components/Landmarks/Header/SiteMenu/SiteMenuLinks.vue";
import SiteMenuPopover from "Components/Landmarks/Header/SiteMenu/SiteMenuPopover.vue";
</script>

<template>
    <div class="site-menu">
        <site-menu-links />
        <site-menu-popover />
    </div>
</template>
